<?php

namespace App\Controller\Kanban\Api;

use App\Entity\User\User;
use App\Enum\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanAttachmentRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Service\Kanban\KanbanAttachmentService;
use App\Service\Kanban\KanbanService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kanban/cards/{cardId}/attachments')]
final class KanbanAttachmentApiController extends AbstractController
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanAttachmentRepository $attachmentRepo,
        private readonly KanbanService $kanbanService,
        private readonly KanbanAttachmentService $attachmentService,
    ) {
    }

    #[Route('', name: 'api_kanban_attachments_upload', methods: ['POST'])]
    public function upload(string $cardId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'Файл не передан.'], Response::HTTP_BAD_REQUEST);
        }

        $attachment = $this->attachmentService->upload($file, $card);

        return $this->json([
            'id' => (string) $attachment->getId(),
            'filename' => $attachment->getFilename(),
            'contentType' => $attachment->getContentType(),
            'sizeBytes' => $attachment->getSizeBytes(),
            'createdAt' => $attachment->getCreatedAt()?->format('c'),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/download', name: 'api_kanban_attachments_download', methods: ['GET'])]
    public function download(string $cardId, string $id): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::VIEWER);

        $attachment = $this->attachmentRepo->find($id);
        if (!$attachment || (string) $attachment->getCard()->getId() !== $cardId) {
            return $this->json(['error' => 'Вложение не найдено.'], Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->attachmentService->getFilePath($attachment);
        if (!file_exists($filePath)) {
            return $this->json(['error' => 'Файл не найден на диске.'], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getFilename());

        return $response;
    }

    #[Route('/{id}', name: 'api_kanban_attachments_delete', methods: ['DELETE'])]
    public function delete(string $cardId, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::EDITOR);

        $attachment = $this->attachmentRepo->find($id);
        if (!$attachment || (string) $attachment->getCard()->getId() !== $cardId) {
            return $this->json(['error' => 'Вложение не найдено.'], Response::HTTP_NOT_FOUND);
        }

        $this->attachmentService->delete($attachment);

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
