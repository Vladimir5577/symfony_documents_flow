<?php

declare(strict_types=1);

namespace App\Service\SpaApi\Documents;

use App\Entity\Document\Document;
use App\Entity\User\User;
use App\Enum\Document\DocumentStatus;
use App\Service\Notification\NotificationPublisher;

/**
 * Уведомления документооборота: тексты и ссылки в одном месте, отправка — через
 * общий NotificationPublisher.
 *
 * Раньше документооборот звал локальный NotificationService, который писал
 * строки в таблицу notification монолита. Колокольчик портала эту таблицу давно
 * не читает — его обслуживает go_notification_service_document_flow со своей
 * базой, — поэтому уведомления по документам создавались, но пользователю не
 * показывались. Ни ошибки, ни следа.
 *
 * Ссылки здесь СРАЗУ маршруты SPA. Прежде монолит отдавал свои Twig-маршруты
 * (/view_incoming_document/{id}), а сервис нотификаций переписывал их в
 * /document-in?doc={id}. Это была компенсация чужой ошибки на приёмной
 * стороне, и вместе с общим контрактом она удалена: маршрут знает тот, кто
 * уведомление создаёт.
 */
final class DocumentNotifier
{
    private const MODULE = 'document';

    /** Якорь на блок комментариев в карточке документа. */
    private const COMMENTS_ANCHOR = '#document-comments';

    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    /**
     * Документ пришёл получателям — входящий.
     *
     * @param iterable<User> $recipients
     */
    public function notifyIncoming(Document $document, iterable $recipients, ?User $actor = null): void
    {
        $documentId = $document->getId();
        if ($documentId === null) {
            return;
        }

        $this->publisher->publish(
            module: self::MODULE,
            event: 'incoming',
            recipients: $recipients,
            title: 'Новый входящий документ: ' . (string) $document->getName(),
            link: $this->incomingLink($documentId),
            actor: $actor,
            typeLabel: 'Новый входящий документ',
        );
    }

    /**
     * Комментарий к документу.
     *
     * @param iterable<User> $recipients
     */
    public function notifyCommentAdded(Document $document, User $author, iterable $recipients): void
    {
        $authorName = trim((string) $author->getLastname() . ' ' . (string) $author->getFirstname());
        if ($authorName === '') {
            $authorName = (string) $author->getLogin();
        }

        $this->publishSplitByRole(
            document: $document,
            event: 'comment_added',
            recipients: $recipients,
            title: $authorName . ' оставил комментарий к документу ' . (string) $document->getName(),
            actor: $author,
            typeLabel: 'Новый комментарий к документу',
            anchor: self::COMMENTS_ANCHOR,
        );
    }

    /**
     * Получатель сменил статус документа.
     *
     * @param iterable<User> $recipients
     */
    public function notifyStatusChanged(
        Document $document,
        User $actor,
        DocumentStatus $status,
        iterable $recipients,
    ): void {
        $actorName = trim(sprintf(
            '%s %s %s',
            (string) $actor->getLastname(),
            (string) $actor->getFirstname(),
            (string) ($actor->getPatronymic() ?? ''),
        ));
        if ($actorName === '') {
            $actorName = 'Пользователь';
        }

        $this->publishSplitByRole(
            document: $document,
            event: 'status_changed',
            recipients: $recipients,
            title: sprintf(
                '%s изменил статус документа «%s» на «%s».',
                $actorName,
                (string) $document->getName(),
                $status->getLabel(),
            ),
            actor: $actor,
            typeLabel: 'Статус документа изменён',
        );
    }

    /**
     * Автор документа смотрит его как исходящий, остальные — как входящий,
     * и ссылки у них разные. В контракте ссылка одна на событие, поэтому это
     * честно два разных события, а не одно с ветвлением внутри.
     *
     * @param iterable<User> $recipients
     */
    private function publishSplitByRole(
        Document $document,
        string $event,
        iterable $recipients,
        string $title,
        User $actor,
        string $typeLabel,
        string $anchor = '',
    ): void {
        $documentId = $document->getId();
        if ($documentId === null) {
            return;
        }

        $creatorId = $document->getCreatedBy()?->getId();

        $creator = [];
        $others = [];
        foreach ($recipients as $recipient) {
            if ($creatorId !== null && $recipient->getId() === $creatorId) {
                $creator[] = $recipient;
            } else {
                $others[] = $recipient;
            }
        }

        if ($creator !== []) {
            $this->publisher->publish(
                module: self::MODULE,
                event: $event,
                recipients: $creator,
                title: $title,
                link: $this->outgoingLink($documentId) . $anchor,
                actor: $actor,
                typeLabel: $typeLabel,
            );
        }

        if ($others !== []) {
            $this->publisher->publish(
                module: self::MODULE,
                event: $event,
                recipients: $others,
                title: $title,
                link: $this->incomingLink($documentId) . $anchor,
                actor: $actor,
                typeLabel: $typeLabel,
            );
        }
    }

    private function incomingLink(int $documentId): string
    {
        return '/document-in?doc=' . $documentId;
    }

    private function outgoingLink(int $documentId): string
    {
        return '/document-out?doc=' . $documentId;
    }
}
