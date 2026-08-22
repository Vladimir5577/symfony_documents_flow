<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseTaskAssignment;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Задача этапа заготовки — «кого ждём на этом шаге».
 *
 * Задача заготовки описывает адресата, а не человека: людей поимённо в регламент
 * не вписывают, они появляются только в снимке заявки. Отсюда и разделение
 * назначений — роль модуля, пул для выбора на разборе или автор заявки.
 *
 * Никаких решений здесь нет: решение, подписант и время живут в
 * PurchaseApprovalTask, у конкретной заявки. Заготовка — это регламент, а не
 * работа по нему.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_route_template_task')]
#[ORM\Index(columns: ['stage_id', 'position'])]
class PurchaseRouteTemplateTask
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseRouteTemplateStage::class, inversedBy: 'tasks')]
    #[ORM\JoinColumn(name: 'stage_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRouteTemplateStage $stage = null;

    /**
     * Порядок внутри этапа — только для показа.
     *
     * Очерёдность согласования задают этапы; задачи одного этапа параллельны, и
     * ждать их можно в любом порядке.
     */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 1;

    #[ORM\Column(name: 'assignment_type', type: Types::STRING, length: 20, enumType: PurchaseTaskAssignment::class)]
    private PurchaseTaskAssignment $assignmentType = PurchaseTaskAssignment::ROLE;

    /** Кого ждём, когда задача адресована роли модуля. */
    #[ORM\Column(name: 'role_code', type: Types::STRING, length: 50, nullable: true, enumType: PurchaseRoleCode::class)]
    private ?PurchaseRoleCode $roleCode = null;

    /** Пул, из которого разбирающий выбирает людей на динамическую задачу. */
    #[ORM\Column(name: 'candidate_role_code', type: Types::STRING, length: 50, nullable: true, enumType: PurchaseRoleCode::class)]
    private ?PurchaseRoleCode $candidateRoleCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    /** Без этого файла задачу не закрыть. */
    #[ORM\Column(name: 'requires_file_type', type: Types::STRING, length: 30, nullable: true, enumType: PurchaseFileType::class)]
    private ?PurchaseFileType $requiresFileType = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStage(): ?PurchaseRouteTemplateStage
    {
        return $this->stage;
    }

    public function setStage(?PurchaseRouteTemplateStage $stage): static
    {
        $this->stage = $stage;

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

    public function setRoleCode(?PurchaseRoleCode $roleCode): static
    {
        $this->roleCode = $roleCode;

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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $title = $title !== null ? trim($title) : null;
        $this->title = $title !== '' ? $title : null;

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

    /** Люди в этой задаче появятся только после выбора на разборе. */
    public function isDynamic(): bool
    {
        return $this->assignmentType === PurchaseTaskAssignment::DYNAMIC_USERS;
    }

    /** Заголовок для карточки и превью: свой, иначе — по адресату. */
    public function resolveTitle(): string
    {
        if ($this->title !== null) {
            return $this->title;
        }

        return match ($this->assignmentType) {
            PurchaseTaskAssignment::ROLE => $this->roleCode?->getLabel() ?? 'Согласование',
            PurchaseTaskAssignment::DYNAMIC_USERS => $this->candidateRoleCode?->getLabel() ?? 'Согласование',
            PurchaseTaskAssignment::AUTHOR => 'Заявитель',
            PurchaseTaskAssignment::USER => 'Согласование',
        };
    }
}
