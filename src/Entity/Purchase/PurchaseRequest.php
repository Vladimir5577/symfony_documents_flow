<?php

namespace App\Entity\Purchase;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseLaw;
use App\Enum\Purchase\PurchaseMethod;
use App\Enum\Purchase\PurchasePriority;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseStatus;
use App\Repository\Purchase\PurchaseRequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PurchaseRequestRepository::class)]
#[ORM\Index(columns: ['organization_id', 'status'])]
#[ORM\Index(columns: ['status'])]
#[ORM\Index(columns: ['created_at'])]
class PurchaseRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Узел дерева организаций (обычно департамент автора)
    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull(message: 'Организация обязательна для заполнения.')]
    private ?AbstractOrganization $organization = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    // Сотрудник отдела закупок, взявший заявку в работу
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'executor_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $executor = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Название заявки обязательно для заполнения.')]
    #[Assert\Length(max: 255, maxMessage: 'Название заявки не должно превышать {{ limit }} символов.')]
    private ?string $title = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // Категория из справочника; NULL — менеджер не нашёл подходящую, проставит отдел закупок
    #[ORM\ManyToOne(targetEntity: PurchaseCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?PurchaseCategory $category = null;

    // Закон: по умолчанию 223-ФЗ — по нему идёт почти всё. Автор его не выбирает,
    // при рассмотрении поправит отдел закупок.
    #[ORM\Column(type: Types::STRING, length: 20, nullable: true, enumType: PurchaseLaw::class)]
    private ?PurchaseLaw $law = PurchaseLaw::FZ_223;

    // Способ закупки; NULL — определит отдел закупок при рассмотрении
    #[ORM\Column(type: Types::STRING, length: 30, nullable: true, enumType: PurchaseMethod::class)]
    private ?PurchaseMethod $method = null;

    // Техническое задание текстом (альтернатива — файл типа TECHNICAL_SPEC)
    #[ORM\Column(name: 'technical_spec', type: Types::TEXT, nullable: true)]
    private ?string $technicalSpec = null;

    #[ORM\Column(type: Types::STRING, length: 50, enumType: PurchaseStatus::class)]
    private PurchaseStatus $status = PurchaseStatus::DRAFT;

    // Какой кнопкой создана. НЕ меняется: от неё зависят набор полей формы
    // при редактировании и проверка потолка быстрой заявки при подаче.
    #[ORM\Column(name: 'created_as', type: Types::STRING, length: 20, enumType: PurchaseRequestKind::class, options: ['default' => 'STANDARD'])]
    private PurchaseRequestKind $createdAs = PurchaseRequestKind::STANDARD;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PurchasePriority::class, options: ['default' => 'NORMAL'])]
    private PurchasePriority $priority = PurchasePriority::NORMAL;

    #[ORM\Column(name: 'due_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $dueDate = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, PurchaseRequestItem> */
    #[ORM\OneToMany(mappedBy: 'purchaseRequest', targetEntity: PurchaseRequestItem::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $items;

    /** @var Collection<int, PurchaseRequestComment> */
    #[ORM\OneToMany(mappedBy: 'purchaseRequest', targetEntity: PurchaseRequestComment::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $comments;

    /** @var Collection<int, PurchaseRequestHistory> */
    #[ORM\OneToMany(mappedBy: 'purchaseRequest', targetEntity: PurchaseRequestHistory::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $history;

    /** @var Collection<int, PurchaseRequestFile> */
    #[ORM\OneToMany(mappedBy: 'purchaseRequest', targetEntity: PurchaseRequestFile::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $files;

    /** @var Collection<int, PurchaseApprovalStep> */
    #[ORM\OneToMany(mappedBy: 'purchaseRequest', targetEntity: PurchaseApprovalStep::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $steps;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->comments = new ArrayCollection();
        $this->history = new ArrayCollection();
        $this->files = new ArrayCollection();
        $this->steps = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): ?AbstractOrganization
    {
        return $this->organization;
    }

    public function setOrganization(AbstractOrganization $organization): static
    {
        $this->organization = $organization;

        return $this;
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

    public function getExecutor(): ?User
    {
        return $this->executor;
    }

    public function setExecutor(?User $executor): static
    {
        $this->executor = $executor;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCategory(): ?PurchaseCategory
    {
        return $this->category;
    }

    public function setCategory(?PurchaseCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getLaw(): ?PurchaseLaw
    {
        return $this->law;
    }

    public function setLaw(?PurchaseLaw $law): static
    {
        $this->law = $law;

        return $this;
    }

    public function getMethod(): ?PurchaseMethod
    {
        return $this->method;
    }

    public function setMethod(?PurchaseMethod $method): static
    {
        $this->method = $method;

        return $this;
    }


    public function getTechnicalSpec(): ?string
    {
        return $this->technicalSpec;
    }

    public function setTechnicalSpec(?string $technicalSpec): static
    {
        $this->technicalSpec = $technicalSpec;

        return $this;
    }

    public function getStatus(): PurchaseStatus
    {
        return $this->status;
    }

    public function setStatus(PurchaseStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getPriority(): PurchasePriority
    {
        return $this->priority;
    }

    public function setPriority(PurchasePriority $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getDueDate(): ?\DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(?\DateTimeImmutable $dueDate): static
    {
        $this->dueDate = $dueDate;

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
     * @return Collection<int, PurchaseRequestItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(PurchaseRequestItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setPurchaseRequest($this);
        }

        return $this;
    }

    public function removeItem(PurchaseRequestItem $item): static
    {
        $this->items->removeElement($item);

        return $this;
    }

    /**
     * Сумма заявки: считается из позиций, отдельно не хранится.
     */
    public function getTotalAmount(): float
    {
        $total = 0.0;
        foreach ($this->items as $item) {
            // Снятые директором позиции в сумму не идут, количество — утверждённое
            if ($item->isExcluded()) {
                continue;
            }
            $total += (float) $item->getEffectiveQuantity() * (float) $item->getEstimatedPrice();
        }

        return round($total, 2);
    }

    /**
     * @return Collection<int, PurchaseRequestComment>
     */
    public function getComments(): Collection
    {
        return $this->comments;
    }

    public function addComment(PurchaseRequestComment $comment): static
    {
        if (!$this->comments->contains($comment)) {
            $this->comments->add($comment);
            $comment->setPurchaseRequest($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, PurchaseRequestHistory>
     */
    public function getHistory(): Collection
    {
        return $this->history;
    }

    public function addHistory(PurchaseRequestHistory $entry): static
    {
        if (!$this->history->contains($entry)) {
            $this->history->add($entry);
            $entry->setPurchaseRequest($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, PurchaseRequestFile>
     */
    public function getFiles(): Collection
    {
        return $this->files;
    }

    public function addFile(PurchaseRequestFile $file): static
    {
        if (!$this->files->contains($file)) {
            $this->files->add($file);
            $file->setPurchaseRequest($this);
        }

        return $this;
    }

    public function removeFile(PurchaseRequestFile $file): static
    {
        $this->files->removeElement($file);

        return $this;
    }

    public function hasFileOfType(PurchaseFileType $type): bool
    {
        foreach ($this->files as $file) {
            if ($file->getType() === $type) {
                return true;
            }
        }

        return false;
    }

    public function getCreatedAs(): PurchaseRequestKind
    {
        return $this->createdAs;
    }

    public function setCreatedAs(PurchaseRequestKind $createdAs): static
    {
        $this->createdAs = $createdAs;

        return $this;
    }

    /**
     * @return Collection<int, PurchaseApprovalStep>
     */
    public function getSteps(): Collection
    {
        return $this->steps;
    }

    public function addStep(PurchaseApprovalStep $step): static
    {
        if (!$this->steps->contains($step)) {
            $this->steps->add($step);
            $step->setPurchaseRequest($this);
        }

        return $this;
    }

    public function removeStep(PurchaseApprovalStep $step): static
    {
        $this->steps->removeElement($step);

        return $this;
    }

    /**
     * Указатель маршрута: минимальная позиция среди незакрытых шагов.
     * NULL — незакрытых нет, то есть маршрут пройден (или ещё не построен).
     * Отдельной колонкой не хранится, чтобы её нельзя было рассинхронить с шагами.
     */
    public function getCurrentPosition(): ?int
    {
        $min = null;
        foreach ($this->steps as $step) {
            if ($step->isPending() && ($min === null || $step->getPosition() < $min)) {
                $min = $step->getPosition();
            }
        }

        return $min;
    }

    /**
     * Шаги, по которым можно действовать прямо сейчас — вся текущая позиция.
     * Параллельные шаги закрываются в любом порядке, позиция уходит, когда закрыты все.
     *
     * @return list<PurchaseApprovalStep>
     */
    public function getActiveSteps(): array
    {
        $position = $this->getCurrentPosition();
        if ($position === null) {
            return [];
        }

        $active = [];
        foreach ($this->steps as $step) {
            if ($step->isPending() && $step->getPosition() === $position) {
                $active[] = $step;
            }
        }

        return $active;
    }

    /** Маршрут построен и полностью пройден. */
    public function isRouteComplete(): bool
    {
        return !$this->steps->isEmpty() && $this->getCurrentPosition() === null;
    }
}
