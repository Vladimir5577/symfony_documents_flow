<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Notification;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Notification\Notification;
use App\Entity\User\User;
use App\Repository\Kanban\KanbanBoardRepository;
use App\Repository\Document\DocumentUserRecipientRepository;
use App\Repository\Notification\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/notifications')]
final class NotificationController extends AbstractController
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const DEFAULT_LATEST_LIMIT = 15;
    private const UNREAD_COUNT_CAP = 100;

    public function __construct(
        private readonly NotificationRepository $notificationRepo,
        private readonly DocumentUserRecipientRepository $recipientRepo,
        private readonly KanbanBoardRepository $kanbanBoardRepository,
    ) {
    }

    /**
     * Пагинированный список уведомлений текущего пользователя в формате legacy.
     * Query: page (default 1), page_size (default 20, max 100).
     */
    #[Route('', name: 'spa_api_notifications_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $page = max(1, $request->query->getInt('page', 1));
        $pageSize = $this->resolveLimit($request->query->getInt('page_size', self::DEFAULT_LIMIT));
        $notifications = $this->notificationRepo->findAllForUser($user, $page, $pageSize);
        $total = $this->notificationRepo->countAllForUser($user);

        return $this->json([
            'items' => array_map(
                fn (Notification $notification): array => $this->formatNotification($notification),
                $notifications,
            ),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
            'unreadCount' => $this->notificationRepo->countUnreadForUser($user, self::UNREAD_COUNT_CAP),
            'unreadDocumentsCount' => $this->recipientRepo->countNewIncomingForUser($user),
        ]);
    }

    /**
     * Последние непрочитанные уведомления для колокольчика (аналог legacy /api/notifications/latest).
     * Query: limit (default 15, max 100).
     */
    #[Route('/latest', name: 'spa_api_notifications_latest', methods: ['GET'])]
    public function latest(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $limit = $this->resolveLimit(
            $request->query->getInt('limit', self::DEFAULT_LATEST_LIMIT),
        );

        $notifications = $this->notificationRepo->findLatestForUser($user, $limit);

        return $this->json([
            'unreadCount' => $this->notificationRepo->countUnreadForUser($user, self::UNREAD_COUNT_CAP),
            'unreadDocumentsCount' => $this->recipientRepo->countNewIncomingForUser($user),
            'notifications' => array_map(
                fn (Notification $notification): array => $this->formatNotification($notification),
                $notifications,
            ),
        ]);
    }

    #[Route('/read-all', name: 'spa_api_notifications_read_all', methods: ['POST'])]
    public function markAllAsRead(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->notificationRepo->markAllAsReadForUser($user);

        return $this->json(['success' => true]);
    }

    #[Route('/{id}/read', name: 'spa_api_notifications_read', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function markAsRead(int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $notification = $this->notificationRepo->find($id);

        if (!$notification instanceof Notification || $notification->getUser()?->getId() !== $user->getId()) {
            return $this->json(['error' => SpaApiError::NOTIFICATION_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->notificationRepo->markAsRead($notification);

        return $this->json(['success' => true]);
    }

    private function resolveLimit(int $limit): int
    {
        if ($limit < 1) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * @return array{
     *     id: int|null,
     *     type: string,
     *     typeLabel: string,
     *     title: string,
     *     message: string|null,
     *     link: string|null,
     *     isRead: bool,
     *     createdAt: string
     * }
     */
    private function formatNotification(Notification $notification): array
    {
        return [
            'id' => $notification->getId(),
            'type' => $notification->getType()->value,
            'typeLabel' => $notification->getType()->getLabel(),
            'title' => $notification->getTitle(),
            'message' => $notification->getMessage(),
            'link' => $this->getLink($notification->getLink()),
            'isRead' => $notification->isRead(),
            'createdAt' => $notification->getCreatedAt()->format('Y-m-d\TH:i:s'),
        ];
    }

    private function getLink(?string $rawLink): ?string
    {
        if ($rawLink === null || $rawLink === '') {
            return $rawLink;
        }

        if (str_starts_with($rawLink, '/view_incoming_document')) {
            return '/document-in';
        }

        if (!str_starts_with($rawLink, '/kanban_board/')) {
            if (str_starts_with($rawLink, '/kanban_project/')) {
                $projectId = (int) preg_replace('/\D+/', '', $rawLink);
                if ($projectId > 0) {
                    return '/projects/' . $projectId . '/edit';
                }
            }

            return $rawLink;
        }

        $parts = parse_url($rawLink);
        $path = $parts['path'] ?? '';
        $query = $parts['query'] ?? '';

        if (!preg_match('#^/kanban_board/(\d+)$#', $path, $matches)) {
            return $rawLink;
        }

        $boardId = (int) $matches[1];
        if ($boardId <= 0) {
            return $rawLink;
        }

        $board = $this->kanbanBoardRepository->find($boardId);
        $projectId = $board?->getProject()?->getId();
        if ($projectId === null) {
            return $rawLink;
        }

        parse_str($query, $queryParams);
        $spaQuery = 'board=' . $boardId;
        if (isset($queryParams['card']) && is_scalar($queryParams['card'])) {
            $spaQuery .= '&card=' . (int) $queryParams['card'];
        }

        return '/projects/' . $projectId . '?' . $spaQuery;
    }
}
