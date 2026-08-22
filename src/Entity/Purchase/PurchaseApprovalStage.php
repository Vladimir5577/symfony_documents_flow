<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseStageStatus;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Enum\Purchase\PurchaseTaskDecision;
use App\Repository\Purchase\PurchaseApprovalStageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Этап маршрута конкретной заявки — снимок этапа заготовки на момент подачи.
 *
 * Маршрут собирается один раз при подаче и замораживается: правка заготовки не
 * трогает заявки в пути. Поэтому здесь скопированы и название, и назначение, и
 * правило закрытия — заявка идёт по тому регламенту, который действовал, когда
 * её подали.
 *
 * Статус хранится, а не выводится из решений задач. Пока указатель считался на
 * месте, «этап закрылся» было решением, принятым в памяти процесса, и два
 * согласанта параллельного этапа, нажавшие одновременно, оба видели незакрытый
 * этап. Цена хранения — согласованность, поэтому статус меняет только
 * PurchaseApprovalWorkflow и только вместе с версией заявки.
 */
#[ORM\Entity(repositoryClass: PurchaseApprovalStageRepository::class)]
#[ORM\Table(name: 'purchase_approval_stage')]
#[ORM\UniqueConstraint(name: 'uniq_purchase_approval_stage_position', columns: ['purchase_request_id', 'position'])]
#[ORM\Index(columns: ['purchase_request_id', 'status'])]
class PurchaseApprovalStage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseRequest::class, inversedBy: 'stages')]
    #[ORM\JoinColumn(name: 'purchase_request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRequest $purchaseRequest = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: PurchaseStagePurpose::class)]
    private PurchaseStagePurpose $purpose = PurchaseStagePurpose::SIGN_OFF;

    #[ORM\Column(name: 'allows_reject', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $allowsReject = true;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: PurchaseStageStatus::class, options: ['default' => 'PENDING'])]
    private PurchaseStageStatus $status = PurchaseStageStatus::PENDING;

    /**
     * Пул, из которого разбирающий выбирает людей на этот этап. Заполнен только
     * у динамического этапа и переживает создание задач: по нему проверяют, что
     * выбранный человек вообще может стоять на этом этапе.
     */
    #[ORM\Column(name: 'candidate_role_code', type: Types::STRING, length: 50, nullable: true, enumType: PurchaseRoleCode::class)]
    private ?PurchaseRoleCode $candidateRoleCode = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'completed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, PurchaseApprovalTask> */
    #[ORM\OneToMany(
        mappedBy: 'stage',
        targetEntity: PurchaseApprovalTask::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $tasks;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurchaseRequest(): ?PurchaseRequest
    {
        return $this->purchaseRequest;
    }

    public function setPurchaseRequest(?PurchaseRequest $purchaseRequest): static
    {
        $this->purchaseRequest = $purchaseRequest;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title !== null && trim($title) !== '' ? trim($title) : null;

        return $this;
    }

    public function getPurpose(): PurchaseStagePurpose
    {
        return $this->purpose;
    }

    public function setPurpose(PurchaseStagePurpose $purpose): static
    {
        $this->purpose = $purpose;

        return $this;
    }

    public function allowsReject(): bool
    {
        return $this->allowsReject;
    }

    public function setAllowsReject(bool $allowsReject): static
    {
        $this->allowsReject = $allowsReject;

        return $this;
    }

    public function getStatus(): PurchaseStageStatus
    {
        return $this->status;
    }

    public function setStatus(PurchaseStageStatus $status): static
    {
        $this->status = $status;
        $this->startedAt = $status === PurchaseStageStatus::ACTIVE && $this->startedAt === null
            ? new \DateTimeImmutable()
            : $this->startedAt;
        $this->completedAt = $status->isClosed() ? new \DateTimeImmutable() : null;

        return $this;
    }

    public function getCandidateRoleCode(): ?PurchaseRoleCode
    {
        return $this->candidateRoleCode;
    }

    public function setCandidateRoleCode(?PurchaseRoleCode $candidateRoleCode): static
    {
        $this->candidateRoleCode = $candidateRoleCode;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, PurchaseApprovalTask> */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function addTask(PurchaseApprovalTask $task): static
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks->add($task);
            $task->setStage($this);
        }

        return $this;
    }

    public function removeTask(PurchaseApprovalTask $task): static
    {
        $this->tasks->removeElement($task);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->status === PurchaseStageStatus::ACTIVE;
    }

    public function isClosed(): bool
    {
        return $this->status->isClosed();
    }

    /** Этап ждёт, чтобы разбирающий выбрал на него людей. */
    public function isAwaitingAssignment(): bool
    {
        return $this->status === PurchaseStageStatus::AWAITING_ASSIGNMENT;
    }

    /** Этап заполняется людьми на разборе — задачи в нём появятся позже. */
    public function isDynamic(): bool
    {
        return $this->candidateRoleCode !== null;
    }

    /**
     * Все задачи этапа согласованы.
     *
     * Пустой этап закрытым не считается: динамический до назначения людей ждёт
     * разбора, а не проходит молча. Пропустить его — отдельное решение воркфлоу
     * (SKIPPED), принимаемое только когда разбирающий никого не выбрал.
     */
    public function isSatisfied(): bool
    {
        if ($this->tasks->isEmpty()) {
            return false;
        }

        foreach ($this->tasks as $task) {
            if ($task->getDecision() !== PurchaseTaskDecision::APPROVED) {
                return false;
            }
        }

        return true;
    }

    /**
     * Задачи этапа, ждущие решения.
     *
     * @return list<PurchaseApprovalTask>
     */
    public function getPendingTasks(): array
    {
        $pending = [];
        foreach ($this->tasks as $task) {
            if ($task->isPending()) {
                $pending[] = $task;
            }
        }

        return $pending;
    }

    /** Снять все решения этапа — при возврате в закупки и отзыве подписи. */
    public function resetTasks(): static
    {
        foreach ($this->tasks as $task) {
            $task->reset();
        }

        return $this;
    }

    /** Есть ли в этапе задача, которую закрывает автор заявки. */
    public function hasAuthorTask(): bool
    {
        foreach ($this->tasks as $task) {
            if ($task->getAssignmentType() === PurchaseTaskAssignment::AUTHOR) {
                return true;
            }
        }

        return false;
    }

    /** Заголовок для карточки: свой, иначе — из адресатов задач. */
    public function resolveTitle(): string
    {
        if ($this->title !== null) {
            return $this->title;
        }

        $titles = [];
        foreach ($this->tasks as $task) {
            $titles[] = $task->resolveTitle();
        }
        if ($titles === []) {
            return $this->candidateRoleCode?->getLabel() ?? $this->purpose->getLabel();
        }

        return implode(' и ', $titles);
    }
}
