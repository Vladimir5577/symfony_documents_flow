<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Enum\Purchase\PurchaseRequestKind;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Шаблон маршрута согласования — заготовка, которую ведёт отдел закупок.
 *
 * Стартовых шаблонов два: is_default_for = FAST и STANDARD, они применяются
 * автоматически по нажатой кнопке создания. Всё остальное (is_default_for = NULL) —
 * заготовки вроде «Маршрут с бухгалтерией»: автору не видны, применяются вручную
 * отделом закупок на их шаге.
 *
 * Диапазонов сумм у шаблона НЕТ: маршрут выбирается кнопкой, а не деньгами.
 * Единственное денежное правило — обязательный шаг директора от его порога,
 * и оно живёт в строителе поверх любого шаблона.
 *
 * Уникальность «не более одного active на FAST и на STANDARD» — условная
 * (только среди active), в схеме не выражается: проверяется при сохранении.
 */
#[ORM\Entity(repositoryClass: PurchaseRouteTemplateRepository::class)]
#[ORM\Index(columns: ['is_default_for', 'active'])]
class PurchaseRouteTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Название шаблона обязательно для заполнения.')]
    #[Assert\Length(max: 255, maxMessage: 'Название шаблона не должно превышать {{ limit }} символов.')]
    private ?string $name = null;

    // NULL — заготовка: применяется только вручную отделом закупок
    #[ORM\Column(name: 'is_default_for', type: Types::STRING, length: 20, nullable: true, enumType: PurchaseRequestKind::class)]
    private ?PurchaseRequestKind $isDefaultFor = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, PurchaseRouteTemplateStep> */
    #[ORM\OneToMany(mappedBy: 'template', targetEntity: PurchaseRouteTemplateStep::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $steps;

    public function __construct()
    {
        $this->steps = new ArrayCollection();
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

    public function getIsDefaultFor(): ?PurchaseRequestKind
    {
        return $this->isDefaultFor;
    }

    public function setIsDefaultFor(?PurchaseRequestKind $isDefaultFor): static
    {
        $this->isDefaultFor = $isDefaultFor;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return Collection<int, PurchaseRouteTemplateStep>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    public function addStep(PurchaseRouteTemplateStep $step): static
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setTemplate($this);
        }

        return $this;
    }

    public function removeStep(PurchaseRouteTemplateStep $step): static
    {
        $this->steps->removeElement($step);

        return $this;
    }
}
