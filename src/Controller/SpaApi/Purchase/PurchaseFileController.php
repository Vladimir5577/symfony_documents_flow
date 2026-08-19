<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchaseStatus;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseApiPresenter;
use App\Service\Purchase\PurchaseFileStorageService;
use App\Service\Purchase\PurchaseRequestService;
use Aws\S3\Exception\S3Exception;
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

#[Route('/spa/api/purchases/{id}/files', requirements: ['id' => '\d+'])]
final class PurchaseFileController extends AbstractController
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly PurchaseApiPresenter $presenter,
        private readonly PurchaseFileStorageService $storage,
        private readonly EntityManagerInterface $em,
        private readonly PurchaseAccess $access,
        private readonly PurchaseRequestService $purchaseService,
    ) {
    }

    #[Route('', name: 'spa_api_purchases_files_upload', methods: ['POST'])]
    public function upload(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Файлы может добавлять любой участник процесса (кто видит заявку)
        if (!$this->canView($purchase, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $uploaded = $request->files->get('file');
        if ($uploaded === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }

        if ($uploaded->getSize() > self::MAX_FILE_SIZE) {
            return $this->json(['error' => SpaApiError::POST_FILE_TOO_LARGE], Response::HTTP_BAD_REQUEST);
        }

        // Тип вложения: ТЗ / пояснительная записка / прочее (по умолчанию)
        $typeRaw = trim((string) $request->request->get('type', ''));
        $type = PurchaseFileType::OTHER;
        if ($typeRaw !== '') {
            $type = PurchaseFileType::tryFrom($typeRaw);
            if ($type === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_INVALID_FILE_TYPE], Response::HTTP_BAD_REQUEST);
            }
        }

        $fileEntity = new PurchaseRequestFile();
        $fileEntity->setType($type);
        $fileEntity->setUploadedBy($user);
        // Имя приходит от пользователя, а колонка 255 — длинное обрезаем, иначе вставка упадёт.
        $fileEntity->setOriginalName(mb_substr($uploaded->getClientOriginalName(), 0, 255));
        $fileEntity->setStorageKey($this->storage->upload($purchase, $uploaded));
        $purchase->addFile($fileEntity);

        $this->em->persist($fileEntity);
        $this->purchaseService->log(
            $purchase,
            $user,
            PurchaseHistoryAction::FILE_UPLOADED,
            sprintf('%s: %s', $type->getLabel(), (string) $fileEntity->getOriginalName()),
        );

        return $this->json($this->presenter->presentFile($fileEntity), Response::HTTP_CREATED);
    }

    #[Route('/{fileId}/download', name: 'spa_api_purchases_files_download', requirements: ['fileId' => '\d+'], methods: ['GET'])]
    public function download(int $id, int $fileId, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canView($purchase, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $fileEntity = $this->findFile($purchase->getFiles()->toArray(), $fileId);
        if ($fileEntity === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $disposition = $request->query->getBoolean('inline')
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $name = $fileEntity->getOriginalName() ?? 'file';

        try {
            $object = $this->storage->getObject($fileEntity->getStorageKey());
        } catch (S3Exception) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $stream = $object['Body'];
        $response = new StreamedResponse(static function () use ($stream): void {
            while (!$stream->eof()) {
                echo $stream->read(8192);
            }
        });

        $response->headers->set('Content-Type', (string) ($object['ContentType'] ?? 'application/octet-stream'));
        // Длину MinIO отдаёт всегда, но пустой заголовок сломал бы скачивание молча.
        if ($object['ContentLength'] !== null) {
            $response->headers->set('Content-Length', (string) $object['ContentLength']);
        }
        // Имя файла обычно кириллическое, а makeDisposition() требует ASCII-запасной
        // вариант и иначе бросает исключение. BinaryFileResponse делал это за нас.
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $disposition,
            $name,
            preg_replace('/[^\x20-\x7e]|%/', '_', $name) ?? 'file',
        ));
        $response->headers->set('Cache-Control', 'max-age=0, private');

        return $response;
    }

    #[Route('/{fileId}', name: 'spa_api_purchases_files_delete', requirements: ['fileId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, int $fileId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $fileEntity = $this->findFile($purchase->getFiles()->toArray(), $fileId);
        if ($fileEntity === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Обязательное вложение после прохождения его стадии не удалить уже никому:
        // иначе оплаченная заявка осталась бы без договора, а поданная — без записки.
        if ($fileEntity->getType()->isLockedAt($purchase->getStatus())) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_LOCKED], Response::HTTP_FORBIDDEN);
        }

        // Удалять может загрузивший или автор заявки, пока она редактируема
        $canDelete = $fileEntity->getUploadedBy()?->getId() === $user->getId()
            || ($this->isManagerOwner($purchase, $user) && $purchase->getStatus()->isEditable());
        if (!$canDelete) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        // Содержимое сносим до строки: ключ хранится только в ней, и без него
        // объект уже никак не найти — останется висеть в бакете навсегда.
        $this->storage->delete($fileEntity->getStorageKey());

        $description = sprintf(
            '%s: %s',
            $fileEntity->getType()->getLabel(),
            (string) $fileEntity->getOriginalName(),
        );

        $purchase->removeFile($fileEntity);
        $this->em->remove($fileEntity);
        $this->purchaseService->log($purchase, $user, PurchaseHistoryAction::FILE_DELETED, $description);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /** Автор заявки — роль не требуется: создавать может любой пользователь. */
    private function isManagerOwner(PurchaseRequest $purchase, User $user): bool
    {
        return $purchase->getCreatedBy()?->getId() === $user->getId();
    }

    /** Видимость заявки — общая на весь модуль, см. PurchaseAccess. */
    private function canView(PurchaseRequest $purchase, User $user): bool
    {
        return $this->access->canView($purchase, $user);
    }

    /**
     * @param list<PurchaseRequestFile> $files
     */
    private function findFile(array $files, int $fileId): ?PurchaseRequestFile
    {
        foreach ($files as $file) {
            if ($file->getId() === $fileId) {
                return $file;
            }
        }

        return null;
    }
}
