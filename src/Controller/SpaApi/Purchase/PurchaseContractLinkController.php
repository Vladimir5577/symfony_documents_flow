<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseContract;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseContractStatus;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Repository\Purchase\PurchaseRequestRepository;
use App\Service\Purchase\PurchaseAccess;
use App\Service\Purchase\PurchaseApiPresenter;
use App\Service\Purchase\PurchaseContractCatalog;
use App\Service\Purchase\PurchaseDocumentBinding;
use App\Service\Purchase\PurchaseRequestEditor;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/** Ссылка заявки на уже существующий договор. Файл не копируется. */
#[Route('/spa/api/purchases/{id}/contracts', requirements: ['id' => '\d+'])]
final class PurchaseContractLinkController extends AbstractController
{
    public function __construct(
        private readonly PurchaseRequestRepository $purchaseRepo,
        private readonly PurchaseAccess $access,
        private readonly PurchaseApiPresenter $presenter,
        private readonly PurchaseContractCatalog $catalog,
        private readonly PurchaseDocumentBinding $binding,
        private readonly PurchaseRequestEditor $editor,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'spa_api_purchases_contracts_attach', methods: ['POST'])]
    public function attach(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        $contractId = is_array($payload) ? (int) ($payload['contractId'] ?? 0) : 0;

        // Ссылки читает согласование этапа «договор» под блокировкой заявки.
        // Без той же блокировки здесь договор поменялся бы между проверкой и решением.
        $error = $this->em->wrapInTransaction(function () use ($purchase, $contractId, $user): ?JsonResponse {
            $this->em->refresh($purchase, LockMode::PESSIMISTIC_WRITE);

            if (!$this->canEditContracts($purchase, $user)) {
                return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
            }

            $contract = $this->em->find(PurchaseContract::class, $contractId);
            if (!$contract instanceof PurchaseContract || $contract->getStatus() !== PurchaseContractStatus::ACTIVE) {
                return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_BAD_REQUEST);
            }

            if ($this->catalog->link($purchase, $contract)) {
                $name = $contract->getName();
                $this->binding->refresh($purchase);
                $this->editor->log($purchase, $user, PurchaseHistoryAction::CONTRACT_ATTACHED, $name !== '' ? $name : null);
            }

            return null;
        });

        // Карточку собираем после commit: сборка не должна держать блокировку заявки.
        return $error ?? $this->json($this->presenter->presentDetail($purchase));
    }

    #[Route('/{contractId}', name: 'spa_api_purchases_contracts_detach', requirements: ['contractId' => '\d+'], methods: ['DELETE'])]
    public function detach(int $id, int $contractId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $purchase = $this->purchaseRepo->find($id);
        if ($purchase === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $error = $this->em->wrapInTransaction(function () use ($purchase, $contractId, $user): ?JsonResponse {
            $this->em->refresh($purchase, LockMode::PESSIMISTIC_WRITE);

            if (!$this->canEditContracts($purchase, $user)) {
                return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
            }

            $link = null;
            foreach ($purchase->getContractLinks() as $existing) {
                if ($existing->getContract()->getId() === $contractId) {
                    $link = $existing;
                    break;
                }
            }
            if ($link === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_CONTRACT_UNAVAILABLE], Response::HTTP_NOT_FOUND);
            }

            $contract = $link->getContract();
            $purchase->removeContractLink($link);
            $contract->removeLink($link);
            $this->em->remove($link);
            foreach ($purchase->getItems() as $item) {
                if ($item->getContractFile()?->getContract() === $contract) {
                    $item->setContractFile(null);
                }
            }
            $this->binding->refresh($purchase);
            $this->editor->log($purchase, $user, PurchaseHistoryAction::CONTRACT_DETACHED, null);

            return null;
        });

        // Карточку собираем после commit: сборка не должна держать блокировку заявки.
        return $error ?? $this->json($this->presenter->presentDetail($purchase));
    }

    /** Проверять только после блокировки заявки: задачу могли закрыть параллельно. */
    private function canEditContracts(PurchaseRequest $purchase, User $user): bool
    {
        $task = $this->access->findMyActiveTask($purchase, $user);

        return $task !== null && $task->getRequiresFileType() === PurchaseFileType::CONTRACT;
    }
}
