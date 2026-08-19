<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Entity\User\User;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Заготовка маршрута: по какой цепочке пойдут новые заявки этого вида.
 *
 * Одна заготовка на вид заявки — быстрая и обычная. Выбора при подаче нет:
 * маршрут определяет кнопка создания, как и раньше, а заготовка отвечает только
 * на вопрос «из каких шагов он состоит».
 *
 * Заявки в пути правка не трогает: при подаче маршрут копируется в шаги заявки
 * (PurchaseApprovalStep) и дальше живёт отдельно от заготовки. Поэтому здесь нет
 * версий и дат действия — версия заготовки, по которой шла заявка, и есть её
 * собственные шаги.
 *
 * Пустая заготовка означает «маршрут не настроен»: заявки этого вида подать
 * нельзя, пока админ не собрал цепочку. Умолчания в коде нет намеренно — копия
 * регламента, которую не синхронизируют с админкой, была бы вторым ответом на
 * вопрос «как согласуют закупки».
 */
#[ORM\Entity(repositoryClass: PurchaseRouteTemplateRepository::class)]
#[ORM\Table(name: 'purchase_route_template')]
#[ORM\UniqueConstraint(name: 'uniq_purchase_route_template_kind', columns: ['kind'])]
class PurchaseRouteTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PurchaseRequestKind::class)]
    private ?PurchaseRequestKind $kind = null;

    /** @var Collection<int, PurchaseRouteTemplateStep> */
    #[ORM\OneToMany(
        mappedBy: 'template',
        targetEntity: PurchaseRouteTemplateStep::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $steps;

    /**
     * Кто и когда последним менял регламент. Правка маршрута сильнее любой
     * другой настройки модуля, и вопрос «почему заявка не пошла к юристам»
     * должен иметь ответ: шаги заявки покажут, как было, эти поля — кто менял.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->steps = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): ?PurchaseRequestKind
    {
        return $this->kind;
    }

    public function setKind(PurchaseRequestKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    /** @return Collection<int, PurchaseRouteTemplateStep> */
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

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
