<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseApprover;
use App\Entity\User\User;
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseApproverRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Справочник согласантов закупок: кого директор может отметить в заявке.
 * Ведут админ и отдел закупок, порядок задают руками.
 */
#[Route('/spa/api/purchase-approvers')]
final class PurchaseApproverController extends AbstractController
{
    public function __construct(
        private readonly PurchaseApproverRepository $approverRepo,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Читают все авторизованные: список нужен директору в модалке разбора заявок,
     * а ролевой гейт здесь дал бы ему 403 на своём же экране.
     */
    #[Route('', name: 'spa_api_purchase_approvers_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json(['items' => $this->presentAll()]);
    }

    #[Route('', name: 'spa_api_purchase_approvers_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        $userId = is_array($payload) ? ($payload['userId'] ?? null) : null;
        if ($userId === null || $userId === '') {
            return $this->json(['error' => SpaApiError::USER_NOT_FOUND], Response::HTTP_BAD_REQUEST);
        }

        $approverUser = $this->userRepo->find((int) $userId);
        if ($approverUser === null) {
            return $this->json(['error' => SpaApiError::USER_NOT_FOUND], Response::HTTP_BAD_REQUEST);
        }

        if ($this->approverRepo->findOneBy(['user' => $approverUser]) !== null) {
            return $this->json(['error' => SpaApiError::PURCHASE_APPROVER_ALREADY_ADDED], Response::HTTP_CONFLICT);
        }

        $approver = (new PurchaseApprover())
            ->setUser($approverUser)
            ->setPosition($this->approverRepo->nextPosition());

        $this->em->persist($approver);
        $this->em->flush();

        return $this->json(['items' => $this->presentAll()], Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_purchase_approvers_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $approver = $this->approverRepo->find($id);
        if ($approver === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_APPROVER_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        // Заявки, где этот человек уже согласант, не трогаем: шаги маршрута
        // ссылаются на User напрямую и живут своей жизнью.
        $this->em->remove($approver);
        $this->em->flush();

        return $this->json(['items' => $this->presentAll()]);
    }

    /**
     * Перестановка списка целиком: body {ids: [3, 1, 2]}.
     *
     * Список приходит одним куском, а не «подвинь на единицу»: при перетаскивании
     * это один запрос вместо пачки, и порядок не разъедется, если часть упадёт.
     * Строки, которых нет в присланном списке, уезжают в конец с прежним порядком —
     * это лучше, чем 400 из-за гонки с чужим удалением.
     */
    #[Route('/order', name: 'spa_api_purchase_approvers_order', methods: ['PUT'])]
    public function reorder(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        $ids = is_array($payload) && is_array($payload['ids'] ?? null) ? $payload['ids'] : null;
        if ($ids === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_APPROVER_NOT_FOUND], Response::HTTP_BAD_REQUEST);
        }

        /** @var array<int, PurchaseApprover> $byId */
        $byId = [];
        foreach ($this->approverRepo->findAllOrdered() as $approver) {
            $byId[(int) $approver->getId()] = $approver;
        }

        $position = 0;
        foreach ($ids as $id) {
            $approver = $byId[(int) $id] ?? null;
            if ($approver === null) {
                continue;
            }
            $approver->setPosition(++$position);
            unset($byId[(int) $id]);
        }

        foreach ($byId as $approver) {
            $approver->setPosition(++$position);
        }

        $this->em->flush();

        return $this->json(['items' => $this->presentAll()]);
    }

    private function canManage(): bool
    {
        return $this->isGranted(UserRole::ROLE_ADMIN->value)
            || $this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presentAll(): array
    {
        return array_map(
            static function (PurchaseApprover $approver): array {
                $user = $approver->getUser();
                $name = trim(($user?->getLastname() ?? '') . ' ' . ($user?->getFirstname() ?? ''));

                return [
                    'id' => $approver->getId(),
                    'position' => $approver->getPosition(),
                    'user' => [
                        'id' => $user?->getId(),
                        'name' => $name !== '' ? $name : (string) $user?->getLogin(),
                        // Должность: в списке из десятка фамилий директору нужно
                        // понимать, кому он отдаёт заявку, а не только «кто это».
                        'position' => $user?->getWorker()?->getProfession(),
                    ],
                ];
            },
            $this->approverRepo->findAllOrdered(),
        );
    }
}
