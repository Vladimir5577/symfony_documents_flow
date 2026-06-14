<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Post;

use App\Entity\Post\File as PostFile;
use App\Entity\Post\Post;
use App\Entity\Post\PostUserComment;
use App\Entity\User\User;
use App\Enum\Post\PostType;
use App\Enum\Post\PostUserStatusType;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class PostResponseFormatter
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly PostImagePreviewUrlGenerator $previewUrlGenerator,
    ) {
    }

    /**
     * @return array{
     *     id: int|null,
     *     title: string|null,
     *     type: array{value: string, label: string}|null,
     *     content: string|null,
     *     author: array{id: int|null, name: string}|null,
     *     isActive: bool,
     *     isRequiredAcknowledgment: bool,
     *     coverImageUrl: string|null,
     *     coverThumbnailUrl: string|null,
     *     createdAt: string|null,
     *     commentCount: int,
     *     userStatus: array{value: int, label: string}|null
     * }
     */
    public function formatPostListItem(
        Post $post,
        int $commentCount = 0,
        ?PostUserStatusType $userStatus = null,
    ): array {
        $author = $post->getAuthor();

        return [
            'id' => $post->getId(),
            'title' => $post->getTitle(),
            'type' => $this->formatType($post->getType()),
            'content' => $post->getContent(),
            'author' => $author !== null ? [
                'id' => $author->getId(),
                'name' => $this->formatUserFullName($author),
            ] : null,
            'isActive' => $post->isActive(),
            'isRequiredAcknowledgment' => $post->isRequiredAcknowledgment(),
            'coverImageUrl' => $this->buildCoverImageUrl($post),
            'coverThumbnailUrl' => $this->previewUrlGenerator->getCoverPreviewUrl($post),
            'createdAt' => $post->getCreatedAt()?->format('c'),
            'commentCount' => $commentCount,
            'userStatus' => $this->formatUserStatus($userStatus),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function formatPostDetail(Post $post, ?PostUserStatusType $userStatus = null, int $commentCount = 0): array
    {
        $data = $this->formatPostListItem($post, $commentCount, $userStatus);
        $data['files'] = array_map(
            fn (PostFile $file): array => $this->formatFile($file),
            $post->getFiles()->toArray(),
        );

        return $data;
    }

    /**
     * @return array{
     *     id: int|null,
     *     title: string|null,
     *     extension: string|null,
     *     downloadUrl: string|null,
     *     previewUrl: string|null
     * }
     */
    public function formatFile(PostFile $file): array
    {
        $filePath = $file->getFilePath();
        $extension = $filePath !== null ? (pathinfo($filePath, PATHINFO_EXTENSION) ?: null) : null;

        return [
            'id' => $file->getId(),
            'title' => $file->getTitle(),
            'extension' => $extension,
            'downloadUrl' => $file->getId() !== null
                ? $this->urlGenerator->generate('spa_api_posts_file_download', ['fileId' => $file->getId()])
                : null,
            'previewUrl' => $this->previewUrlGenerator->getFilePreviewUrl($file),
        ];
    }

    /**
     * @return array{
     *     id: int|null,
     *     content: string|null,
     *     author: array{id: int|null, name: string}|null,
     *     createdAt: string|null,
     *     updatedAt: string|null
     * }
     */
    public function formatComment(PostUserComment $comment): array
    {
        $author = $comment->getUser();

        return [
            'id' => $comment->getId(),
            'content' => $comment->getContent(),
            'author' => $author !== null ? [
                'id' => $author->getId(),
                'name' => $this->formatUserFullName($author),
            ] : null,
            'createdAt' => $comment->getCreatedAt()?->format('c'),
            'updatedAt' => $comment->getUpdatedAt()?->format('c'),
        ];
    }

    /**
     * @return array{current_page: int, total_pages: int, total_items: int, items_per_page: int}
     */
    public function formatPagination(int $page, int $limit, int $total): array
    {
        $totalPages = (int) max(1, ceil($total / $limit));

        return [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_items' => $total,
            'items_per_page' => $limit,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function formatTypeChoices(): array
    {
        return array_map(
            static fn (PostType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
            ],
            PostType::cases(),
        );
    }

    /**
     * @return array{value: string, label: string}|null
     */
    private function formatType(?PostType $type): ?array
    {
        if ($type === null) {
            return null;
        }

        return [
            'value' => $type->value,
            'label' => $type->label(),
        ];
    }

    /**
     * @return array{value: int, label: string}|null
     */
    private function formatUserStatus(?PostUserStatusType $status): ?array
    {
        if ($status === null) {
            return null;
        }

        return [
            'value' => $status->value,
            'label' => $status->label(),
        ];
    }

    private function buildCoverImageUrl(Post $post): ?string
    {
        $name = $post->getCoverImageName();
        if ($name === null || $name === '') {
            return null;
        }

        return '/uploads/posts/' . $post->getId() . '/' . $name;
    }

    private function formatUserFullName(User $user): string
    {
        $fullName = trim(sprintf(
            '%s %s %s',
            (string) $user->getLastname(),
            (string) $user->getFirstname(),
            (string) ($user->getPatronymic() ?? ''),
        ));

        return $fullName !== '' ? $fullName : (string) $user->getLogin();
    }
}
