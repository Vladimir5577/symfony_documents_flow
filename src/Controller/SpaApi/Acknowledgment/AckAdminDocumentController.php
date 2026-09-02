<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Acknowledgment;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Acknowledgment\AckDocument;
use App\Entity\Acknowledgment\AckDocumentFile;
use App\Entity\Acknowledgment\AckDocumentUser;
use App\Entity\User\User;
use App\Enum\Acknowledgment\AckAudience;
use App\Repository\Acknowledgment\AckCategoryRepository;
use App\Repository\Acknowledgment\AckDocumentRepository;
use App\Repository\Acknowledgment\AckDocumentUserRepository;
use App\Repository\User\UserRepository;
use App\Service\Acknowledgment\AckFileStorageService;
use App\Service\Acknowledgment\AckPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Раздел «Документы к ознакомлению» со стороны делопроизводства.
 *
 * Черновик правится целиком, опубликованный документ — только в тексте: файл
 * после публикации не меняется. Версий у документа нет, поэтому подмена файла
 * означала бы, что люди «ознакомлены» не с тем, что лежит по ссылке.
 */
#[Route('/spa/api/acknowledgment/admin/documents')]
#[IsGranted('ROLE_DOC_OFFICE')]
final class AckAdminDocumentController extends AbstractController
{
    private const REGISTRY_PAGE_SIZE = 20;
    private const REPORT_PAGE_SIZE = 50;

    public function __construct(
        private readonly AckDocumentRepository $documentRepo,
        private readonly AckDocumentUserRepository $ackRepo,
        private readonly AckCategoryRepository $categoryRepo,
        private readonly UserRepository $userRepo,
        private readonly AckPresenter $presenter,
        private readonly AckFileStorageService $storage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_ack_admin_documents_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = max(1, $request->query->getInt('page', 1));
        $includeArchived = $request->query->getBoolean('includeArchived');

        $category = null;
        $categoryId = $request->query->getInt('categoryId');
        if ($categoryId > 0) {
            $category = $this->categoryRepo->find($categoryId);
            if ($category === null) {
                return $this->json(['error' => SpaApiError::ACK_CATEGORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }
        }

        $documents = $this->documentRepo->findForRegistry($category, $includeArchived, $page, self::REGISTRY_PAGE_SIZE);
        $counts = $this->ackRepo->statusCountsFor(
            array_map(static fn (AckDocument $document): int => (int) $document->getId(), $documents)
        );

        return $this->json([
            'items' => array_map(
                fn (AckDocument $document): array => $this->presenter->presentRegistryRow(
                    $document,
                    $counts[$document->getId()] ?? ['acknowledged' => 0, 'disagreed' => 0, 'later' => 0, 'rows' => 0],
                    $this->ackRepo->countAudience($document),
                ),
                $documents,
            ),
            'total' => $this->documentRepo->countForRegistry($category, $includeArchived),
            'page' => $page,
            'limit' => self::REGISTRY_PAGE_SIZE,
        ]);
    }

    #[Route('', name: 'spa_api_ack_admin_documents_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        // Форму списка проверяем до вставки: назначения требуют id документа,
        // а падение после flush оставило бы в реестре черновик-обрубок.
        if (array_key_exists('userIds', $payload) && !is_array($payload['userIds'])) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NO_RECIPIENTS], Response::HTTP_BAD_REQUEST);
        }

        $document = (new AckDocument())->setAuthor($user);

        $error = $this->applyPayload($document, $payload);
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $this->em->persist($document);
        $this->em->flush();

        $error = $this->syncRecipients($document, $payload);
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }
        $this->em->flush();

        return $this->json($this->presentAdmin($document), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_ack_admin_documents_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $document = $this->documentRepo->find($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        // Состав аудитории после публикации заморожен: люди уже отмечаются, и
        // тихое расширение круга сделало бы охват в отчёте несопоставимым.
        if ($document->isPublished() && (isset($payload['audience']) || isset($payload['userIds']))) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_ALREADY_PUBLISHED], Response::HTTP_CONFLICT);
        }

        $error = $this->applyPayload($document, $payload);
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $error = $this->syncRecipients($document, $payload);
        if ($error !== null) {
            return $this->json(['error' => $error], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();

        return $this->json($this->presentAdmin($document));
    }

    #[Route('/{id}/publish', name: 'spa_api_ack_admin_documents_publish', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publish(int $id): JsonResponse
    {
        $document = $this->documentRepo->find($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($document->isPublished()) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_ALREADY_PUBLISHED], Response::HTTP_CONFLICT);
        }

        // Ознакомление без документа — это просто кнопка. Пустую публикацию
        // проще запретить здесь, чем объяснять сотрудникам, что читать нечего.
        if ($document->getFiles()->isEmpty()) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NO_FILES], Response::HTTP_CONFLICT);
        }

        if ($document->getAudience() === AckAudience::SELECTED
            && $this->ackRepo->countAudience($document) === 0
        ) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NO_RECIPIENTS], Response::HTTP_CONFLICT);
        }

        $document->setPublishedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json($this->presentAdmin($document));
    }

    #[Route('/{id}/archive', name: 'spa_api_ack_admin_documents_archive', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function archive(int $id): JsonResponse
    {
        $document = $this->documentRepo->find($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$document->isActive()) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_ACTIVE], Response::HTTP_CONFLICT);
        }

        $document->setArchivedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json($this->presentAdmin($document));
    }

    /**
     * Удалять можно только черновик: опубликованный документ уходит в архив.
     * Иначе из отчётов пропадали бы уже собранные ознакомления.
     */
    #[Route('/{id}', name: 'spa_api_ack_admin_documents_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $document = $this->documentRepo->find($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($document->isPublished()) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_ALREADY_PUBLISHED], Response::HTTP_CONFLICT);
        }

        $this->em->remove($document);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/files', name: 'spa_api_ack_admin_documents_file_upload', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function uploadFile(int $id, Request $request): JsonResponse
    {
        $document = $this->documentRepo->find($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($document->isPublished()) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_ALREADY_PUBLISHED], Response::HTTP_CONFLICT);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }

        if ($file->getSize() > AckFileStorageService::MAX_FILE_SIZE) {
            return $this->json(['error' => SpaApiError::ACK_FILE_TOO_LARGE], Response::HTTP_BAD_REQUEST);
        }

        if (!$this->storage->isAllowed($file)) {
            return $this->json(['error' => SpaApiError::ACK_FILE_TYPE_NOT_ALLOWED], Response::HTTP_BAD_REQUEST);
        }

        $entity = (new AckDocumentFile())
            ->setStorageKey($this->storage->upload($document, $file))
            ->setOriginalName($file->getClientOriginalName());

        $document->addFile($entity);
        $this->em->persist($entity);
        $this->em->flush();

        return $this->json($this->presenter->presentFile($entity), Response::HTTP_CREATED);
    }

    #[Route(
        '/{id}/files/{fileId}',
        name: 'spa_api_ack_admin_documents_file_delete',
        requirements: ['id' => '\d+', 'fileId' => '\d+'],
        methods: ['DELETE'],
    )]
    public function deleteFile(int $id, int $fileId): JsonResponse
    {
        $document = $this->documentRepo->find($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($document->isPublished()) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_ALREADY_PUBLISHED], Response::HTTP_CONFLICT);
        }

        foreach ($document->getFiles() as $file) {
            if ($file->getId() === $fileId) {
                $this->storage->delete($file->getStorageKey());
                $document->removeFile($file);
                $this->em->flush();

                return $this->json(['success' => true]);
            }
        }

        return $this->json(['error' => SpaApiError::ACK_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
    }

    /**
     * Отчёт по документу: кто ознакомился, кто возразил, кто молчит.
     * ?filter=acknowledged|disagreed|later|pending
     */
    #[Route('/{id}/report', name: 'spa_api_ack_admin_documents_report', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function report(int $id, Request $request): JsonResponse
    {
        $document = $this->documentRepo->find($id);
        if ($document === null) {
            return $this->json(['error' => SpaApiError::ACK_DOCUMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $filter = $request->query->get('filter');
        $filter = is_string($filter) && $filter !== '' ? $filter : null;

        $counts = $this->ackRepo->statusCountsFor([(int) $document->getId()])[$document->getId()]
            ?? ['acknowledged' => 0, 'disagreed' => 0, 'later' => 0, 'rows' => 0];

        return $this->json([
            'document' => $this->presenter->presentRegistryRow(
                $document,
                $counts,
                $this->ackRepo->countAudience($document),
            ),
            'items' => array_map(
                fn (array $row): array => $this->presenter->presentReportRow($row),
                $this->ackRepo->findReportRows($document, $filter, $page, self::REPORT_PAGE_SIZE),
            ),
            'total' => $this->ackRepo->countReportRows($document, $filter),
            'page' => $page,
            'limit' => self::REPORT_PAGE_SIZE,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return string|null код ошибки из SpaApiError, если данные не годятся
     */
    private function applyPayload(AckDocument $document, array $payload): ?string
    {
        if (array_key_exists('title', $payload)) {
            $title = is_string($payload['title']) ? trim($payload['title']) : '';
            if ($title === '') {
                return SpaApiError::ACK_DOCUMENT_TITLE_REQUIRED;
            }
            $document->setTitle(mb_substr($title, 0, 255));
        }

        if (array_key_exists('description', $payload)) {
            $description = is_string($payload['description']) ? trim($payload['description']) : '';
            $document->setDescription($description !== '' ? $description : null);
        }

        if (array_key_exists('categoryId', $payload)) {
            $category = $this->categoryRepo->find((int) $payload['categoryId']);
            if ($category === null) {
                return SpaApiError::ACK_CATEGORY_NOT_FOUND;
            }
            $document->setCategory($category);
        }

        if (array_key_exists('audience', $payload)) {
            $audience = is_string($payload['audience']) ? AckAudience::tryFrom($payload['audience']) : null;
            if ($audience === null) {
                return SpaApiError::ACK_DOCUMENT_AUDIENCE_INVALID;
            }
            $document->setAudience($audience);
        }

        if (array_key_exists('deadline', $payload)) {
            $deadline = $payload['deadline'];
            if ($deadline === null || $deadline === '') {
                $document->setDeadline(null);
            } else {
                $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $deadline);
                if ($parsed === false) {
                    return SpaApiError::ACK_DOCUMENT_DEADLINE_INVALID;
                }
                $document->setDeadline($parsed);
            }
        }

        if ($document->getCategory() === null) {
            return SpaApiError::ACK_CATEGORY_NOT_FOUND;
        }

        if ($document->getTitle() === '') {
            return SpaApiError::ACK_DOCUMENT_TITLE_REQUIRED;
        }

        return null;
    }

    /**
     * Приводит список назначенных к присланному. Работает только для черновика:
     * у опубликованного документа состав заморожен выше по коду.
     *
     * @param array<string, mixed> $payload
     */
    private function syncRecipients(AckDocument $document, array $payload): ?string
    {
        if (!array_key_exists('userIds', $payload)) {
            return null;
        }

        // Аудитория «все» назначений не хранит вовсе: список живой и считается
        // от текущего состава в момент запроса.
        if ($document->getAudience() === AckAudience::ALL) {
            foreach ($this->ackRepo->findBy(['document' => $document]) as $row) {
                $this->em->remove($row);
            }

            return null;
        }

        if (!is_array($payload['userIds'])) {
            return SpaApiError::ACK_DOCUMENT_NO_RECIPIENTS;
        }

        $users = $this->userRepo->findByIds($payload['userIds']);
        $wanted = [];
        foreach ($users as $user) {
            $wanted[(int) $user->getId()] = $user;
        }

        foreach ($this->ackRepo->findBy(['document' => $document]) as $row) {
            $userId = (int) $row->getUser()?->getId();
            if (isset($wanted[$userId])) {
                unset($wanted[$userId]);
                continue;
            }

            // Отметившегося из списка не выкидываем: его ознакомление уже факт.
            if ($row->getStatus() === null) {
                $this->em->remove($row);
            }
        }

        foreach ($wanted as $user) {
            $this->em->persist(
                (new AckDocumentUser())->setDocument($document)->setUser($user)
            );
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAdmin(AckDocument $document): array
    {
        $counts = $this->ackRepo->statusCountsFor([(int) $document->getId()])[$document->getId()]
            ?? ['acknowledged' => 0, 'disagreed' => 0, 'later' => 0, 'rows' => 0];

        $row = $this->presenter->presentRegistryRow($document, $counts, $this->ackRepo->countAudience($document));
        $row['description'] = $document->getDescription();
        $row['files'] = array_map(
            fn (AckDocumentFile $file): array => $this->presenter->presentFile($file),
            $document->getFiles()->toArray(),
        );

        return $row;
    }
}
