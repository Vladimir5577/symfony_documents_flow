<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Inventory;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Inventory\CredentialViewLog;
use App\Entity\Inventory\Device;
use App\Entity\Inventory\DeviceCredential;
use App\Entity\User\User;
use App\Repository\Inventory\CredentialViewLogRepository;
use App\Repository\Inventory\DeviceCredentialRepository;
use App\Repository\Inventory\DeviceRepository;
use App\Service\SpaApi\Inventory\Device\DeviceCredentialService;
use App\Service\SpaApi\Inventory\InventoryAccessService;
use App\Service\SpaApi\Inventory\InventoryApiPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Доступы устройств (логин/пароль) и журнал их просмотров.
 *
 * Здесь класс-гейт `ROLE_INVENTORY_ADMIN` УМЕСТЕН и стоит намеренно: по матрице доступа
 * креды — единственная сущность модуля, к которой МОЛ не допущен вовсе, а админ наследует
 * все инвентарные роли, поэтому никого лишнего гейт не отрезает.
 * (Ср. ReconciliationController: там класс-гейт был бы дырой, роли друг друга не наследуют.)
 */
#[Route('/spa/api/inventory')]
#[IsGranted('ROLE_INVENTORY_ADMIN')]
final class DeviceCredentialController extends AbstractController
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly DeviceCredentialRepository $credentials,
        private readonly CredentialViewLogRepository $viewLogs,
        private readonly DeviceCredentialService $service,
        private readonly InventoryAccessService $access,
        private readonly InventoryApiPresenter $presenter,
    ) {
    }

    /** Список доступов устройства. Секретов здесь нет — только метаданные. */
    #[Route('/devices/{id}/credentials', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function list(int $id): JsonResponse
    {
        $device = $this->findDevice($id);

        return $this->json([
            'credentials' => array_map(
                $this->presenter->credential(...),
                $device->getCredentials()->toArray(),
            ),
        ]);
    }

    #[Route('/devices/{id}/credentials', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $device = $this->findDevice($id);

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $credential = $this->service->create($device, $payload, $user);

        return $this->json(
            ['credential' => $this->presenter->credential($credential)],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/credentials/{cid}', requirements: ['cid' => '\d+'], methods: ['PATCH'])]
    public function update(int $cid, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $credential = $this->findCredential($cid);

        $payload = json_decode((string) $request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVENTORY_INVALID_PAYLOAD], Response::HTTP_BAD_REQUEST);
        }

        $this->service->update($credential, $payload, $user);

        return $this->json(['credential' => $this->presenter->credential($credential)]);
    }

    /**
     * Удаление. Если доступ хоть раз раскрывали, строка остаётся с затёртым секретом:
     * журнал просмотров ссылается на неё с RESTRICT, и терять след просмотра нельзя.
     * Клиенту это сообщается флагом `wiped`, чтобы UI не показывал «удалено», когда
     * запись на самом деле осталась.
     */
    #[Route('/credentials/{cid}', requirements: ['cid' => '\d+'], methods: ['DELETE'])]
    public function delete(int $cid, #[CurrentUser] User $user): JsonResponse
    {
        $credential = $this->findCredential($cid);
        $wiped = $this->service->delete($credential, $user);

        return $this->json([
            'wiped' => $wiped,
            'credential' => $wiped ? $this->presenter->credential($credential) : null,
        ]);
    }

    /**
     * Раскрытие секрета. Единственное место API, где логин и пароль покидают сервер.
     *
     * `Cache-Control: no-store` обязателен: без него ответ осядет в кэше браузера
     * и прокси, и пароль переживёт закрытие вкладки.
     */
    #[Route('/credentials/{cid}/reveal', requirements: ['cid' => '\d+'], methods: ['POST'])]
    public function reveal(int $cid, #[CurrentUser] User $user): JsonResponse
    {
        $credential = $this->findCredential($cid);
        $secret = $this->service->reveal($credential, $user);

        $response = $this->json([
            'credential' => $this->presenter->credential($credential),
            'secret' => ['login' => $secret['login'], 'password' => $secret['password']],
        ]);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * Журнал просмотров кредов — кто и когда видел пароль.
     * Названия в записях денормализованы, поэтому журнал читается даже после удаления
     * устройства или доступа.
     */
    #[Route('/credential-view-log', methods: ['GET'])]
    public function viewLog(Request $request): JsonResponse
    {
        if (!$this->access->isAdmin()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $pageSize = max(1, min(100, (int) $request->query->get('page_size', 20)));
        $criteria = [];
        if ($request->query->has('credential_id')) {
            $criteria['credential'] = (int) $request->query->get('credential_id');
        }

        // Фильтр по устройству: без него вкладка «Журнал доступов» в карточке одного
        // устройства показывала журнал по ВСЕМ устройствам (находка ревью Kimi).
        // Доступы устройства могли быть удалены вместе со строками, поэтому берём id
        // из живых кредов, а не из связи журнала — записи удалённых сюда не попадут,
        // и это честно: они уже не относятся к текущему составу карточки.
        if ($request->query->has('device_id')) {
            $device = $this->devices->find((int) $request->query->get('device_id'));
            if ($device === null) {
                return $this->json(['error' => SpaApiError::INVENTORY_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }
            $credentialIds = array_map(
                static fn (DeviceCredential $c): int => (int) $c->getId(),
                $device->getCredentials()->toArray(),
            );
            if ($credentialIds === []) {
                return $this->json([
                    'credential_view_log' => [],
                    'pagination' => $this->presenter->pagination($page, $pageSize, 0),
                ]);
            }
            $criteria['credential'] = $credentialIds;
        }

        $total = $this->viewLogs->count($criteria);
        $logs = $this->viewLogs->findBy($criteria, ['id' => 'DESC'], $pageSize, ($page - 1) * $pageSize);

        return $this->json([
            'credential_view_log' => array_map(
                $this->presenter->credentialViewLog(...),
                array_values(array_filter($logs, static fn ($l): bool => $l instanceof CredentialViewLog)),
            ),
            'pagination' => $this->presenter->pagination($page, $pageSize, $total),
        ]);
    }

    private function findDevice(int $id): Device
    {
        $device = $this->devices->find($id);
        if ($device === null) {
            throw $this->createNotFoundException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        return $device;
    }

    private function findCredential(int $cid): DeviceCredential
    {
        $credential = $this->credentials->find($cid);
        if ($credential === null) {
            throw $this->createNotFoundException(SpaApiError::INVENTORY_NOT_FOUND);
        }

        return $credential;
    }
}
