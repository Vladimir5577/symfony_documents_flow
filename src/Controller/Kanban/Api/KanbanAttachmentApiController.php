<?php

namespace App\Controller\Kanban\Api;

use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanAttachmentRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Service\Kanban\KanbanAttachmentPreviewUrlGenerator;
use App\Service\Kanban\KanbanAttachmentService;
use App\Service\Kanban\KanbanCardActivityLogger;
use App\Service\Kanban\KanbanService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/kanban/cards/{cardId}/attachments')]
final class KanbanAttachmentApiController extends AbstractController
{
    public function __construct(
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanAttachmentRepository $attachmentRepo,
        private readonly KanbanService $kanbanService,
        private readonly KanbanAttachmentService $attachmentService,
        private readonly KanbanAttachmentPreviewUrlGenerator $kanbanAttachmentPreviewUrlGenerator,
        private readonly KanbanCardActivityLogger $activityLogger,
    ) {
    }

    #[Route('', name: 'api_kanban_attachments_upload', methods: ['POST'])]
    public function upload(int $cardId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'Файл не передан.'], Response::HTTP_BAD_REQUEST);
        }

        $attachment = $this->attachmentService->upload($file, $card);
        $attachment->setAuthor($user);

        $context = $request->request->get('context', 'info');
        if (!in_array($context, ['chat', 'info', 'description'], true)) {
            $context = 'info';
        }
        $attachment->setContext($context);
        $this->attachmentService->flush();

        if ($context !== 'chat') {
            $this->activityLogger->logAttachmentAdded($card, $attachment->getFilename());
        }

        return $this->json([
            'id' => $attachment->getId(),
            'filename' => $attachment->getFilename(),
            'contentType' => $attachment->getContentType(),
            'sizeBytes' => $attachment->getSizeBytes(),
            'context' => $attachment->getContext(),
            'createdAt' => $attachment->getCreatedAt()?->format('c'),
            'previewUrl' => $this->kanbanAttachmentPreviewUrlGenerator->getPreviewUrl($attachment),
            'authorId' => $attachment->getAuthor()?->getId(),
            'authorName' => $attachment->getAuthor()?->getLastname() . ' ' . $attachment->getAuthor()?->getFirstname(),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/download', name: 'api_kanban_attachments_download', methods: ['GET'])]
    public function download(int $cardId, int $id, Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $attachment = $this->attachmentRepo->find($id);
        if (!$attachment || $attachment->getCard()->getId() !== $cardId) {
            return $this->json(['error' => 'Вложение не найдено.'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->attachmentService->exists($attachment)) {
            return $this->json(['error' => 'Файл не найден в хранилище.'], Response::HTTP_NOT_FOUND);
        }

        $stream = $this->attachmentService->getObjectStream($attachment);
        $disposition = $request->query->getBoolean('inline', false) ? 'inline' : 'attachment';

        $response = new StreamedResponse(function () use ($stream) {
            while (!$stream->eof()) {
                echo $stream->read(8192);
                flush();
            }
            $stream->close();
        });

        $response->headers->set('Content-Type', $attachment->getContentType());
        $response->headers->set('Content-Disposition', sprintf('%s; filename="%s"', $disposition, $attachment->getFilename()));

        return $response;
    }

    #[Route('/{id}', name: 'api_kanban_attachments_delete', methods: ['DELETE'])]
    public function delete(int $cardId, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $card = $this->cardRepo->find($cardId);
        if (!$card) {
            return $this->json(['error' => 'Карточка не найдена.'], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $attachment = $this->attachmentRepo->find($id);
        if (!$attachment || $attachment->getCard()->getId() !== $cardId) {
            return $this->json(['error' => 'Вложение не найдено.'], Response::HTTP_NOT_FOUND);
        }

        $filename = $attachment->getFilename();
        $context = $attachment->getContext();

        $this->attachmentService->delete($attachment);

        if ($context !== 'chat') {
            $this->activityLogger->logAttachmentRemoved($card, $filename);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }
}
