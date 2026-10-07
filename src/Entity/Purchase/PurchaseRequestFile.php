<?php

namespace App\Entity\Purchase;

use App\Entity\User\User;
use App\Enum\Purchase\PurchaseFileType;
use App\Repository\Purchase\PurchaseRequestFileRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: PurchaseRequestFileRepository::class)]
class PurchaseRequestFile
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseRequest::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'purchase_request_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    private ?PurchaseRequest $purchaseRequest = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'uploaded_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $uploadedBy = null;

    /** Ключ объекта в бакете MinIO. Содержимое лежит там, в базе только ключ. */
    #[ORM\Column(name: 'storage_key', length: 255)]
    private string $storageKey;

    // Имя файла, каким его загрузил пользователь
    #[ORM\Column(name: 'original_name', length: 255)]
    private ?string $originalName = null;

    // Тип вложения: ТЗ, пояснительная записка или прочее
    #[ORM\Column(type: Types::STRING, length: 30, enumType: PurchaseFileType::class, options: ['default' => 'OTHER'])]
    private PurchaseFileType $type = PurchaseFileType::OTHER;

    /** Цвет счёта (#RRGGBB): им помечены позиции, которые по нему оплачивают. */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null;

    /** Подписанный договор указывает на договор, который он подписывает. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'contract_file_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseRequestFile $contractFile = null;

    /** Старые копии. Новое прикрепление эту колонку не заполняет. */
    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'source_file_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseRequestFile $sourceFile = null;

    /**
     * Договор справочника. Файл с заявки смотрит сюда; файл, заведённый в справочнике, заявки не имеет.
     */
    #[ORM\ManyToOne(targetEntity: PurchaseContract::class, inversedBy: 'files')]
    #[ORM\JoinColumn(name: 'purchase_contract_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?PurchaseContract $contract = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Редакция для OnlyOffice — часть ключа документа.
     *
     * Все, кто открыл файл, должны получить один ключ, иначе каждый правит свою
     * копию. Но когда сессия закрылась и её итог лёг в хранилище, следующая
     * обязана открыться под новым ключом: по старому Document Server отдал бы
     * закэшированный прошлый документ, а не файл из хранилища.
     */
    #[ORM\Column(name: 'editor_revision', type: Types::INTEGER, options: ['default' => 1])]
    private int $editorRevision = 1;

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

    public function getUploadedBy(): ?User
    {
        return $this->uploadedBy;
    }

    public function setUploadedBy(?User $uploadedBy): static
    {
        $this->uploadedBy = $uploadedBy;

        return $this;
    }

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function setStorageKey(string $storageKey): static
    {
        $this->storageKey = $storageKey;

        return $this;
    }

    public function getOriginalName(): ?string
    {
        return $this->originalName;
    }

    public function setOriginalName(string $originalName): static
    {
        $this->originalName = $originalName;

        return $this;
    }

    public function getType(): PurchaseFileType
    {
        return $this->type;
    }

    public function setType(PurchaseFileType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function getContractFile(): ?PurchaseRequestFile
    {
        return $this->contractFile;
    }

    public function setContractFile(?PurchaseRequestFile $contractFile): static
    {
        $this->contractFile = $contractFile;

        return $this;
    }

    public function getSourceFile(): ?PurchaseRequestFile
    {
        return $this->sourceFile;
    }

    public function setSourceFile(?PurchaseRequestFile $sourceFile): static
    {
        $this->sourceFile = $sourceFile;

        return $this;
    }

    public function getContract(): ?PurchaseContract
    {
        return $this->contract;
    }

    public function setContract(?PurchaseContract $contract): static
    {
        $this->contract = $contract;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getEditorRevision(): int
    {
        return $this->editorRevision;
    }

    public function nextEditorRevision(): static
    {
        ++$this->editorRevision;

        return $this;
    }
}
