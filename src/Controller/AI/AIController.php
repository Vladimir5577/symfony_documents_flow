<?php

declare(strict_types=1);

namespace App\Controller\AI;

use App\Entity\AI\AiChatAttachment;
use App\Entity\AI\AiChatMessage;
use App\Entity\User\User;
use App\Repository\AI\AiChatMessageRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AIController extends AbstractController
{
    public function __construct(
        private readonly AiChatMessageRepository $messageRepo,
        private readonly int $renderLimit,
    ) {
    }

    #[Route('/ai_agent', name: 'app_ai_agent')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $messages = $this->messageRepo->findRecentForUser($user, $this->renderLimit);

        $rendered = array_map(
            fn(AiChatMessage $m) => $this->serializeMessage($m),
            $messages
        );

        return $this->render('ai/index.html.twig', [
            'active_tab' => 'ai_agent',
            'history'    => $rendered,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(AiChatMessage $msg): array
    {
        return [
            'id'          => $msg->getId(),
            'role'        => $msg->getRole(),
            'content'     => $msg->getContent(),
            'status'      => $msg->getStatus(),
            'attachments' => array_map(
                fn(AiChatAttachment $a) => [
                    'id'           => $a->getId(),
                    'title'        => $a->getTitle(),
                    'content_type' => $a->getContentType(),
                    'size_bytes'   => $a->getSizeBytes(),
                    'is_image'     => $a->isImage(),
                    'url'          => $this->generateUrl('api_ai_chat_attachment_download', ['id' => $a->getId()]),
                ],
                $msg->getAttachments()->toArray()
            ),
        ];
    }
}
