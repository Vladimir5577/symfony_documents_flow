<?php

declare(strict_types=1);

namespace App\Enum\Purchase;

/**
 * Где заявка на маршруте — для фильтра списка.
 *
 * Считается из этапов в PurchaseApprovalWorkflow, а не запросом по каждой строке:
 * «первый этап или нет» иначе требует подзапрос на каждую заявку страницы.
 */
enum PurchaseProgressStatus: string
{
    /** Указатель на самом первом этапе. */
    case NEW = 'new';

    /** Указатель на любом этапе после первого. */
    case PROGRESS = 'progress';

    /** Непройденных этапов не осталось. */
    case DONE = 'done';
}
