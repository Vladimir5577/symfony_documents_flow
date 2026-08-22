<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Enum\Purchase\PurchaseRoleCode;
use App\Enum\Purchase\PurchaseStagePurpose;
use App\Enum\Purchase\PurchaseTaskAssignment;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Этап заготовки маршрута — «что делают на этом шаге согласования».
 *
 * Этапы идут по порядку, задачи внутри этапа параллельны: две задачи в одном
 * этапе — это две подписи, которые кладут в любом порядке. Прежде и то и другое
 * выражалось номерами позиций у задач, и параллельность приходилось
 * восстанавливать по совпадению чисел.
 *
 * При подаче этап копируется в PurchaseApprovalStage и дальше живёт отдельно:
 * правка регламента не трогает заявки в пути.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_route_template_stage')]
#[ORM\UniqueConstraint(name: 'uniq_purchase_route_template_stage_position', columns: ['template_id', 'position'])]
class PurchaseRouteTemplateStage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseRouteTemplate::class, inversedBy: 'stages')]
    #[ORM\JoinColumn(name: 'template_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRouteTemplate $template = null;

    /** Порядок согласования. Нумерует редактор — 1..N по порядку присланного списка. */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 1;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: Types::STRING, length: 30, enumType: PurchaseStagePurpose::class)]
    private PurchaseStagePurpose $purpose = PurchaseStagePurpose::SIGN_OFF;

    /**
     * Можно ли с этого этапа вернуть заявку автору.
     *
     * Поле, а не вывод из назначения: как только поведение начинают выводить из
     * purpose, назначение снова означает несколько вещей — этим и был испорчен
     * TRIAGE, где «разбор» значил и права директора, и то, что он смотрит заявку.
     */
    #[ORM\Column(name: 'allows_reject', type: Types::BOOLEAN, options: ['default' => true])]
    private bool $allowsReject = true;

    /** @var Collection<int, PurchaseRouteTemplateTask> */
    #[ORM\OneToMany(
        mappedBy: 'stage',
        targetEntity: PurchaseRouteTemplateTask::class,
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $tasks;

    public function __construct()
    {
        $this->tasks = new ArrayCollection();
    }

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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $title = $title !== null ? trim($title) : null;
        $this->title = $title !== '' ? $title : null;

        return $this;
    }

    public function getPurpose(): PurchaseStagePurpose
    {
        return $this->purpose;
    }

    public function setPurpose(PurchaseStagePurpose $purpose): static
    {
        $this->purpose = $purpose;

        return $this;
    }

    public function allowsReject(): bool
    {
        return $this->allowsReject;
    }

    public function setAllowsReject(bool $allowsReject): static
    {
        $this->allowsReject = $allowsReject;

        return $this;
    }

    /** @return Collection<int, PurchaseRouteTemplateTask> */
    public function getTasks(): Collection
    {
        return $this->tasks;
    }

    public function addTask(PurchaseRouteTemplateTask $task): static
    {
        if (!$this->tasks->contains($task)) {
            $this->tasks->add($task);
            $task->setStage($this);
        }

        return $this;
    }

    public function removeTask(PurchaseRouteTemplateTask $task): static
    {
        $this->tasks->removeElement($task);

        return $this;
    }

    /**
     * Этап, задачи которого появятся только после выбора людей на разборе.
     *
     * Такой этап законно пуст в снимке заявки до решения разбирающего — это
     * единственный случай, когда этап без задач не является ошибкой настройки.
     */
    public function isDynamic(): bool
    {
        foreach ($this->tasks as $task) {
            if ($task->isDynamic()) {
                return true;
            }
        }

        return false;
    }

    /** Пул, из которого выбирают людей на этом этапе. */
    public function getCandidateRoleCode(): ?PurchaseRoleCode
    {
        foreach ($this->tasks as $task) {
            if ($task->isDynamic()) {
                return $task->getCandidateRoleCode();
            }
        }

        return null;
    }

    /** Есть ли в этапе задача, которую закрывает автор заявки. */
    public function hasAuthorTask(): bool
    {
        foreach ($this->tasks as $task) {
            if ($task->getAssignmentType() === PurchaseTaskAssignment::AUTHOR) {
                return true;
            }
        }

        return false;
    }

    /** Заголовок для карточки и превью: свой, иначе — из адресатов задач. */
    public function resolveTitle(): string
    {
        if ($this->title !== null) {
            return $this->title;
        }

        $titles = [];
        foreach ($this->tasks as $task) {
            $titles[] = $task->resolveTitle();
        }

        return $titles === [] ? $this->purpose->getLabel() : implode(' и ', $titles);
    }
}
