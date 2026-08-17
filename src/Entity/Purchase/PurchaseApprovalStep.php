<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Entity\User\User;
use App\Enum\Purchase\PurchaseApproverKind;
use App\Enum\Purchase\PurchaseFileType;
use App\Enum\Purchase\PurchaseStepDecision;
use App\Repository\Purchase\PurchaseApprovalStepRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Шаг маршрута конкретной заявки. Одна строка = одна требуемая подпись.
 *
 * Маршрут собирается один раз при подаче из шаблона и замораживается: правка
 * шаблона не трогает заявки в пути. Текущая позиция не хранится, а считается —
 * минимальный position среди PENDING-шагов (см. PurchaseRequest::getCurrentPosition).
 *
 * approver_* отвечает на вопрос «кого ждали», decided_by — «кто фактически нажал».
 * У ролевого шага approver_user_id так и остаётся пустым: ждали любого носителя роли,
 * и затирать это подписантом нельзя, иначе теряется первый вопрос.
 */
#[ORM\Entity(repositoryClass: PurchaseApprovalStepRepository::class)]
#[ORM\Index(columns: ['purchase_request_id', 'position'])]
#[ORM\Index(columns: ['approver_user_id', 'decision'])]
#[ORM\Index(columns: ['approver_role', 'decision'])]
class PurchaseApprovalStep
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseRequest::class, inversedBy: 'steps')]
    #[ORM\JoinColumn(name: 'purchase_request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRequest $purchaseRequest = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position = 1;

    #[ORM\Column(name: 'approver_kind', type: Types::STRING, length: 30, enumType: PurchaseApproverKind::class)]
    private PurchaseApproverKind $approverKind = PurchaseApproverKind::ROLE;

    #[ORM\Column(name: 'approver_role', type: Types::STRING, length: 50, nullable: true)]
    private ?string $approverRole = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'approver_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $approverUser = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(name: 'requires_file_type', type: Types::STRING, length: 30, nullable: true, enumType: PurchaseFileType::class)]
    private ?PurchaseFileType $requiresFileType = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PurchaseStepDecision::class, options: ['default' => 'PENDING'])]
    private PurchaseStepDecision $decision = PurchaseStepDecision::PENDING;

    /**
     * «Рассмотрю позже»: заявка остаётся у директора, но уезжает в конец очереди.
     * Без этой отметки отложенная заявка заезжала бы в модалку первой по кругу.
     */
    #[ORM\Column(name: 'deferred_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deferredAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    // Кто поставил шаг; NULL — пришёл из шаблона или из обязательного минимума
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

    public function getPurchaseRequest(): ?PurchaseRequest
    {
        return $this->purchaseRequest;
    }

    public function setPurchaseRequest(?PurchaseRequest $purchaseRequest): static
    {
        $this->purchaseRequest = $purchaseRequest;

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

    public function getApproverKind(): PurchaseApproverKind
    {
        return $this->approverKind;
    }

    public function setApproverKind(PurchaseApproverKind $approverKind): static
    {
        $this->approverKind = $approverKind;

        return $this;
    }

    public function getApproverRole(): ?string
    {
        return $this->approverRole;
    }

    public function setApproverRole(?string $approverRole): static
    {
        $this->approverRole = $approverRole;

        return $this;
    }

    public function getApproverUser(): ?User
    {
        return $this->approverUser;
    }

    public function setApproverUser(?User $approverUser): static
    {
        $this->approverUser = $approverUser;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getRequiresFileType(): ?PurchaseFileType
    {
        return $this->requiresFileType;
    }

    public function setRequiresFileType(?PurchaseFileType $requiresFileType): static
    {
        $this->requiresFileType = $requiresFileType;

        return $this;
    }


    public function getDecision(): PurchaseStepDecision
    {
        return $this->decision;
    }

    public function setDecision(PurchaseStepDecision $decision): static
    {
        $this->decision = $decision;
        // Решение снимает отметку «рассмотрю позже»: откладывать больше нечего,
        // а подписанный шаг с флагом «отложен» — мусор в карточке и в очереди.
        $this->deferredAt = null;

        return $this;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): static
    {
        $this->decidedBy = $decidedBy;

        return $this;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeferredAt(): ?\DateTimeImmutable
    {
        return $this->deferredAt;
    }

    public function setDeferredAt(?\DateTimeImmutable $deferredAt): static
    {
        $this->deferredAt = $deferredAt;

        return $this;
    }

    public function isPending(): bool
    {
        return $this->decision === PurchaseStepDecision::PENDING;
    }

    /**
     * Шаг адресован лично этому человеку. Ролевые шаги сюда не попадают:
     * право на них проверяется через isGranted($step->getApproverRole()).
     */
    public function isAddressedTo(User $user): bool
    {
        return $this->approverUser?->getId() === $user->getId();
    }
}
