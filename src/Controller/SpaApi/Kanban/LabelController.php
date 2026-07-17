<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Kanban\KanbanBoard;
use App\Entity\Kanban\KanbanLabel;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Enum\Kanban\KanbanColumnColor;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Repository\Kanban\KanbanLabelRepository;
use App\Repository\Kanban\Project\KanbanProjectRepository;
use App\Service\Kanban\KanbanCardActivityLogger;
use App\Service\Kanban\KanbanRealtimePublisher;
use App\Service\Kanban\KanbanService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

// DISABLED KANBAN MODULE
// #[Route('/spa/api/projects')]
final class LabelController extends AbstractController
{
    public function __construct(
        private readonly KanbanProjectRepository $projectRepository,
        private readonly KanbanBoardRepository $boardRepository,
        private readonly KanbanLabelRepository $labelRepository,
        private readonly KanbanCardRepository $cardRepository,
        private readonly KanbanService $kanbanService,
        private readonly EntityManagerInterface $entityManager,
        private readonly KanbanCardActivityLogger $activityLogger,
        private readonly KanbanRealtimePublisher $realtimePublisher,
    ) {
    }

    #[Route('/{id}/boards/{boardId}/labels', name: 'spa_api_project_board_labels_list', requirements: ['id' => '\d+', 'boardId' => '\d+'], methods: ['GET'])]
    public function list(int $id, int $boardId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $board = $this->resolveBoard($id, $boardId);
        if ($board instanceof JsonResponse) {
            return $board;
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $labels = $this->labelRepository->findBy(['board' => $board]);

        return $this->json(array_map(fn (KanbanLabel $label) => $this->formatLabel($label), $labels));
    }

    #[Route('/{id}/boards/{boardId}/labels', name: 'spa_api_project_board_labels_create', requirements: ['id' => '\d+', 'boardId' => '\d+'], methods: ['POST'])]
    public function create(int $id, int $boardId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $board = $this->resolveBoard($id, $boardId);
        if ($board instanceof JsonResponse) {
            return $board;
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => SpaApiError::INVALID_JSON], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => SpaApiError::LABEL_NAME_REQUIRED], Response::HTTP_BAD_REQUEST);
        }

        $color = KanbanColumnColor::tryFrom((string) ($payload['color'] ?? '')) ?? KanbanColumnColor::BG_PRIMARY;

        $label = new KanbanLabel();
        $label->setName($name);
        $label->setColor($color);
        $label->setBoard($board);

        $this->entityManager->persist($label);
        $this->entityManager->flush();

        return $this->json($this->formatLabel($label), Response::HTTP_CREATED);
    }

    #[Route('/{id}/boards/{boardId}/labels/{labelId}', name: 'spa_api_project_board_labels_delete', requirements: ['id' => '\d+', 'boardId' => '\d+', 'labelId' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id, int $boardId, int $labelId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $board = $this->resolveBoard($id, $boardId);
        if ($board instanceof JsonResponse) {
            return $board;
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_ADMIN);

        $label = $this->labelRepository->find($labelId);
        if ($label === null || $label->getBoard()->getId() !== $boardId) {
            return $this->json(['error' => SpaApiError::LABEL_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->entityManager->remove($label);
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/boards/{boardId}/labels/cards/{cardId}/{labelId}', name: 'spa_api_project_board_labels_toggle', requirements: ['id' => '\d+', 'boardId' => '\d+', 'cardId' => '\d+', 'labelId' => '\d+'], methods: ['POST'])]
    public function toggleLabel(int $id, int $boardId, int $cardId, int $labelId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $board = $this->resolveBoard($id, $boardId);
        if ($board instanceof JsonResponse) {
            return $board;
        }

        $this->kanbanService->requireRole($board, $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $card = $this->cardRepository->find($cardId);
        if ($card === null || $card->getColumn()->getBoard()->getId() !== $boardId) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $label = $this->labelRepository->find($labelId);
        if ($label === null || $label->getBoard()->getId() !== $boardId) {
            return $this->json(['error' => SpaApiError::LABEL_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if ($card->getLabels()->contains($label)) {
            $card->removeLabel($label);
            $action = 'detached';
        } else {
            $card->addLabel($label);
            $action = 'attached';
        }

        $this->entityManager->flush();

        if ($action === 'attached') {
            $this->activityLogger->logLabelAdded($card, $label->getName());
        } else {
            $this->activityLogger->logLabelRemoved($card, $label->getName());
        }

        // Realtime: обновляем теги на карточке доски.
        $this->realtimePublisher->publishCardPatch(
            $card,
            $this->realtimePublisher->buildLabels($card),
            $user->getId(),
        );

        return $this->json(['action' => $action, 'labelId' => $label->getId()]);
    }

    private function resolveBoard(int $projectId, int $boardId): KanbanBoard|JsonResponse
    {
        $project = $this->projectRepository->find($projectId);
        if ($project === null) {
            return $this->json(['error' => SpaApiError::PROJECT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $board = $this->boardRepository->find($boardId);
        if ($board === null || $board->getProject()?->getId() !== $project->getId()) {
            return $this->json(['error' => SpaApiError::BOARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        return $board;
    }

    /**
     * @return array{id: int|null, name: string, color: string}
     */
    private function formatLabel(KanbanLabel $label): array
    {
        return [
            'id' => $label->getId(),
            'name' => $label->getName(),
            'color' => $label->getColor()->value,
        ];
    }
}
