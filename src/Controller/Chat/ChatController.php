<?php

namespace App\Controller\Chat;

use App\Entity\User\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ChatController extends AbstractController
{
    #[Route('/api/chat/subscription', name: 'api_chat_subscription', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function subscription(Request $request, Authorization $authorization, #[CurrentUser] User $user): JsonResponse
    {
        $topic = '/chat/user/' . $user->getId();
        $response = new JsonResponse(['topic' => $topic]);
        $response->headers->setCookie($authorization->createCookie($request, [$topic], [], [
            // Целые секунды: дробный exp в JWT понимают не все клиенты.
            'exp' => new \DateTimeImmutable('@' . (time() + 3600)),
        ]));
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    #[Route('/chat', name: 'app_chat')]
    public function index(): Response
    {
        return $this->render('chat/index.html.twig', [
            'controller_name' => 'ChatController',
        ]);
    }
}
