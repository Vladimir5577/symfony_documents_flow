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
use App\Enum\User\UserRole;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Справочник шаблонов маршрута согласования.
 *
 * Стартовых шаблонов два — с is_default_for = FAST и STANDARD, они применяются
 * автоматически по нажатой кнопке создания. Остальные (is_default_for = NULL) —
 * заготовки: автору не видны и ни на что не влияют, пока отдел закупок не
 * применит их к конкретной заявке на своём шаге.
 *
 * Ведут отдел закупок и директор: порог себе ставит директор, и логично, что
 * он же может посмотреть и поправить цепочку, в которой стоит.
 */
#[Route('/spa/api/purchase-route-templates')]
final class PurchaseRouteTemplateController extends AbstractController
{
    public function __construct(
        private readonly PurchaseRouteTemplateRepository $templateRepo,
        private readonly UserRepository $userRepo,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Читают все участники процесса: список нужен переключателю маршрута. */
    #[Route('', name: 'spa_api_purchase_route_templates_list', methods: ['GET'])]
    public function list(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->json([
            'items' => array_map(
                fn (PurchaseRouteTemplate $template): array => $this->present($template),
                $this->templateRepo->findAllOrdered(),
            ),
        ]);
    }

    #[Route('', name: 'spa_api_purchase_route_templates_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (($denied = $this->assertEditor($user)) !== null) {
            return $denied;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $template = new PurchaseRouteTemplate();
        $this->em->persist($template);

        if (($error = $this->apply($template, $payload)) !== null) {
            return $error;
        }

        $this->em->flush();

        return $this->json($this->present($template), Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'spa_api_purchase_route_templates_update', requirements: ['id' => '\d+'], methods: ['PUT'])]
    public function update(int $id, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (($denied = $this->assertEditor($user)) !== null) {
            return $denied;
        }

        $template = $this->templateRepo->find($id);
        if ($template === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_TEMPLATE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        if (($error = $this->apply($template, $payload)) !== null) {
            return $error;
        }

        $this->em->flush();

        return $this->json($this->present($template));
    }

    /**
     * Удаление шаблона. Заявки в пути не пострадают — их маршрут это снапшот
     * в purchase_approval_step, а не ссылка на шаги шаблона. Но ссылку
     * route_template_id обнулять молча нехорошо: если шаблон где-то применён,
     * его правильнее деактивировать, а не удалять.
     */
    #[Route('/{id}', name: 'spa_api_purchase_route_templates_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (($denied = $this->assertEditor($user)) !== null) {
            return $denied;
        }

        $template = $this->templateRepo->find($id);
        if ($template === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_TEMPLATE_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $used = (int) $this->em->createQuery(
            'SELECT COUNT(p.id) FROM App\Entity\Purchase\PurchaseRequest p WHERE p.routeTemplate = :t'
        )->setParameter('t', $template)->getSingleScalarResult();

        if ($used > 0) {
            return $this->json(['error' => SpaApiError::PURCHASE_TEMPLATE_IN_USE], Response::HTTP_CONFLICT);
        }

        $this->em->remove($template);
        $this->em->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Заполнение шаблона с валидацией. Проверки не косметические: каждая
     * закрывает способ получить неисполнимую заявку.
     *
     * @param array<mixed> $payload
     */
    private function apply(PurchaseRouteTemplate $template, array $payload): ?JsonResponse
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => SpaApiError::PURCHASE_TEMPLATE_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $isDefaultFor = null;
        if (($payload['isDefaultFor'] ?? null) !== null && $payload['isDefaultFor'] !== '') {
            $isDefaultFor = PurchaseRequestKind::tryFrom((string) $payload['isDefaultFor']);
            if ($isDefaultFor === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_INVALID], Response::HTTP_BAD_REQUEST);
            }
        }

        $active = (bool) ($payload['active'] ?? true);
        $rows = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];

        // Шаблон без шагов активировать нельзя: заявка на нём встанет сразу после подачи
        if ($active && $rows === []) {
            return $this->json(['error' => SpaApiError::PURCHASE_TEMPLATE_EMPTY], Response::HTTP_BAD_REQUEST);
        }

        // «Не более одного» — не «ровно один»: сидов нет, на пустой системе их
        // ноль, и подача честно падает в 409, а не подставляет короткий маршрут
        if ($isDefaultFor !== null && $active) {
            $existing = $this->templateRepo->findDefaultFor($isDefaultFor);
            if ($existing !== null && $existing->getId() !== $template->getId()) {
                return $this->json(['error' => SpaApiError::PURCHASE_TEMPLATE_DEFAULT_TAKEN], Response::HTTP_CONFLICT);
            }
        }

        $steps = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_INVALID], Response::HTTP_BAD_REQUEST);
            }
            $step = new PurchaseRouteTemplateStep();
            if (($error = $this->applyStep($step, $row)) !== null) {
                return $error;
            }
            $steps[] = $step;
        }

        usort($steps, static fn (PurchaseRouteTemplateStep $a, PurchaseRouteTemplateStep $b): int
            => $a->getPosition() <=> $b->getPosition());

        // На «первый шаг — отдел закупок» завязаны правка маршрута и recall:
        // шаблон, начинающийся с директора, дал бы непра́вимую и неотзываемую заявку
        if ($isDefaultFor !== null && $active) {
            $first = $steps[0] ?? null;
            if ($first === null
                || $first->getApproverKind() !== PurchaseApproverKind::ROLE
                || $first->getApproverRole() !== UserRole::ROLE_PURCHASE_DEPARTMENT->value
            ) {
                return $this->json(
                    ['error' => SpaApiError::PURCHASE_TEMPLATE_FIRST_STEP_DEPARTMENT],
                    Response::HTTP_BAD_REQUEST,
                );
            }
        }

        $template->setName($name);
        $template->setIsDefaultFor($isDefaultFor);
        $template->setActive($active);

        foreach ($template->getSteps()->toArray() as $old) {
            $template->removeStep($old);
            $this->em->remove($old);
        }
        foreach ($steps as $step) {
            $template->addStep($step);
            $this->em->persist($step);
        }

        return null;
    }

    /**
     * @param array<mixed> $row
     */
    private function applyStep(PurchaseRouteTemplateStep $step, array $row): ?JsonResponse
    {
        $kind = PurchaseApproverKind::tryFrom((string) ($row['kind'] ?? ''));
        if ($kind === null) {
            return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_INVALID], Response::HTTP_BAD_REQUEST);
        }

        $step->setPosition(max(1, (int) ($row['position'] ?? 1)));
        $step->setApproverKind($kind);
        $step->setTitle(
            isset($row['title']) && trim((string) $row['title']) !== '' ? (string) $row['title'] : null,
        );

        if (isset($row['requiresFileType']) && $row['requiresFileType'] !== '' && $row['requiresFileType'] !== null) {
            $fileType = PurchaseFileType::tryFrom((string) $row['requiresFileType']);
            if ($fileType === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_INVALID_FILE_TYPE], Response::HTTP_BAD_REQUEST);
            }
            $step->setRequiresFileType($fileType);
        }

        if ($kind === PurchaseApproverKind::ROLE) {
            $role = UserRole::tryFrom((string) ($row['role'] ?? ''));
            if ($role === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_TEMPLATE_UNKNOWN_ROLE], Response::HTTP_BAD_REQUEST);
            }
            // Роль без носителей — шаг, который никто никогда не закроет.
            // Ловим здесь, а не через неделю разбирательств «почему заявка висит».
            if ($this->userRepo->findByRoleName($role->value) === []) {
                return $this->json(
                    ['error' => SpaApiError::PURCHASE_TEMPLATE_ROLE_WITHOUT_HOLDERS],
                    Response::HTTP_BAD_REQUEST,
                );
            }
            $step->setApproverRole($role->value);

            return null;
        }

        if ($kind === PurchaseApproverKind::USER) {
            $approver = $this->userRepo->find((int) ($row['userId'] ?? 0));
            if ($approver === null) {
                return $this->json(['error' => SpaApiError::PURCHASE_ROUTE_INVALID], Response::HTTP_BAD_REQUEST);
            }
            $step->setApproverUser($approver);
        }

        // CATEGORY_RESPONSIBLE не требует ни роли, ни человека: строитель
        // развернёт его в ответственных за категории конкретной заявки

        return null;
    }

    private function assertEditor(?User $user): ?JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }
        if (!$this->isGranted(UserRole::ROLE_PURCHASE_DEPARTMENT->value)
            && !$this->isGranted(UserRole::ROLE_PURCHASE_DIRECTOR->value)
            && !$this->isGranted(UserRole::ROLE_ADMIN->value)
        ) {
            return $this->json(['error' => SpaApiError::ACCESS_DENIED], Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PurchaseRouteTemplate $template): array
    {
        return [
            'id' => $template->getId(),
            'name' => $template->getName(),
            'isDefaultFor' => $template->getIsDefaultFor()?->value,
            'active' => $template->isActive(),
            'steps' => array_values(array_map(
                function (PurchaseRouteTemplateStep $step): array {
                    $role = $step->getApproverRole() !== null ? UserRole::tryFrom($step->getApproverRole()) : null;

                    return [
                        'id' => $step->getId(),
                        'position' => $step->getPosition(),
                        'kind' => $step->getApproverKind()->value,
                        'role' => $role !== null
                            ? ['value' => $role->value, 'label' => $role->getLabel()]
                            : null,
                        'user' => $step->getApproverUser() !== null ? [
                            'id' => $step->getApproverUser()->getId(),
                            'name' => trim(
                                ($step->getApproverUser()->getLastname() ?? '')
                                . ' ' . ($step->getApproverUser()->getFirstname() ?? '')
                            ),
                        ] : null,
                        'title' => $step->getTitle(),
                        'requiresFileType' => $step->getRequiresFileType()?->value,
                    ];
                },
                $template->getSteps()->toArray(),
            )),
        ];
    }
}
