<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRequestHistory;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseHistoryAction;
use App\Enum\Purchase\PurchaseStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Лента истории заявки.
 *
 * Вынесено из воркфлоу, потому что пишут в историю все: движение по маршруту,
 * правки содержимого, загрузка файлов, смена классификации. Пока запись жила
 * внутри одного сервиса, остальным приходилось звать его ради единственного
 * метода — и тянуть за собой весь движок маршрута.
 *
 * Flush не делается: запись истории всегда часть чужой транзакции, и коммитить
 * её отдельно значит получить в ленте событие, которого не было.
 */
final class PurchaseHistoryLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Событие без смены статуса — самый частый случай. */
    public function log(
        PurchaseRequest $request,
        User $actor,
        PurchaseHistoryAction $action,
        ?string $comment = null,
    ): void {
        $this->add($request, $actor, $request->getStatus(), $request->getStatus(), $action, $comment);
    }

    /** Событие со сменой статуса: from заполняется тем, что было до вызова. */
    public function logTransition(
        PurchaseRequest $request,
        User $actor,
        ?PurchaseStatus $from,
        PurchaseStatus $to,
        PurchaseHistoryAction $action,
        ?string $comment = null,
    ): void {
        $this->add($request, $actor, $from, $to, $action, $comment);
    }

    /** Строка истории о действии по задаче: чья задача и что человек написал. */
    public function taskComment(PurchaseApprovalTask $task, ?string $comment): string
    {
        $description = $task->resolveTitle();
        $comment = self::normalizeComment($comment);

        return $comment === null ? $description : $description . ' — ' . $comment;
    }

    public static function nameOf(User $user): string
    {
        $name = trim(($user->getLastname() ?? '') . ' ' . ($user->getFirstname() ?? ''));

        return $name !== '' ? $name : (string) $user->getLogin();
    }

    public static function normalizeComment(?string $comment): ?string
    {
        return $comment !== null && trim($comment) !== '' ? $comment : null;
    }

    private function add(
        PurchaseRequest $request,
        User $actor,
        ?PurchaseStatus $from,
        PurchaseStatus $to,
        PurchaseHistoryAction $action,
        ?string $comment,
    ): void {
        $entry = (new PurchaseRequestHistory())
            ->setUser($actor)
            ->setAction($action)
            ->setFromStatus($from)
            ->setToStatus($to)
            ->setComment(self::normalizeComment($comment));

        $request->addHistory($entry);
        $this->em->persist($entry);
    }
}
