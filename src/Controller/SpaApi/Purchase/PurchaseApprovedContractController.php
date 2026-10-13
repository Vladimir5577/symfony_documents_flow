<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseContract;
use App\Entity\Purchase\PurchaseRequestFile;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use App\Enum\Purchase\PurchaseCapability;
use App\Enum\Purchase\PurchaseContractStatus;
use App\Enum\Purchase\PurchaseFileType;
use App\Repository\Purchase\PurchaseRequestFileRepository;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseContractCatalog;
use App\Service\Purchase\PurchaseFileCoEditing;
use App\Service\Purchase\PurchaseFileStorageService;
use Aws\S3\Exception\S3Exception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Договоры с утверждёнными рецензиями. Список для отдела закупок. */
#[Route('/spa/api/purchases/approved-contracts')]
final class PurchaseApprovedContractController extends AbstractController
{
    public function __construct(
        private readonly PurchaseRequestFileRepository $fileRepo,
        private readonly PurchaseAccess $access,
        private readonly PurchaseContractCatalog $catalog,
        private readonly PurchaseFileStorageService $storage,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_purchases_approved_contracts', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->access->can($user, PurchaseCapability::MANAGE_DICTIONARIES)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = min(100, max(1, $request->query->getInt('page_size', 20)));
        $search = trim((string) $request->query->get('search', ''));
        $excludePurchaseId = $request->query->getInt('exclude_purchase');
        $result = $this->fileRepo->findForList(
            $search,
            $request->query->getBoolean('confirmed'),
            $page,
            $pageSize,
            $excludePurchaseId > 0 ? $excludePurchaseId : null,
        );

        return $this->json([
            'items' => $result['items'],
            'pagination' => [
                'current_page' => $page,
                'items_per_page' => $pageSize,
                'total_items' => $result['total'],
                'total_pages' => (int) ceil($result['total'] / $pageSize),
            ],
        ]);
    }

    #[Route('', name: 'spa_api_purchases_approved_contracts_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->access->can($user, PurchaseCapability::MANAGE_DICTIONARIES)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $name = trim((string) $request->request->get('name', ''));
        $contractFiles = $this->uploads($request, 'contract');
        $signed = $this->uploads($request, 'signed');
        $other = $this->uploads($request, 'other');
        if ($name === '' || mb_strlen($name) > 255 || count($contractFiles) > 1 || $signed === []) {
            return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_BAD_REQUEST);
        }

        $contract = $this->catalog->createDetached($name, $user, $contractFiles[0] ?? null, $signed, $other);

        return $this->json(['id' => $contract->getId(), 'name' => $contract->getName()], Response::HTTP_CREATED);
    }

    #[Route('/{id}/requests', name: 'spa_api_purchases_approved_contracts_requests', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function requests(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->access->can($user, PurchaseCapability::MANAGE_DICTIONARIES)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        if (!$this->em->find(PurchaseContract::class, $id) instanceof PurchaseContract) {
            return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->fileRepo->findRequests($id));
    }

    #[Route('/{id}/files', name: 'spa_api_purchases_approved_contracts_files', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function files(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->access->can($user, PurchaseCapability::MANAGE_DICTIONARIES)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        if (!$this->em->find(PurchaseContract::class, $id) instanceof PurchaseContract) {
            return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->fileRepo->findFiles($id));
    }

    #[Route('/{id}/files/{fileId}/download', name: 'spa_api_purchases_approved_contracts_file_download', requirements: ['id' => '\d+', 'fileId' => '\d+'], methods: ['GET'])]
    public function download(int $id, int $fileId, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $contract = $this->em->find(PurchaseContract::class, $id);
        $file = $this->em->find(PurchaseRequestFile::class, $fileId);
        if (!$contract instanceof PurchaseContract || !$file instanceof PurchaseRequestFile || $file->getContract()?->getId() !== $id) {
            return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canRead($contract, $user)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        try {
            $object = $this->storage->getObject($file->getStorageKey());
        } catch (S3Exception) {
            return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_NOT_FOUND);
        }

        $name = $file->getOriginalName() ?? 'file';
        $disposition = $request->query->getBoolean('inline')
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        if ($file->getType() === PurchaseFileType::CONTRACT) {
            $body = PurchaseFileCoEditing::acceptedCopy((string) $object['Body']);
            $response = new Response($body);
            $response->headers->set('Content-Length', (string) strlen($body));
        } else {
            $stream = $object['Body'];
            $response = new StreamedResponse(static function () use ($stream): void {
                while (!$stream->eof()) {
                    echo $stream->read(8192);
                }
            });
            if ($object['ContentLength'] !== null) {
                $response->headers->set('Content-Length', (string) $object['ContentLength']);
            }
        }

        $response->headers->set('Content-Type', (string) ($object['ContentType'] ?? 'application/octet-stream'));
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            $disposition,
            $name,
            preg_replace('/[^\x20-\x7e]|%/', '_', $name) ?? 'file',
        ));
        $response->headers->set('Cache-Control', 'max-age=0, private');

        return $response;
    }

    #[Route('/{id}', name: 'spa_api_purchases_approved_contracts_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isGranted(UserRole::ROLE_ADMIN->value)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $contract = $this->em->find(PurchaseContract::class, $id);
        if (!$contract instanceof PurchaseContract) {
            return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_NOT_FOUND);
        }

        $this->catalog->remove($contract);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}', name: 'spa_api_purchases_approved_contracts_status', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function updateStatus(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->access->can($user, PurchaseCapability::MANAGE_DICTIONARIES)) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $contract = $this->em->find(PurchaseContract::class, $id);
        if (!$contract instanceof PurchaseContract) {
            return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_BAD_REQUEST);
        }

        $name = null;
        if (array_key_exists('name', $payload)) {
            $name = trim((string) $payload['name']);
            if ($name === '' || mb_strlen($name) > 255) {
                return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_BAD_REQUEST);
            }
            $contract->setName($name);
        }

        $status = null;
        if (array_key_exists('status', $payload)) {
            $status = PurchaseContractStatus::tryFrom((string) $payload['status']);
            if ($status === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_BAD_REQUEST);
            }
            $contract->setStatus($status);
        }

        if ($name === null && $status === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();

        if ($status !== null && $name === null) {
            return $this->json([
                'value' => $status->value,
                'label' => $status->getLabel(),
            ]);
        }

        return $this->json(['name' => $contract->getName()]);
    }

    /** @return list<UploadedFile> */
    private function uploads(Request $request, string $field): array
    {
        $value = $request->files->get($field);
        $files = $value instanceof UploadedFile ? [$value] : (is_array($value) ? $value : []);
        $uploads = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $uploads[] = $file;
            }
        }

        return $uploads;
    }

    private function canRead(PurchaseContract $contract, User $user): bool
    {
        if ($this->access->can($user, PurchaseCapability::MANAGE_DICTIONARIES)) {
            return true;
        }

        foreach ($contract->getLinks() as $link) {
            $linked = $link->getPurchaseRequest();
            if ($linked !== null && $this->access->canView($linked, $user)) {
                return true;
            }
        }

        return false;
    }
}
