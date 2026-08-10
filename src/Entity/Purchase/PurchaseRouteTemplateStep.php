<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Entity\User\User;
use App\Enum\Purchase\PurchaseApproverKind;
use App\Enum\Purchase\PurchaseFileType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Шаг шаблона маршрута.
 *
 * position — номер этапа: одинаковый у нескольких шагов означает параллельность
 * (ждём всех), разный — последовательность. Один шаг = одна требуемая подпись.
 */
#[ORM\Entity]
class PurchaseRouteTemplateStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseRouteTemplate::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(name: 'template_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRouteTemplate $template = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 1;

    #[ORM\Column(name: 'approver_kind', type: Types::STRING, length: 30, enumType: PurchaseApproverKind::class)]
    private PurchaseApproverKind $approverKind = PurchaseApproverKind::ROLE;

    // При ROLE — значение из enum UserRole; проверяется при сохранении шаблона,
    // там же контролируется, что у роли есть хотя бы один носитель
    #[ORM\Column(name: 'approver_role', type: Types::STRING, length: 50, nullable: true)]
    private ?string $approverRole = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approver_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approverUser = null;

    // Подпись шага в степпере: одна роль может стоять в маршруте дважды
    // («Отдел закупок» на рассмотрении и он же на договоре)
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    // Шаг нельзя закрыть, пока к заявке не приложен файл этого типа
    #[ORM\Column(name: 'requires_file_type', type: Types::STRING, length: 30, nullable: true, enumType: PurchaseFileType::class)]
    private ?PurchaseFileType $requiresFileType = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTemplate(): ?PurchaseRouteTemplate
    {
        return $this->template;
    }

    public function setTemplate(?PurchaseRouteTemplate $template): static
    {
        $this->template = $template;

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

    public function getApproverKind(): PurchaseApproverKind
    {
        return $this->approverKind;
    }

    public function setApproverKind(PurchaseApproverKind $approverKind): static
    {
        $this->approverKind = $approverKind;

        return $this;
    }

    public function getApproverRole(): ?string
    {
        return $this->approverRole;
    }

    public function setApproverRole(?string $approverRole): static
    {
        $this->approverRole = $approverRole;

        return $this;
    }

    public function getApproverUser(): ?User
    {
        return $this->approverUser;
    }

    public function setApproverUser(?User $approverUser): static
    {
        $this->approverUser = $approverUser;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

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
}
