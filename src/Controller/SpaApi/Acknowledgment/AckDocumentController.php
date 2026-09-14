<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Acknowledgment;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Acknowledgment\AckDocument;
use App\Entity\Acknowledgment\AckDocumentFile;
use App\Entity\Acknowledgment\AckDocumentUser;
use App\Entity\User\User;
use App\Enum\Acknowledgment\AckStatus;
use App\Repository\Acknowledgment\AckDocumentRepository;
use App\Repository\Acknowledgment\AckDocumentUserRepository;
use App\Service\Acknowledgment\AckFileStorageService;
use App\Service\Acknowledgment\AckPresenter;
use Aws\S3\Exception\S3Exception;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Раздел «Документы к ознакомлению» глазами сотрудника.
 *
 * Уведомлений у модуля нет: обязанность видна как счётчик неотмеченных, и он
 * держится, пока человек не нажал кнопку, — в отличие от уведомления, которое
 * гаснет от прочтения, а обязанность оставляет.
 */
#[Route('/spa/api/acknowledgment')]
final class AckDocumentController extends AbstractController
{
    private const HISTORY_PAGE_SIZE = 20;

    public function __construct(
        private readonly AckDocumentRepository $documentRepo,
        private readonly AckDocumentUserRepository $ackRepo,
        private readonly AckPresenter $presenter,
        private readonly AckFileStorageService $storage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Лёгкий запрос для бейджа: его дёргают с любой страницы портала. */
    #[Route('/pending-count', name: 'spa_api_ack_pending_count', methods: ['GET'])]
    public function pendingCount(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json(['count' => $this->documentRepo->countPendingFor($user)]);
    }

    /**
     * Мои документы: по умолчанию неотмеченные, по ?status=history — закрытые.
     *
     * Один маршрут с фильтром, а не два: форма ответа одна и та же, а объёмы
     * разные — неотмеченных единицы и они нужны целиком, закрытых за год сотни
     * и им нужна страница.
     */
    #[Route('/documents', name: 'spa_api_ack_documents_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if ($request->query->get('status') === 'history') {
            $page = max(1, $request->query->getInt('page', 1));
            $rows = $this->ackRepo->findHistoryFor($user, $page, self::HISTORY_PAGE_SIZE);

            return $this->json([
                'items' => array_map(
                    fn (AckDocumentUser $ack): array => $this->presenter->presentDocument(
                        $ack->getDocument(),
                        $ack,
                        $user,
                    ),
                    $rows,
                ),
                'total' => $this->ackRepo->countHistoryFor($user),
                'page' => $page,
                'limit' => self::HISTORY_PAGE_SIZE,
            ]);
        }

        $documents = $this->documentRepo->findPendingFor($user);
        $own = $this->ownRows($documents, $user);

        return $this->json([
            'items' => array_map(
                fn (AckDocument $document): array => $this->presenter->presentDocument(
                    $document,
                    $own[$document->getId()] ?? null,
                    $user,
                ),
                $documents,
            ),
            'total' => count($documents),
        ]);
    }

    #[Route('/documents/{id}', name: 'spa_api_ack_documents_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->documentRepo->find($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canView($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return $this->json(
            $this->presenter->presentDocument($document, $this->ackRepo->findOneFor($document, $user), $user)
        );
    }

    /**
     * Отметка сотрудника. Одна ручка на все три статуса: различаются они
     * значением, а не логикой, и три маршрута отличались бы одной строкой.
     */
    #[Route('/documents/{id}/status', name: 'spa_api_ack_documents_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function setStatus(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->documentRepo->find($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$document->isActive()) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_ACTIVE], Response::HTTP_CONFLICT);
        }

        if (!$this->documentRepo->isAddressedTo($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $status = is_string($payload['status'] ?? null) ? AckStatus::tryFrom($payload['status']) : null;
        if ($status === null) {
            return $this->json(['error' => SpaApiError::ACK_STATUS_INVALID], Response::HTTP_BAD_REQUEST);
        }

        $comment = is_string($payload['comment'] ?? null) ? trim($payload['comment']) : '';
        // Несогласие без объяснения нечитаемо: делопроизводству оно не говорит
        // ничего, кроме того, что кто-то недоволен.
        if ($status === AckStatus::DISAGREED && $comment === '') {
            return $this->json(['error' => SpaApiError::ACK_STATUS_COMMENT_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $ack = $this->ackRepo->findOneFor($document, $user);
        // Отметку об ознакомлении назад не отыгрывают: иначе охват документа
        // «дышал» бы и отчёт делопроизводства ничего не значил.
        if ($ack !== null && $ack->isFinal()) {
            return $this->json(['error' => SpaApiError::ACK_STATUS_ALREADY_FINAL], Response::HTTP_CONFLICT);
        }

        if ($ack === null) {
            $ack = (new AckDocumentUser())
                ->setDocument($document)
                ->setUser($user);
            $this->em->persist($ack);
        }

        $ack->setStatus($status)
            ->setComment($comment !== '' ? $comment : null)
            ->setActedAt(new \DateTimeImmutable());

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            // Две вкладки нажали кнопку одновременно: строка уже создана соседним
            // запросом, и его отметка ничем не хуже нашей.
            return $this->json(['error' => SpaApiError::ACK_STATUS_ALREADY_FINAL], Response::HTTP_CONFLICT);
        }

        return $this->json($this->presenter->presentDocument($document, $ack, $user));
    }

    #[Route(
        '/documents/{id}/files/{fileId}/download',
        name: 'spa_api_ack_documents_file_download',
        requirements: ['id' => '\d+', 'fileId' => '\d+'],
        methods: ['GET'],
    )]
    public function download(int $id, int $fileId, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $document = $this->documentRepo->find($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Права проверяем на каждом скачивании, а не один раз при выдаче ссылки:
        // адресный документ не должен утекать пересланным адресом файла.
        if (!$this->canView($document, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $file = null;
        foreach ($document->getFiles() as $candidate) {
            if ($candidate->getId() === $fileId) {
                $file = $candidate;
                break;
            }
        }

        if (!$file instanceof AckDocumentFile) {
            return $this->json(['error' => SpaApiError::ACK_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        try {
            $object = $this->storage->getObject($file->getStorageKey());
        } catch (S3Exception) {
            return $this->json(['error' => SpaApiError::ACK_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $stream = $object['Body'];
        $response = new StreamedResponse(static function () use ($stream): void {
            while (!$stream->eof()) {
                echo $stream->read(8192);
            }
        });

        $response->headers->set('Content-Type', (string) ($object['ContentType'] ?? 'application/octet-stream'));
        if ($object['ContentLength'] !== null) {
            $response->headers->set('Content-Length', (string) $object['ContentLength']);
        }

        $name = $file->getOriginalName();
        // Имена у делопроизводства кириллические, а makeDisposition() требует
        // ASCII-запасной вариант и иначе бросает исключение.
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $request->query->getBoolean('inline')
                ? ResponseHeaderBag::DISPOSITION_INLINE
                : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
            preg_replace('/[^\x20-\x7e]|%/', '_', $name) ?? 'file',
        ));
        $response->headers->set('Cache-Control', 'max-age=0, private');

        return $response;
    }

    /** Черновики и архив сотруднику не показываем, делопроизводству — да. */
    private function canView(AckDocument $document, User $user): bool
    {
        if ($this->isGranted('ROLE_DOC_OFFICE')) {
            return true;
        }

        return $document->isActive() && $this->documentRepo->isAddressedTo($document, $user);
    }

    /**
     * Свои отметки по списку документов одним запросом.
     *
     * @param AckDocument[] $documents
     *
     * @return array<int, AckDocumentUser>
     */
    private function ownRows(array $documents, User $user): array
    {
        if ($documents === []) {
            return [];
        }

        $rows = $this->ackRepo->findBy(['document' => $documents, 'user' => $user]);

        $byDocument = [];
        foreach ($rows as $row) {
            $byDocument[(int) $row->getDocument()?->getId()] = $row;
        }

        return $byDocument;
    }
}
