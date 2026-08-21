<?php

declare(strict_types=1);

namespace App\Service\Purchase;

use App\Entity\Purchase\PurchaseApprovalStage;
use App\Entity\Purchase\PurchaseApprovalTask;
use App\Entity\Purchase\PurchaseRequest;
use App\Entity\Purchase\PurchaseRouteTemplate;
use App\Entity\Purchase\PurchaseRouteTemplateStage;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseStageStatus;
use App\Enum\Purchase\PurchaseTaskAssignment;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Снимок маршрута заявки: собирается из заготовки при подаче и дальше живёт
 * отдельно от неё.
 *
 * Правка заготовки не трогает заявки в пути, и версий заготовке не нужно: снимок
 * и есть версия, по которой пошла заявка. Повторная подача после доработки
 * собирает снимок заново — по нынешней заготовке, а не по той, что действовала в
 * первый раз: состав и сумма к тому времени изменились, и согласовывать надо то,
 * что есть.
 *
 * Люди в динамический этап при сборке не попадают: до решения разбирающего
 * неизвестно, кто они и нужны ли вообще. Такой этап создаётся пустым со статусом
 * AWAITING_ASSIGNMENT — он виден в карточке как «ожидает назначения», и вставка
 * людей в середину маршрута не двигает соседние этапы.
 *
 * Flush — на вызывающей стороне.
 */
final class ApprovalRouteBuilder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Построить снимок заявки по заготовке, снеся предыдущий.
     *
     * Заготовку выбирает вызывающий через ApprovalRouteResolver: сборщик не
     * решает, какой маршрут применить, он только переносит его в заявку.
     */
    public function build(PurchaseRequest $request, PurchaseRouteTemplate $template): void
    {
        foreach ($request->getStages()->toArray() as $stage) {
            $request->removeStage($stage);
            $this->em->remove($stage);
        }

        $request->setAppliedRouteTemplate($template);

        foreach ($template->getStages() as $templateStage) {
            $stage = $this->copyStage($templateStage);
            $request->addStage($stage);
            $this->em->persist($stage);

            foreach ($templateStage->getTasks() as $templateTask) {
                // Динамическая задача — это место, а не адрес: людей в него
                // выберет разбирающий, и до тех пор задачи здесь нет.
                if ($templateTask->isDynamic()) {
                    continue;
                }

                $task = (new PurchaseApprovalTask())
                    ->setPosition($templateTask->getPosition())
                    ->setAssignmentType($templateTask->getAssignmentType())
                    ->setRoleCode($templateTask->getRoleCode())
                    ->setTitle($templateTask->getTitle())
                    ->setRequiresFileType($templateTask->getRequiresFileType());

                $stage->addTask($task);
                $this->em->persist($task);
            }

            if ($stage->getTasks()->isEmpty()) {
                $stage->setStatus(PurchaseStageStatus::AWAITING_ASSIGNMENT);
            }
        }
    }

    /**
     * Повесить на динамический этап людей, отмеченных разбирающим.
     *
     * Автор в список не попадает: сам себе согласантом человек не бывает, а
     * заявка на нём бы и застряла. Дубли склеиваются — двумя подписями один
     * человек не подписывает.
     *
     * Что каждый выбранный входит в пул этапа, проверяет вызывающий: пул — это
     * состав людей, и спрашивать его надо у ростера, а не у сборщика.
     *
     * @param list<User> $users
     * @return list<User> кого реально повесили — им уходит уведомление о назначении
     */
    public function assign(PurchaseApprovalStage $stage, array $users, User $actor): array
    {
        $authorId = $stage->getPurchaseRequest()?->getCreatedBy()?->getId();
        $assigned = [];
        $seen = [];
        $position = 0;

        foreach ($users as $user) {
            $userId = $user->getId();
            if ($userId === null || $userId === $authorId || isset($seen[$userId])) {
                continue;
            }
            $seen[$userId] = true;

            $task = (new PurchaseApprovalTask())
                ->setPosition(++$position)
                ->setAssignmentType(PurchaseTaskAssignment::USER)
                ->setAssigneeUser($user)
                ->setCreatedBy($actor);

            $stage->addTask($task);
            $this->em->persist($task);
            $assigned[] = $user;
        }

        return $assigned;
    }

    /**
     * Превью маршрута для формы создания: что получится по этой заготовке.
     * Считает бэк, чтобы фронт не дублировал правила.
     *
     * @return list<array{position: int, title: string, purpose: string, dynamic: bool}>
     */
    public function preview(PurchaseRouteTemplate $template): array
    {
        $preview = [];

        foreach ($template->getStages() as $stage) {
            $title = $stage->resolveTitle();
            if ($stage->isDynamic()) {
                $title .= ' (назначает директор)';
            }

            $preview[] = [
                'position' => $stage->getPosition(),
                'title' => $title,
                'purpose' => $stage->getPurpose()->value,
                'dynamic' => $stage->isDynamic(),
            ];
        }

        return $preview;
    }

    private function copyStage(PurchaseRouteTemplateStage $templateStage): PurchaseApprovalStage
    {
        return (new PurchaseApprovalStage())
            ->setPosition($templateStage->getPosition())
            ->setTitle($templateStage->getTitle())
            ->setPurpose($templateStage->getPurpose())
            ->setAllowsReject($templateStage->allowsReject())
            ->setCandidateRoleCode($templateStage->getCandidateRoleCode());
    }
}
