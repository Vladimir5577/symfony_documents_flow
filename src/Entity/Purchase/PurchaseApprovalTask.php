<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseTaskAssignment;
use App\Enum\Purchase\PurchaseTaskDecision;
use App\Repository\Purchase\PurchaseApprovalTaskRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Задача этапа конкретной заявки. Одна строка = одна требуемая подпись.
 *
 * Роль, автор или конкретный человек отвечают на вопрос «кого ждали», decidedBy —
 * «кто фактически нажал». У ролевой задачи assigneeUser так и остаётся пустым:
 * ждали любого носителя роли, и затирать это подписантом нельзя, иначе теряется
 * первый вопрос.
 *
 * Роль здесь — роль модуля (PurchaseRoleCode), а не Symfony-роль: носителей
 * назначает админ в участниках модуля, и маршрут не должен зависеть от того, что
 * записано в security.yaml.
 */
#[ORM\Entity(repositoryClass: PurchaseApprovalTaskRepository::class)]
#[ORM\Table(name: 'purchase_approval_task')]
#[ORM\Index(columns: ['stage_id', 'position'])]
#[ORM\Index(columns: ['assignee_user_id', 'decision'])]
#[ORM\Index(columns: ['role_code', 'decision'])]
class PurchaseApprovalTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseApprovalStage::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(name: 'stage_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseApprovalStage $stage = null;

    /** Порядок показа внутри этапа: очерёдность задают этапы, не задачи. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 1;

    #[ORM\Column(name: 'assignment_type', type: Types::STRING, length: 30, enumType: PurchaseTaskAssignment::class)]
    private PurchaseTaskAssignment $assignmentType = PurchaseTaskAssignment::ROLE;

    #[ORM\Column(name: 'role_code', type: Types::STRING, length: 50, nullable: true, enumType: PurchaseRoleCode::class)]
    private ?PurchaseRoleCode $roleCode = null;

    /**
     * Название роли на момент подачи. Снимок, а не удобство: роль переименуют или
     * уберут из enum, а история подписанных заявок должна остаться читаемой —
     * «подписала Бухгалтерия», даже если роль давно называется иначе.
     */
    #[ORM\Column(name: 'role_name', type: Types::STRING, length: 100, nullable: true)]
    private ?string $roleName = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assignee_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $assigneeUser = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(name: 'requires_file_type', type: Types::STRING, length: 30, nullable: true, enumType: PurchaseFileType::class)]
    private ?PurchaseFileType $requiresFileType = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PurchaseTaskDecision::class, options: ['default' => 'PENDING'])]
    private PurchaseTaskDecision $decision = PurchaseTaskDecision::PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** Кто добавил задачу; NULL — пришла из заготовки при подаче. */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStage(): ?PurchaseApprovalStage
    {
        return $this->stage;
    }

    public function setStage(?PurchaseApprovalStage $stage): static
    {
        $this->stage = $stage;

        return $this;
    }

    public function getPurchaseRequest(): ?PurchaseRequest
    {
        return $this->stage?->getPurchaseRequest();
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

    public function getAssignmentType(): PurchaseTaskAssignment
    {
        return $this->assignmentType;
    }

    public function setAssignmentType(PurchaseTaskAssignment $assignmentType): static
    {
        $this->assignmentType = $assignmentType;

        return $this;
    }

    public function getRoleCode(): ?PurchaseRoleCode
    {
        return $this->roleCode;
    }

    /** Снимок названия обновляется вместе с кодом: задача ждёт роль в её нынешнем виде. */
    public function setRoleCode(?PurchaseRoleCode $roleCode): static
    {
        $this->roleCode = $roleCode;
        $this->roleName = $roleCode?->getLabel();

        return $this;
    }

    public function getRoleName(): ?string
    {
        return $this->roleName;
    }

    public function getAssigneeUser(): ?User
    {
        return $this->assigneeUser;
    }

    public function setAssigneeUser(?User $assigneeUser): static
    {
        $this->assigneeUser = $assigneeUser;

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

    public function getRequiresFileType(): ?PurchaseFileType
    {
        return $this->requiresFileType;
    }

    public function setRequiresFileType(?PurchaseFileType $requiresFileType): static
    {
        $this->requiresFileType = $requiresFileType;

        return $this;
    }

    public function getDecision(): PurchaseTaskDecision
    {
        return $this->decision;
    }

    public function setDecision(PurchaseTaskDecision $decision): static
    {
        $this->decision = $decision;

        return $this;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): static
    {
        $this->decidedBy = $decidedBy;

        return $this;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isPending(): bool
    {
        return $this->decision === PurchaseTaskDecision::PENDING;
    }

    /** Снять решение — при возврате в закупки и отзыве подписи. */
    public function reset(): static
    {
        $this->decision = PurchaseTaskDecision::PENDING;
        $this->decidedBy = null;
        $this->decidedAt = null;
        $this->comment = null;

        return $this;
    }

    public function decide(PurchaseTaskDecision $decision, User $actor, ?string $comment = null): static
    {
        $this->decision = $decision;
        $this->decidedBy = $actor;
        $this->decidedAt = new \DateTimeImmutable();
        $this->comment = $comment !== null && trim($comment) !== '' ? $comment : null;

        return $this;
    }

    /**
     * Задача адресована лично этому человеку — конкретному сотруднику или автору
     * заявки. Ролевые задачи сюда не попадают: право на них проверяет
     * PurchaseAccess::canActOn().
     */
    public function isAddressedTo(User $user): bool
    {
        if ($this->assignmentType === PurchaseTaskAssignment::AUTHOR) {
            return $this->getPurchaseRequest()?->getCreatedBy()?->getId() === $user->getId();
        }

        return $this->assigneeUser !== null && $this->assigneeUser->getId() === $user->getId();
    }

    /** Как задача называется в карточке и в истории. */
    public function resolveTitle(): string
    {
        if ($this->title !== null) {
            return $this->title;
        }
        if ($this->assignmentType === PurchaseTaskAssignment::AUTHOR) {
            return 'Заявитель';
        }
        if ($this->assigneeUser !== null) {
            $name = trim(($this->assigneeUser->getLastname() ?? '') . ' ' . ($this->assigneeUser->getFirstname() ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return $this->roleName ?? 'Согласование';
    }
}
