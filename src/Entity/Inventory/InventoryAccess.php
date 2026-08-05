<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Repository\Inventory\InventoryAccessRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Привязка пользователя к организации — источник прав в модуле.
 *
 * category = null — админ инвентаризации организации, видит все категории.
 * category заполнена — ответственный за эту категорию.
 *
 * По наличию привязок AccessController выдаёт и снимает ROLE_INVENTORY_MANAGER,
 * но сама роль правами не является — их считает InventoryScope. Главному
 * администратору (ROLE_INVENTORY_ADMIN) привязки не нужны вовсе: он видит всё.
 *
 * Обе привязки распространяются на всю ветку дерева организаций
 * (см. OrganizationRepository::findOrganizationWithChildrenIds).
 */
#[ORM\Entity(repositoryClass: InventoryAccessRepository::class)]
#[ORM\Table(name: 'inventory_access')]
#[ORM\UniqueConstraint(
    name: 'uniq_inventory_access',
    columns: ['user_id', 'organization_id', 'category_id'],
)]
// В PostgreSQL NULL-ы в UNIQUE считаются различными, поэтому строки «админ организации»
// (category_id IS NULL) ограничение выше не разводит — нужен частичный индекс.
#[ORM\UniqueConstraint(
    name: 'uniq_inventory_access_org_admin',
    columns: ['user_id', 'organization_id'],
    options: ['where' => '(category_id IS NULL)'],
)]
class InventoryAccess
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private AbstractOrganization $organization;

    #[ORM\ManyToOne(targetEntity: ItemCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?ItemCategory $category = null;

    /**
     * Кто выдал привязку — главный администратор или админ организации.
     */
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

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getOrganization(): AbstractOrganization
    {
        return $this->organization;
    }

    public function setOrganization(AbstractOrganization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getCategory(): ?ItemCategory
    {
        return $this->category;
    }

    public function setCategory(?ItemCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    /**
     * Админ организации (все категории), а не ответственный за одну категорию.
     */
    public function isOrganizationAdmin(): bool
    {
        return $this->category === null;
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
}
