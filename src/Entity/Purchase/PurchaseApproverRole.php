<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Enum\Purchase\PurchaseRoleCode;
use App\Repository\Purchase\PurchaseApproverRoleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Функция участника модуля: «этот человек — бухгалтерия закупок». Выдаёт админ.
 *
 * Отдельная строка на каждую функцию, потому что один человек нередко и
 * юрист, и финансовый контроль, а в маленькой организации — сразу всё.
 *
 * Привязка идёт к участнику (PurchaseApprover), а не напрямую к пользователю:
 * список участников модуля один, и функции — его продолжение, а не второй
 * параллельный справочник людей. Удалили участника — ушли и его функции.
 *
 * Роль хранится кодом: справочника ролей нет, состав ролей задаёт
 * PurchaseRoleCode. Неизвестный код возможен только после удаления case из
 * enum — такую строку роутер и гейты просто не увидят.
 */
#[ORM\Entity(repositoryClass: PurchaseApproverRoleRepository::class)]
#[ORM\Table(name: 'purchase_approver_role')]
#[ORM\UniqueConstraint(name: 'uniq_purchase_approver_role', columns: ['approver_id', 'role_code'])]
class PurchaseApproverRole
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseApprover::class, inversedBy: 'roles')]
    #[ORM\JoinColumn(name: 'approver_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseApprover $approver = null;

    #[ORM\Column(name: 'role_code', type: Types::STRING, length: 50, enumType: PurchaseRoleCode::class)]
    private ?PurchaseRoleCode $roleCode = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getApprover(): ?PurchaseApprover
    {
        return $this->approver;
    }

    public function setApprover(?PurchaseApprover $approver): static
    {
        $this->approver = $approver;

        return $this;
    }

    public function getRoleCode(): ?PurchaseRoleCode
    {
        return $this->roleCode;
    }

    public function setRoleCode(PurchaseRoleCode $roleCode): static
    {
        $this->roleCode = $roleCode;

        return $this;
    }
}
