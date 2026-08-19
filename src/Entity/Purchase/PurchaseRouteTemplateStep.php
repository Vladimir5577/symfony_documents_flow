<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Enum\Purchase\PurchaseApproverKind;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStepPurpose;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Шаг заготовки маршрута — «кого ждём и что на этом шаге делают».
 *
 * Позиция задаёт порядок, а не номер по счёту: одинаковая позиция у нескольких
 * шагов означает параллельные подписи (так стоят бухгалтерия и юристы), и
 * заявка уходит дальше, когда закрыта вся позиция. Дырки в нумерации допустимы —
 * указатель маршрута считается как минимальная незакрытая позиция.
 *
 * ROLE — шаг закроет любой носитель роли модуля. USER — это слот под профильных
 * замов: людей в него назначает директор на разборе, и до его решения неизвестно,
 * кто они и нужны ли вообще. Поэтому при подаче слот не превращается в шаг, а
 * только резервирует позицию (PurchaseRequest::approversPosition) — иначе
 * маршрут встал бы на шаге, который некому подписать.
 *
 * Назначение (purpose) — то, что спрашивает логика модуля: на SOURCING правят
 * поставщика и цены, его закрывший становится исполнителем, и на него возвращают
 * пакет документов; с TRIAGE правят состав и назначают замов. Роль отвечает
 * только за «кто подписывает», поэтому её можно менять, не ломая правила.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_route_template_step')]
#[ORM\Index(columns: ['template_id', 'position'])]
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

    #[ORM\Column(name: 'role_code', type: Types::STRING, length: 50, nullable: true, enumType: PurchaseRoleCode::class)]
    private ?PurchaseRoleCode $roleCode = null;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: PurchaseStepPurpose::class)]
    private PurchaseStepPurpose $purpose = PurchaseStepPurpose::SIGN_OFF;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    /** Без файла этого типа шаг не подписать: «нет договора — нет подписи». */
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

    public function getRoleCode(): ?PurchaseRoleCode
    {
        return $this->roleCode;
    }

    public function setRoleCode(?PurchaseRoleCode $roleCode): static
    {
        $this->roleCode = $roleCode;

        return $this;
    }

    public function getPurpose(): PurchaseStepPurpose
    {
        return $this->purpose;
    }

    public function setPurpose(PurchaseStepPurpose $purpose): static
    {
        $this->purpose = $purpose;

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

    /** Место под профильных замов, а не шаг: подписантов назначает директор. */
    public function isApproversSlot(): bool
    {
        return $this->approverKind === PurchaseApproverKind::USER;
    }

    /** Заголовок для заявки и превью: свой, иначе название роли. */
    public function resolveTitle(): string
    {
        return $this->title ?? $this->roleCode?->getLabel() ?? 'Согласование';
    }
}
