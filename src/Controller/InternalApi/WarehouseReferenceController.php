<?php

declare(strict_types=1);

namespace App\Controller\InternalApi;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\Organization\Department;
use App\Entity\Organization\Filial;
use App\Entity\User\User;
use App\Repository\Organization\OrganizationRepository;
use App\Repository\User\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Справочники для микросервиса складского учёта (go_warehouse_service).
 *
 * ⚠ ЗАЧЕМ ОТДЕЛЬНЫЙ КОНТРОЛЛЕР, А НЕ РАСШИРЕНИЕ KanbanUserController.
 * Канбан знает user_id из своих таблиц и просит пользователей точечно (?ids=).
 * Складской сервис матчит сотрудников ПО ФИО из выгрузок 1С и никаких id
 * заранее не знает — ему нужен весь список. Разные контракты у разных
 * потребителей: расширение канбановского эндпоинта связало бы два сервиса,
 * которые ничего друг о друге знать не должны.
 *
 * ⚠ ПОЧЕМУ ЭТО КРИТИЧНО ДЛЯ СКЛАДСКОГО СЕРВИСА.
 * Реплика users там наполняется двумя путями: событиями RabbitMQ
 * (user.upserted / user.deleted) и разовой массовой загрузкой при старте.
 * События описывают только ИЗМЕНЕНИЯ, а topic exchange выбрасывает сообщение,
 * если под него ещё нет очереди. Значит всё, опубликованное до первого запуска
 * консьюмера, потеряно, и без этого эндпоинта реплика стартует ПУСТОЙ —
 * а первый импорт МЦ.04 не свяжет ни одного из 163 сотрудников.
 *
 * Защита — X-API-Key со СВОИМ ключом (warehouse_internal_api_key), не общим
 * с канбаном: компрометация ключа одного сервиса не должна открывать справочники
 * другому. Файрвол на ^/api/internal отключён (security.yaml), проверка ключа
 * выполняется здесь.
 */
#[Route('/api/internal/warehouse')]
final class WarehouseReferenceController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    /**
     * Все активные сотрудники — для стартовой загрузки реплики и матчинга ФИО.
     *
     * Глобальный фильтр Gedmo SoftDeleteable включён, поэтому findAllActive()
     * (то есть findAll()) уже исключает удалённых. Как следствие, deleted_at
     * здесь всегда null: «надгробия» уволенных приходят в складской сервис
     * ТОЛЬКО событием user.deleted через RabbitMQ, HTTP их не отдаёт.
     * Поле оставлено в ответе ради совместимости формы с KanbanUserController.
     */
    #[Route('/users', name: 'internal_api_warehouse_users', methods: ['GET'])]
    public function getUsers(Request $request): JsonResponse
    {
        if (!$this->isApiKeyValid($request)) {
            return $this->json(['error' => 'Access Denied'], Response::HTTP_FORBIDDEN);
        }

        $users = $this->userRepository->findAllActive();

        $result = array_map(static function (User $user): array {
            return [
                'id' => $user->getId(),
                'login' => $user->getLogin(),
                'lastname' => $user->getLastname(),
                'firstname' => $user->getFirstname(),
                'patronymic' => $user->getPatronymic(),
                'deleted_at' => $user->getDeletedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }, $users);

        return $this->json($result);
    }

    /**
     * Подразделения плоским списком.
     *
     * ⚠ Отдаём ВСЕ организационные единицы (организации, филиалы, отделы),
     * а не только Department. Причина в данных: в выгрузках 1С подразделение
     * приходит СТРОКОЙ («ИНФОРМАЦИОННО-ТЕХНИЧЕСКИЙ ОТДЕЛ», «Основное
     * подразделение (ДСК)»), и заранее неизвестно, какому уровню иерархии
     * она соответствует. Если вернуть только отделы, строка, за которой стоит
     * филиал, не сматчится и уедет в базу голым текстом без связи.
     *
     * Плоский список с parent_id, а не дерево: потребителю нужна карта
     * «название → id», дерево он при необходимости соберёт сам по parent_id.
     *
     * Свойство name физически лежит в колонке short_name (single-table
     * inheritance на таблице organization, дискриминатор в колонке
     * discriminator) — здесь это скрыто за геттером, но при отладке SQL
     * помнить об этом обязательно.
     */
    #[Route('/departments', name: 'internal_api_warehouse_departments', methods: ['GET'])]
    public function getDepartments(Request $request): JsonResponse
    {
        if (!$this->isApiKeyValid($request)) {
            return $this->json(['error' => 'Access Denied'], Response::HTTP_FORBIDDEN);
        }

        /** @var AbstractOrganization[] $units */
        $units = $this->organizationRepository->findAll();

        $result = array_map(static function (AbstractOrganization $unit): array {
            return [
                'id' => $unit->getId(),
                'name' => $unit->getName(),
                'parent_id' => $unit->getParent()?->getId(),
                'type' => match (true) {
                    $unit instanceof Department => 'department',
                    $unit instanceof Filial => 'filial',
                    default => 'organization',
                },
            ];
        }, $units);

        return $this->json($result);
    }

    /**
     * Проверка X-API-Key.
     *
     * hash_equals, а не !==: сравнение секретов должно быть за постоянное время,
     * иначе по времени ответа ключ подбирается посимвольно.
     *
     * Пустой или незаданный параметр означает ОТКАЗ, а не разрешение: если
     * WAREHOUSE_INTERNAL_API_KEY забыли прописать в окружении, справочники
     * сотрудников не должны стать публичными.
     */
    private function isApiKeyValid(Request $request): bool
    {
        $expected = $this->getParameter('warehouse_internal_api_key');
        if (!is_string($expected) || $expected === '') {
            return false;
        }

        $provided = $request->headers->get('X-API-Key');
        if (!is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
