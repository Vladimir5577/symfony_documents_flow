<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Purchase;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\Purchase\PurchaseRouteTemplateStep;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseApproverKind;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStepPurpose;
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Service\Purchase\ApprovalRouteEditor;
use App\Service\Purchase\PurchaseRouteException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Заготовки маршрутов согласования: из каких шагов пойдут новые заявки.
 *
 * Правит только админ. Это не справочник, а регламент: тот, кто меняет маршрут,
 * решает, кого в закупках вообще спрашивают, — поэтому полномочия модуля
 * (в том числе «справочники») такого права не дают.
 *
 * Читать может любой авторизованный: маршрут виден всем в превью формы создания
 * и в шагах своей заявки, скрывать тут нечего, а фронту нужны справочники ролей
 * и назначений, чтобы нарисовать экран.
 */
#[Route('/spa/api/purchase-routes')]
final class PurchaseRouteController extends AbstractController
{
    public function __construct(
        private readonly PurchaseRouteTemplateRepository $templates,
        private readonly ApprovalRouteEditor $editor,
    ) {
    }

    /** Оба маршрута плюс справочники для формы редактора. */
    #[Route('', name: 'spa_api_purchase_routes_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json([
            'items' => array_map($this->present(...), PurchaseRequestKind::cases()),
            'roles' => $this->choices(PurchaseRoleCode::stepRoles()),
            'purposes' => $this->choices(PurchaseStepPurpose::cases()),
            'approverKinds' => $this->choices(PurchaseApproverKind::cases()),
            'fileTypes' => $this->choices(PurchaseFileType::cases()),
            'canManage' => $this->canManage(),
        ]);
    }

    /**
     * Заменить маршрут целиком: body {steps: [...]}.
     *
     * Целиком, а не пошагово: порядок и параллельность — свойства всего списка,
     * и «подвинь один шаг» разъезжался бы с тем, что видит админ на экране.
     */
    #[Route('/{kind}', name: 'spa_api_purchase_routes_update', methods: ['PUT'])]
    public function update(string $kind, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->canManage()) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        $requestKind = PurchaseRequestKind::tryFrom($kind);
        if ($requestKind === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_STEP_INVALID], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        $steps = is_array($payload) && is_array($payload['steps'] ?? null) ? $payload['steps'] : null;
        if ($steps === null) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->editor->replace($requestKind, array_values($steps), $user);
        } catch (PurchaseRouteException $exception) {
            return $this->json(['error' => $exception->errorCode], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['item' => $this->present($requestKind)]);
    }

    /**
     * Маршрут вида заявки. Умолчания в коде нет: пустой список означает, что
     * заявки этого вида подать нельзя, и экран обязан сказать это прямо.
     *
     * @return array<string, mixed>
     */
    private function present(PurchaseRequestKind $kind): array
    {
        $template = $this->templates->findByKind($kind);
        $steps = array_values($template?->getSteps()->toArray() ?? []);

        return [
            'kind' => $kind->value,
            'name' => $kind->getLabel(),
            'isConfigured' => $steps !== [],
            'updatedAt' => $template?->getUpdatedAt()?->format(\DateTimeInterface::ATOM),
            'updatedBy' => $this->nameOf($template),
            'steps' => array_map(
                static fn (PurchaseRouteTemplateStep $step): array => [
                    'position' => $step->getPosition(),
                    'approverKind' => $step->getApproverKind()->value,
                    'roleCode' => $step->getRoleCode()?->value,
                    'roleName' => $step->getRoleCode()?->getLabel(),
                    'purpose' => $step->getPurpose()->value,
                    'purposeName' => $step->getPurpose()->getLabel(),
                    // Свой заголовок и готовый — разные вещи: редактор правит
                    // первый, а пустой означает «подставь название роли», и
                    // подсунуть ему туда label значит заморозить его навсегда.
                    'title' => $step->getTitle(),
                    'label' => $step->resolveTitle(),
                    'requiresFileType' => $step->getRequiresFileType()?->value,
                ],
                $steps,
            ),
        ];
    }

    private function nameOf(?PurchaseRouteTemplate $template): ?string
    {
        $user = $template?->getUpdatedBy();
        if ($user === null) {
            return null;
        }

        $name = trim(($user->getLastname() ?? '') . ' ' . ($user->getFirstname() ?? ''));

        return $name !== '' ? $name : (string) $user->getLogin();
    }

    /**
     * @param list<PurchaseRoleCode|PurchaseStepPurpose|PurchaseApproverKind|PurchaseFileType> $cases
     * @return list<array{value: string, label: string}>
     */
    private function choices(array $cases): array
    {
        return array_map(
            static fn (PurchaseRoleCode|PurchaseStepPurpose|PurchaseApproverKind|PurchaseFileType $case): array => [
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
