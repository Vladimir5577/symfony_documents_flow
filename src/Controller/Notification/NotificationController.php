<?php

namespace App\Controller\Notification;

use App\Entity\User\User;
use App\Repository\Notification\NotificationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NotificationController extends AbstractController
{
    public function __construct(
        private readonly NotificationRepository $notificationRepo,
    ) {}

    #[Route('/notifications', name: 'app_notifications')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 20;

        $notifications = $this->notificationRepo->findAllForUser($user, $page, $perPage);
        $total = $this->notificationRepo->countAllForUser($user);

        return $this->render('notification/index.html.twig', [
            'notifications' => $notifications,
            'currentPage' => $page,
            'totalPages' => (int) ceil($total / $perPage),
            'total' => $total,
        ]);
    }

    #[Route('/api/notifications/latest', name: 'api_notifications_latest', methods: ['GET'])]
    public function latest(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $notifications = $this->notificationRepo->findLatestForUser($user, 15);
        $unreadCount = $this->notificationRepo->countUnreadForUser($user, 100);

        $items = [];
        foreach ($notifications as $notification) {
            $items[] = [
                'id' => $notification->getId(),
                'type' => $notification->getType()->value,
                'title' => $notification->getTitle(),
                'message' => $notification->getMessage(),
                'link' => $notification->getLink(),
                'isRead' => $notification->isRead(),
                'createdAt' => $notification->getCreatedAt()->format('Y-m-d\TH:i:s'),
            ];
        }

        return $this->json([
            'unreadCount' => $unreadCount,
            'notifications' => $items,
        ]);
    }

    #[Route('/api/notifications/{id}/read', name: 'api_notifications_read', methods: ['POST'])]
    public function markAsRead(int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $notification = $this->notificationRepo->find($id);

        if (!$notification || $notification->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Уведомление не найдено.'], Response::HTTP_NOT_FOUND);
        }

        $this->notificationRepo->markAsRead($notification);

        return $this->json(['success' => true]);
    }

    #[Route('/api/notifications/read-all', name: 'api_notifications_read_all', methods: ['POST'])]
    public function markAllAsRead(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->notificationRepo->markAllAsReadForUser($user);

        return $this->json(['success' => true]);
    }
}
