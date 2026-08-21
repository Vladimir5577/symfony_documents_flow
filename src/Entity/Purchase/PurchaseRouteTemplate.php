<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Entity\User\User;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Repository\Purchase\PurchaseRouteTemplateRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Заготовка маршрута: именованная цепочка этапов, по которой можно пустить заявку.
 *
 * Заготовок на вид заявки много. Раньше была ровно одна, и уникальность по виду
 * означала, что «какой это маршрут» и «быстрая заявка или обычная» — один и тот
 * же вопрос. Это два разных вопроса: вид заявки решает форма и потолок суммы, а
 * маршрут — кто её согласует, и одному виду законно соответствуют несколько
 * маршрутов. Какая из заготовок применяется по умолчанию, говорит
 * PurchaseRouteDefault; конкретной заявке маршрут можно назначить отдельно.
 *
 * Заявки в пути правка не трогает: при подаче этапы копируются в заявку и дальше
 * живут отдельно. Поэтому здесь нет ни версий, ни дат действия — снимок в заявке
 * и есть версия заготовки, по которой она пошла.
 *
 * Заготовки не удаляются, только выключаются: на них ссылаются уже прошедшие
 * заявки, и вопрос «по какому регламенту это согласовали» должен иметь ответ.
 *
 */
#[ORM\Entity(repositoryClass: PurchaseRouteTemplateRepository::class)]
#[ORM\Table(name: 'purchase_route_template')]
#[ORM\UniqueConstraint(name: 'uniq_purchase_route_template_code', columns: ['code'])]
#[ORM\Index(columns: ['is_active', 'sort_order'])]
class PurchaseRouteTemplate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Машинное имя маршрута: STANDARD_WITH_SECURITY и подобные.
     *
     * Нужно, чтобы на маршрут можно было сослаться из фикстур и из установки, не
     * зная его id, и чтобы переименование в админке не меняло эту ссылку.
     */
    #[ORM\Column(length: 50)]
    private ?string $code = null;

    /** Что видит админ в списке и разбирающий в выпадающем списке. */
    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Виды заявок, которым этот маршрут разрешён.
     *
     * Список, а не одно значение: один и тот же маршрут может обслуживать и
     * быстрые заявки, и обычные, и заводить его копию ради этого незачем.
     *
     * @var list<string>
     */
    #[ORM\Column(name: 'allowed_kinds', type: Types::JSON)]
    private array $allowedKinds = [];

    /** Выключенный маршрут нельзя ни назначить, ни сделать дефолтным. */
    #[ORM\Column(name: 'is_active', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $active = true;

    #[ORM\Column(name: 'sort_order', type: Types::SMALLINT, options: ['default' => 0])]
    private int $sortOrder = 0;

    /** @var Collection<int, PurchaseRouteTemplateStage> */
    #[ORM\OneToMany(
        mappedBy: 'template',
        targetEntity: PurchaseRouteTemplateStage::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $stages;

    /**
     * Версия строки — оптимистичная блокировка.
     *
     * Дерево заменяется целиком, поэтому потерянная запись здесь теряет не поле, а
     * весь маршрут: двое админов, сохранивших одну заготовку, — и правка первого
     * исчезает без следа, тогда как заявки уже пойдут по регламенту, которого он
     * не писал. Сверять по updatedAt было бы дешевле, но время правки — это
     * сведения для человека, а не признак того, что строку успели изменить.
     */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    /**
     * Кто и когда последним менял регламент. Правка маршрута сильнее любой
     * другой настройки модуля, и вопрос «почему заявка не пошла к юристам»
     * должен иметь ответ: снимок заявки покажет, как было, эти поля — кто менял.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->stages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = trim($code);

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description !== null && trim($description) !== '' ? trim($description) : null;

        return $this;
    }

    /** @return list<PurchaseRequestKind> */
    public function getAllowedKinds(): array
    {
        $kinds = [];
        foreach ($this->allowedKinds as $value) {
            $kind = PurchaseRequestKind::tryFrom($value);
            if ($kind !== null) {
                $kinds[] = $kind;
            }
        }

        return $kinds;
    }

    /** @param list<PurchaseRequestKind> $kinds */
    public function setAllowedKinds(array $kinds): static
    {
        $values = [];
        foreach ($kinds as $kind) {
            $values[$kind->value] = true;
        }
        $this->allowedKinds = array_keys($values);

        return $this;
    }

    public function allowsKind(PurchaseRequestKind $kind): bool
    {
        return in_array($kind->value, $this->allowedKinds, true);
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /** @return Collection<int, PurchaseRouteTemplateStage> */
    public function getStages(): Collection
    {
        return $this->stages;
    }

    public function addStage(PurchaseRouteTemplateStage $stage): static
    {
        if (!$this->stages->contains($stage)) {
            $this->stages->add($stage);
            $stage->setTemplate($this);
        }

        return $this;
    }

    public function removeStage(PurchaseRouteTemplateStage $stage): static
    {
        $this->stages->removeElement($stage);

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

    /**
     * Маршрут без этапов подать нельзя — заявка встала бы, не стоя ни у кого.
     * Пустая заготовка означает «маршрут не настроен», а не «согласований нет».
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    public function isEmpty(): bool
    {
        return $this->stages->isEmpty();
    }

    /** Первый этап разбора, если он в маршруте есть. */
    public function findTriageStage(): ?PurchaseRouteTemplateStage
    {
        foreach ($this->stages as $stage) {
            if ($stage->getPurpose() === PurchaseStagePurpose::TRIAGE) {
                return $stage;
            }
        }

        return null;
    }
}
