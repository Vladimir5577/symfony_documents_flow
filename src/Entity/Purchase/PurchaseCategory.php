<?php

namespace App\Entity\Purchase;

use App\Repository\Purchase\PurchaseCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Справочник категорий закупок (канцелярия, оргтехника, мебель…).
 * Ведёт отдел закупок.
 */
#[ORM\Entity(repositoryClass: PurchaseCategoryRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_purchase_category_name', columns: ['name'])]
class PurchaseCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Название категории обязательно для заполнения.')]
    #[Assert\Length(max: 255, maxMessage: 'Название категории не должно превышать {{ limit }} символов.')]
    private ?string $name = null;

    /** @var Collection<int, PurchaseCategoryItem> */
    #[ORM\OneToMany(mappedBy: 'category', targetEntity: PurchaseCategoryItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['name' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Collection<int, PurchaseCategoryItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(PurchaseCategoryItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setCategory($this);
        }

        return $this;
    }

    public function removeItem(PurchaseCategoryItem $item): static
    {
        $this->items->removeElement($item);

        return $this;
    }
}
