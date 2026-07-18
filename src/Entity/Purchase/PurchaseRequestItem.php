<?php

namespace App\Entity\Purchase;

use App\Repository\Purchase\PurchaseRequestItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PurchaseRequestItemRepository::class)]
class PurchaseRequestItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseRequest::class, inversedBy: 'items')]
    #[ORM\JoinColumn(name: 'purchase_request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRequest $purchaseRequest = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Наименование позиции обязательно для заполнения.')]
    #[Assert\Length(max: 255, maxMessage: 'Наименование позиции не должно превышать {{ limit }} символов.')]
    private ?string $name = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 3)]
    #[Assert\Positive(message: 'Количество должно быть больше нуля.')]
    private ?string $quantity = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(message: 'Единица измерения обязательна для заполнения.')]
    #[Assert\Length(max: 20, maxMessage: 'Единица измерения не должна превышать {{ limit }} символов.')]
    private ?string $unit = null;

    #[ORM\Column(name: 'estimated_price', type: Types::DECIMAL, precision: 12, scale: 2)]
    #[Assert\PositiveOrZero(message: 'Цена не может быть отрицательной.')]
    private ?string $estimatedPrice = null;

    #[ORM\Column]
    private int $position = 0;

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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getQuantity(): ?string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getEstimatedPrice(): ?string
    {
        return $this->estimatedPrice;
    }

    public function setEstimatedPrice(string $estimatedPrice): static
    {
        $this->estimatedPrice = $estimatedPrice;

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
