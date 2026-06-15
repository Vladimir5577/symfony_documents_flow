<?php

declare(strict_types=1);

namespace App\Controller\SpaApi\Kanban;

use App\Controller\SpaApi\SpaApiError;
use App\Entity\Kanban\KanbanAttachment;
use App\Entity\User\User;
use App\Enum\Kanban\KanbanBoardMemberRole;
use App\Repository\Kanban\KanbanAttachmentRepository;
use App\Repository\Kanban\KanbanCardRepository;
use App\Service\Kanban\KanbanAttachmentPreviewUrlGenerator;
use App\Service\Kanban\KanbanAttachmentService;
use App\Service\Kanban\KanbanCardActivityLogger;
use App\Service\Kanban\KanbanService;
use Liip\ImagineBundle\Service\FilterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/spa/api/cards/{cardId}/attachments')]
final class AttachmentController extends AbstractController
{
    private const ALLOWED_CONTEXTS = ['chat', 'info', 'description'];
    private const MAX_ATTACHMENTS_PER_CARD = 16;
    private const PREVIEW_FILTER = 'kanban_attachment_preview';

    public function __construct(
        private readonly KanbanCardRepository $cardRepo,
        private readonly KanbanAttachmentRepository $attachmentRepo,
        private readonly KanbanService $kanbanService,
        private readonly KanbanAttachmentService $attachmentService,
        private readonly KanbanAttachmentPreviewUrlGenerator $kanbanAttachmentPreviewUrlGenerator,
        private readonly FilterService $filterService,
        private readonly KanbanCardActivityLogger $activityLogger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('', name: 'spa_api_cards_attachments_upload', requirements: ['cardId' => '\d+'], methods: ['POST'])]
    public function upload(int $cardId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $file = $request->files->get('file');
        if ($file === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_PROVIDED], Response::HTTP_BAD_REQUEST);
        }

        if ($this->attachmentRepo->count(['card' => $card]) >= self::MAX_ATTACHMENTS_PER_CARD) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_LIMIT_REACHED], Response::HTTP_BAD_REQUEST);
        }

        $attachment = $this->attachmentService->upload($file, $card);
        $attachment->setAuthor($user);

        $context = (string) $request->request->get('context', 'info');
        if (!in_array($context, self::ALLOWED_CONTEXTS, true)) {
            $context = 'info';
        }
        $attachment->setContext($context);
        $this->attachmentService->flush();

        if ($context !== 'chat') {
            $this->activityLogger->logAttachmentAdded($card, $attachment->getFilename());
        }

        return $this->json($this->formatAttachment($attachment), Response::HTTP_CREATED);
    }

    #[Route('/{id}/download', name: 'spa_api_cards_attachments_download', requirements: ['cardId' => '\d+', 'id' => '\d+'], methods: ['GET'])]
    public function download(int $cardId, int $id, Request $request, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $attachment = $this->attachmentRepo->find($id);
        if ($attachment === null || $attachment->getCard()->getId() !== $cardId) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->attachmentService->getFilePath($attachment);
        if (!is_file($filePath)) {
            return $this->json(['error' => SpaApiError::FILE_NOT_FOUND_ON_DISK], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($filePath);
        $disposition = $request->query->getBoolean('inline', false)
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
        $response->setContentDisposition($disposition, $attachment->getFilename());

        return $response;
    }

    #[Route('/{id}/preview', name: 'spa_api_cards_attachments_preview', requirements: ['cardId' => '\d+', 'id' => '\d+'], methods: ['GET'])]
    public function preview(int $cardId, int $id, #[CurrentUser] ?User $user): Response
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_VIEWER);

        $attachment = $this->attachmentRepo->find($id);
        if ($attachment === null || $attachment->getCard()->getId() !== $cardId) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        if (!$this->kanbanAttachmentPreviewUrlGenerator->isImage($attachment)) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_PREVIEWABLE], Response::HTTP_NOT_FOUND);
        }

        if (!is_file($this->attachmentService->getFilePath($attachment))) {
            return $this->json(['error' => SpaApiError::FILE_NOT_FOUND_ON_DISK], Response::HTTP_NOT_FOUND);
        }

        $previewPath = $this->resolvePreviewFilePath($attachment);
        if ($previewPath === null) {
            return $this->json(['error' => SpaApiError::FILE_NOT_FOUND_ON_DISK], Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($previewPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $attachment->getFilename());

        return $response;
    }

    #[Route('/{id}', name: 'spa_api_cards_attachments_delete', requirements: ['cardId' => '\d+', 'id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $cardId, int $id, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $card = $this->cardRepo->find($cardId);
        if ($card === null) {
            return $this->json(['error' => SpaApiError::CARD_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $this->kanbanService->requireRole($card->getColumn()->getBoard(), $user, KanbanBoardMemberRole::KANBAN_EDITOR);

        $attachment = $this->attachmentRepo->find($id);
        if ($attachment === null || $attachment->getCard()->getId() !== $cardId) {
            return $this->json(['error' => SpaApiError::ATTACHMENT_NOT_FOUND], Response::HTTP_NOT_FOUND);
        }

        $filename = $attachment->getFilename();
        $context = $attachment->getContext();

        $this->attachmentService->delete($attachment);

        if ($context !== 'chat') {
            $this->activityLogger->logAttachmentRemoved($card, $filename);
        }

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array{
     *     id: int|null,
     *     filename: string,
     *     contentType: string,
     *     sizeBytes: int,
     *     context: string,
     *     createdAt: string|null,
     *     previewUrl: string|null,
     *     authorId: int|null,
     *     authorName: string|null
     * }
     */
    private function formatAttachment(KanbanAttachment $attachment): array
    {
        $author = $attachment->getAuthor();

        return [
            'id' => $attachment->getId(),
            'filename' => $attachment->getFilename(),
            'contentType' => $attachment->getContentType(),
            'sizeBytes' => $attachment->getSizeBytes(),
            'context' => $attachment->getContext(),
            'createdAt' => $attachment->getCreatedAt()?->format('c'),
            'previewUrl' => $this->kanbanAttachmentPreviewUrlGenerator->getPreviewUrl($attachment),
            'authorId' => $author?->getId(),
            'authorName' => $author !== null
                ? trim($author->getLastname() . ' ' . $author->getFirstname()) ?: null
                : null,
        ];
    }

    private function resolvePreviewFilePath(KanbanAttachment $attachment): ?string
    {
        $storageKey = $attachment->getStorageKey();
        if ($storageKey === '') {
            return null;
        }

        $this->filterService->warmUpCache($storageKey, self::PREVIEW_FILTER);

        $path = \sprintf(
            '%s/public/media/cache/%s/%s',
            $this->projectDir,
            self::PREVIEW_FILTER,
            $storageKey,
        );

        return is_file($path) ? $path : null;
    }
}
