<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Entity\User\User;
use App\Enum\Purchase\PurchaseRoleCode;
use App\Repository\Purchase\PurchaseApproverRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Участник модуля закупок: кого директор может отметить в заявке и кто носит
 * роли модуля. Ведёт админ, порядок задаёт руками — в нём список и показывается.
 *
 * Раньше список вели админ и отдел закупок вместе. С появлением ролей это
 * перестало быть справочником и стало выдачей прав: отдел закупок мог бы
 * дописать себе любую функцию, поэтому список целиком ушёл админу.
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

    /**
     * Функции участника в модуле. Пустой список — человек только «кандидат в
     * согласанты»: директор может отметить его в заявке лично, но ролевые шаги
     * маршрута к нему не адресуются.
     *
     * @var Collection<int, PurchaseApproverRole>
     */
    #[ORM\OneToMany(targetEntity: PurchaseApproverRole::class, mappedBy: 'approver', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $roles;

    public function __construct()
    {
        $this->roles = new ArrayCollection();
    }

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

    /** @return Collection<int, PurchaseApproverRole> */
    public function getRoles(): Collection
    {
        return $this->roles;
    }

    public function addRole(PurchaseApproverRole $role): static
    {
        if (!$this->roles->contains($role)) {
            $this->roles->add($role);
            $role->setApprover($this);
        }

        return $this;
    }

    public function removeRole(PurchaseApproverRole $role): static
    {
        if ($this->roles->removeElement($role)) {
            $role->setApprover(null);
        }

        return $this;
    }

    /** Роли модуля, выданные этому участнику. @return list<PurchaseRoleCode> */
    public function getRoleCodes(): array
    {
        $codes = [];
        foreach ($this->roles as $link) {
            $code = $link->getRoleCode();
            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
