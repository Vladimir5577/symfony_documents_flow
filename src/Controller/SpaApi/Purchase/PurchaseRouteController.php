<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseRouteDefaultRepository;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Service\Purchase\ApprovalRouteBuilder;
use App\Service\Purchase\ApprovalRouteEditor;
use App\Service\Purchase\PurchaseApiPresenter;
use App\Service\Purchase\PurchaseRouteException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Заготовки маршрутов согласования: по каким цепочкам этапов идут заявки.
 *
 * Правит только админ. Это не справочник, а регламент: тот, кто меняет маршрут,
 * решает, кого в закупках вообще спрашивают, — поэтому полномочия модуля (в том
 * числе «справочники») такого права не дают.
 *
 * Читать может любой авторизованный: маршрут виден всем в превью формы создания и
 * в этапах своей заявки, скрывать тут нечего, а фронту нужны справочники ролей и
 * назначений, чтобы нарисовать экран.
 */
#[Route('/spa/api/purchase-routes')]
final class PurchaseRouteController extends AbstractController
{
    public function __construct(
        private readonly PurchaseRouteTemplateRepository $templates,
        private readonly PurchaseRouteDefaultRepository $defaults,
        private readonly ApprovalRouteEditor $editor,
        private readonly ApprovalRouteBuilder $builder,
        private readonly PurchaseApiPresenter $presenter,
    ) {
    }

    /** Все заготовки, дефолты по видам заявок и справочники для формы редактора. */
    #[Route('', name: 'spa_api_purchase_routes_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $defaults = [];
        foreach (PurchaseRequestKind::cases() as $kind) {
            $default = $this->defaults->findByKind($kind);
            $defaults[] = [
                'kind' => $kind->value,
                'name' => $kind->getLabel(),
                'templateId' => $default?->getTemplate()?->getId(),
                // Вид заявки без дефолта подать нельзя — экран обязан сказать это прямо.
                'isConfigured' => $default?->getTemplate() !== null
                    && $default->getTemplate()->isActive()
                    && !$default->getTemplate()->isEmpty(),
                'updatedAt' => $default?->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $this->json([
            'items' => array_map(
                $this->presenter->presentRouteTemplate(...),
                $this->templates->findAllOrdered(),
            ),
            'defaults' => $defaults,
            'kinds' => $this->choices(PurchaseRequestKind::cases()),
            // Пул для динамического этапа — любая роль: «выбрать поимённо из
            // охраны» такое же требование, как «из профильных замов».
            'roles' => $this->choices(PurchaseRoleCode::cases()),
            'taskRoles' => $this->choices(PurchaseRoleCode::taskRoles()),
            'purposes' => $this->choices(PurchaseStagePurpose::cases()),
            'assignmentTypes' => $this->choices(PurchaseTaskAssignment::templateAssignments()),
            'fileTypes' => $this->choices(PurchaseFileType::cases()),
            'canManage' => $this->canManage(),
        ]);
    }

    /** Превью маршрута: какие этапы получатся. Считает бэк, чтобы фронт не дублировал правила. */
    #[Route('/{id}/preview', name: 'spa_api_purchase_routes_preview', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function preview(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $template = $this->templates->findWithStages($id);
        if ($template === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $this->json(['stages' => $this->builder->preview($template)]);
    }

    /**
     * Создать заготовку: body {code, name, allowedKinds, stages: [...]}.
     */
    #[Route('', name: 'spa_api_purchase_routes_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->manage($user, function (User $actor) use ($request): JsonResponse {
            $payload = $this->payload($request);
            if ($payload === null) {
                return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
            }

            $template = $this->editor->create($payload, $actor);

            return $this->json(
                ['item' => $this->presenter->presentRouteTemplate($template)],
                Response::HTTP_CREATED,
            );
        });
    }

    /**
     * Заменить заготовку целиком: body {name, allowedKinds, stages: [{purpose, tasks: [...]}]}.
     *
     * Целиком, а не по одному этапу: порядок этапов и состав задач — свойства
     * всего маршрута, и «подвинь один этап» разъезжался бы с тем, что видит админ.
     */
    #[Route('/{id}', name: 'spa_api_purchase_routes_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->manage($user, function (User $actor) use ($id, $request): JsonResponse {
            $template = $this->templates->findWithStages($id);
            if ($template === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }

            $payload = $this->payload($request);
            if ($payload === null) {
                return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
            }

            $this->editor->update($template, $payload, $actor);

            return $this->json(['item' => $this->presenter->presentRouteTemplate($template)]);
        });
    }

    /**
     * Копия заготовки: body {code, name}.
     *
     * Маршруты в большой компании отличаются на один-два этапа, и собирать похожий
     * с нуля значит переписывать десяток строк ради одной.
     */
    #[Route('/{id}/clone', name: 'spa_api_purchase_routes_clone', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function clone(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->manage($user, function (User $actor) use ($id, $request): JsonResponse {
            $source = $this->templates->findWithStages($id);
            if ($source === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }

            $payload = $this->payload($request);
            if ($payload === null) {
                return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
            }

            $copy = $this->editor->clone(
                $source,
                (string) ($payload['code'] ?? ''),
                (string) ($payload['name'] ?? ''),
                $actor,
            );

            return $this->json(
                ['item' => $this->presenter->presentRouteTemplate($copy)],
                Response::HTTP_CREATED,
            );
        });
    }

    /**
     * Включить или выключить заготовку: body {isActive}.
     *
     * Удаления нет намеренно: на заготовку ссылаются прошедшие заявки, и вопрос
     * «по какому регламенту это согласовали» должен иметь ответ.
     */
    #[Route('/{id}/active', name: 'spa_api_purchase_routes_active', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function setActive(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->manage($user, function (User $actor) use ($id, $request): JsonResponse {
            $template = $this->templates->findWithStages($id);
            if ($template === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }

            $payload = $this->payload($request);
            if ($payload === null) {
                return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
            }

            $this->editor->setActive($template, (bool) ($payload['isActive'] ?? true), $actor);

            return $this->json(['item' => $this->presenter->presentRouteTemplate($template)]);
        });
    }

    /**
     * Назначить заготовку маршрутом по умолчанию: body {kind, templateId}.
     *
     * Касается только будущих подач: у заявки в пути свой снимок маршрута.
     */
    #[Route('/defaults', name: 'spa_api_purchase_routes_default', methods: ['PUT'])]
    public function setDefault(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        return $this->manage($user, function (User $actor) use ($request): JsonResponse {
            $payload = $this->payload($request);
            if ($payload === null) {
                return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
            }

            $kind = PurchaseRequestKind::tryFrom((string) ($payload['kind'] ?? ''));
            if ($kind === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_INVALID_STATUS], Response::HTTP_BAD_REQUEST);
            }

            $template = $this->templates->findWithStages((int) ($payload['templateId'] ?? 0));
            if ($template === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_NOT_FOUND], Response::HTTP_NOT_FOUND);
            }

            $this->editor->setDefault($kind, $template, $actor);

            return $this->json(['item' => $this->presenter->presentRouteTemplate($template)]);
        });
    }

    /**
     * Гейт админа и обработка правил заготовки — один на все правящие ручки.
     *
     * @param callable(User): JsonResponse $action
     */
    private function manage(?User $user, callable $action): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        try {
            return $action($user);
        } catch (PurchaseRouteException $exception) {
            return $this->json(['error' => $exception->errorCode], Response::HTTP_BAD_REQUEST);
        }
    }

    /** @return array<string, mixed>|null */
    private function payload(Request $request): ?array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param list<PurchaseRoleCode|PurchaseStagePurpose|PurchaseTaskAssignment|PurchaseFileType|PurchaseRequestKind> $cases
     * @return list<array{value: string, label: string}>
     */
    private function choices(array $cases): array
    {
        return array_map(
            static fn (
                PurchaseRoleCode|PurchaseStagePurpose|PurchaseTaskAssignment|PurchaseFileType|PurchaseRequestKind $case,
            ): array => [
                'value' => $case->value,
                'label' => $case->getLabel(),
            ],
            $cases,
        );
    }

    private function canManage(): bool
    {
        return $this->isGranted(UserRole::ROLE_ADMIN->value);
    }
}
