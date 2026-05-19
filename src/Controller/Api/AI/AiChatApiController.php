<?php

declare(strict_types=1);

namespace App\Controller\Api\AI;

use App\Entity\AI\AiChatAttachment;
use App\Entity\AI\AiChatMessage;
use App\Entity\User\User;
use App\Exception\AI\RateLimitException;
use App\Repository\AI\AiChatMessageRepository;
use App\Service\AI\AnthropicClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/ai-chat')]
final class AiChatApiController extends AbstractController
{
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;   // 5 MB на картинку (Anthropic limit)
    private const MAX_PDF_BYTES   = 10 * 1024 * 1024;  // 10 MB на PDF (наш PHP-лимит)
    private const MAX_FILES_PER_REQUEST = 10;

    private const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(
        private readonly AnthropicClient $anthropic,
        private readonly EntityManagerInterface $em,
        private readonly AiChatMessageRepository $messageRepo,
        private readonly int $historyLimit,
        private readonly string $privateUploadDirAi,
    ) {
    }

    #[Route('/send', name: 'api_ai_chat_send', methods: ['POST'])]
    public function send(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $message = trim((string) $request->request->get('message', ''));
        // Для имени поля `attachments[]` Symfony FileBag::all($key) возвращает массив UploadedFile.
        $files = $request->files->all('attachments');

        if ($message === '' && count($files) === 0) {
            return $this->json(['error' => 'Сообщение пустое'], 400);
        }
        if (count($files) > self::MAX_FILES_PER_REQUEST) {
            return $this->json(['error' => 'Слишком много файлов (макс ' . self::MAX_FILES_PER_REQUEST . ')'], 400);
        }

        // Сначала валидируем файлы и собираем content-blocks для API. Если что-то не так — выходим
        // до того, как успели создать запись в БД и записать что-либо на диск.
        $currentContent = [];
        if ($message !== '') {
            $currentContent[] = ['type' => 'text', 'text' => $message];
        }
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                return $this->json(['error' => 'Один из файлов не загрузился корректно'], 400);
            }
            $block = $this->fileToContentBlock($file);
            if (is_string($block)) {
                return $this->json(['error' => $block], 400);
            }
            $currentContent[] = $block;
        }

        // История для контекста — последние N COMPLETED сообщений (failed туда не идут).
        // Тянем ДО сохранения нового user-сообщения, чтобы оно само в history не попало.
        $historyEntities = $this->messageRepo->findRecentForUser($user, $this->historyLimit, onlyCompleted: true);

        $apiMessages = [];
        foreach ($historyEntities as $h) {
            $apiMessages[] = [
                'role'    => $h->getRole(),
                'content' => $h->getContent(),
            ];
        }
        $apiMessages[] = [
            'role'    => 'user',
            'content' => count($files) === 0 ? $message : $currentContent,
        ];

        // Сохраняем user-сообщение со статусом pending. Сам текст идёт в content,
        // file-метаданные — в attachments через Vich (он сам положит файлы на диск при flush).
        $userMsg = (new AiChatMessage())
            ->setUser($user)
            ->setRole(AiChatMessage::ROLE_USER)
            ->setContent($message)
            ->setStatus(AiChatMessage::STATUS_PENDING);

        foreach ($files as $file) {
            $att = (new AiChatAttachment())
                ->setTitle($file->getClientOriginalName())
                ->setContentType($file->getMimeType())
                ->setSizeBytes($file->getSize());
            $att->setFile($file);
            $userMsg->addAttachment($att);
        }

        $this->em->persist($userMsg);
        $this->em->flush();

        // Зовём API. На этом этапе user-сообщение уже в БД с status=pending —
        // если упадём, оно останется и юзер увидит свой запрос (в статусе failed).
        try {
            $response = $this->anthropic->sendMessage($apiMessages);
        } catch (RateLimitException $e) {
            $userMsg
                ->setStatus(AiChatMessage::STATUS_FAILED)
                ->setErrorCode('rate_limit');
            $this->em->flush();

            return $this->json([
                'error'        => $e->getMessage(),
                'retry_after'  => $e->retryAfter,
                'user_message' => $this->serializeMessage($userMsg),
            ], 429);
        } catch (\Throwable $e) {
            $userMsg
                ->setStatus(AiChatMessage::STATUS_FAILED)
                ->setErrorCode('api_error');
            $this->em->flush();

            return $this->json([
                'error'        => $e->getMessage(),
                'user_message' => $this->serializeMessage($userMsg),
            ], 502);
        }

        // Успех: user-сообщение помечаем completed, assistant-сообщение сохраняем рядом.
        $userMsg->setStatus(AiChatMessage::STATUS_COMPLETED);

        $assistantMsg = (new AiChatMessage())
            ->setUser($user)
            ->setRole(AiChatMessage::ROLE_ASSISTANT)
            ->setContent($response->text)
            ->setStatus(AiChatMessage::STATUS_COMPLETED)
            ->setModel($response->model)
            ->setTokensIn($response->tokensIn)
            ->setTokensOut($response->tokensOut);

        $this->em->persist($assistantMsg);
        $this->em->flush();

        return $this->json([
            'user_message'      => $this->serializeMessage($userMsg),
            'assistant_message' => $this->serializeMessage($assistantMsg),
        ]);
    }

    #[Route('', name: 'api_ai_chat_clear', methods: ['DELETE'])]
    public function clear(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $count = $this->messageRepo->deleteAllForUser($user);

        return $this->json(['cleared' => true, 'count' => $count]);
    }

    #[Route('/attachments/{id}', name: 'api_ai_chat_attachment_download', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function downloadAttachment(int $id): BinaryFileResponse|JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $attachment = $this->em->getRepository(AiChatAttachment::class)->find($id);
        if ($attachment === null || $attachment->getMessage() === null) {
            return $this->json(['error' => 'Файл не найден'], 404);
        }
        // Проверка владения: только автор родительского сообщения может скачать аттачмент.
        if ($attachment->getMessage()->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Доступ запрещён'], 403);
        }
        if ($attachment->getFilePath() === null) {
            return $this->json(['error' => 'Файл недоступен'], 404);
        }

        $userId  = $attachment->getMessage()->getUser()->getId();
        // Реальный путь: <upload_root>/<user_id>/<filename> — формирует Vich по PropertyDirectoryNamer.
        $diskPath = rtrim($this->privateUploadDirAi, '/') . '/' . $userId . '/' . $attachment->getFilePath();

        if (!is_file($diskPath) || !is_readable($diskPath)) {
            return $this->json(['error' => 'Файл потерян на диске'], 404);
        }

        $response = new BinaryFileResponse($diskPath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $attachment->getTitle() ?? ('file-' . $attachment->getId())
        );
        if ($attachment->getContentType() !== null) {
            $response->headers->set('Content-Type', $attachment->getContentType());
        }
        return $response;
    }

    /**
     * @return array<string, mixed>|string Содержательный блок или текст ошибки.
     */
    private function fileToContentBlock(UploadedFile $file): array|string
    {
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();
        $name = (string) $file->getClientOriginalName();

        $isImage = in_array($mime, self::ALLOWED_IMAGE_MIMES, true);
        $isPdf   = $mime === 'application/pdf';

        if (!$isImage && !$isPdf) {
            return 'Тип файла не поддерживается (' . $mime . '): ' . $name;
        }

        $limit = $isImage ? self::MAX_IMAGE_BYTES : self::MAX_PDF_BYTES;
        if ($size > $limit) {
            return sprintf(
                'Файл слишком большой (макс %d МБ): %s',
                (int) ($limit / 1024 / 1024),
                $name
            );
        }

        $data = @file_get_contents($file->getPathname());
        if ($data === false) {
            return 'Не удалось прочитать файл: ' . $name;
        }

        return [
            'type'   => $isImage ? 'image' : 'document',
            'source' => [
                'type'       => 'base64',
                'media_type' => $mime,
                'data'       => base64_encode($data),
            ],
        ];
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
            'created_at'  => $msg->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'attachments' => array_map(
                fn(AiChatAttachment $a) => $this->serializeAttachment($a),
                $msg->getAttachments()->toArray()
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAttachment(AiChatAttachment $a): array
    {
        return [
            'id'           => $a->getId(),
            'title'        => $a->getTitle(),
            'content_type' => $a->getContentType(),
            'size_bytes'   => $a->getSizeBytes(),
            'is_image'     => $a->isImage(),
            'url'          => $this->generateUrl('api_ai_chat_attachment_download', ['id' => $a->getId()]),
        ];
    }
}
