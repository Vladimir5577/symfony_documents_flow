<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Entity\User\User;
use App\Repository\Purchase\PurchaseApproverRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Справочник согласантов закупок: кого директор может отметить в заявке.
 * Ведут админ и отдел закупок, порядок задают руками — в нём список и показывается.
 *
 * Шаги согласования ссылаются на User напрямую, поэтому удаление строки отсюда
 * не трогает заявки, где человек уже согласант: он просто больше не предлагается.
 */
#[ORM\Entity(repositoryClass: PurchaseApproverRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_purchase_approver_user', columns: ['user_id'])]
class PurchaseApprover
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /** Порядок показа. Дырки после удаления не мешают: сортировка идёт по значению, а не по номеру строки. */
    #[ORM\Column]
    private int $position = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

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
}
