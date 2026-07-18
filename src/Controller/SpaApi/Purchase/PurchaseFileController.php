<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\User\User;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Security\Voter\PurchaseRequestVoter;
use App\Service\Purchase\PurchaseApiPresenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/purchases/{id}/files', requirements: ['id' => '\d+'])]
final class PurchaseFileController extends AbstractController
{
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly PurchaseApiPresenter $presenter,
        private readonly EntityManagerInterface $em,
        #[Autowire('%private_upload_dir_purchases%')]
        private readonly string $uploadDir,
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

        // Файлы может добавлять любой участник процесса (те же права, что на комментарий)
        if (!$this->isGranted(PurchaseRequestVoter::COMMENT, $purchase)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $uploaded = $request->files->get('file');
        if ($uploaded === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }

        if ($uploaded->getSize() > self::MAX_FILE_SIZE) {
            return $this->json(['error' => SpaApiError::POST_FILE_TOO_LARGE], Response::HTTP_BAD_REQUEST);
        }

        $fileEntity = new PurchaseRequestFile();
        $fileEntity->setUploadedBy($user);
        $fileEntity->setOriginalName($uploaded->getClientOriginalName());
        $fileEntity->setFile($uploaded);
        $purchase->addFile($fileEntity);

        $this->em->persist($fileEntity);
        $this->em->flush();

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

        if (!$this->isGranted(PurchaseRequestVoter::VIEW, $purchase)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $fileEntity = $this->findFile($purchase->getFiles()->toArray(), $fileId);
        if ($fileEntity === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_FILE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // PropertyDirectoryNamer кладёт файл в подкаталог с id заявки
        $absolutePath = sprintf('%s/%d/%s', rtrim($this->uploadDir, '/'), $id, $fileEntity->getFileName());
        if (!is_file($absolutePath)) {
            return $this->json(['error' => SpaApiError::FILE_NOT_FOUND_ON_DISK], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($absolutePath);
        $disposition = $request->query->getBoolean('inline')
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->setContentDisposition($disposition, $fileEntity->getOriginalName() ?? 'file');

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

        // Удалять может загрузивший или тот, кто может редактировать заявку
        $canDelete = $fileEntity->getUploadedBy()?->getId() === $user->getId()
            || $this->isGranted(PurchaseRequestVoter::EDIT, $purchase);
        if (!$canDelete) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $purchase->removeFile($fileEntity);
        $this->em->remove($fileEntity);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
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
