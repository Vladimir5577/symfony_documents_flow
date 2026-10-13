<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use Doctrine\ORM\Mapping as ORM;

/** Заявка пользуется договором. Файл при этом не копируется. */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_request_contract')]
#[ORM\UniqueConstraint(name: 'uniq_purchase_request_contract', columns: ['purchase_request_id', 'purchase_contract_id'])]
class PurchaseRequestContract
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: PurchaseRequest::class, inversedBy: 'contractLinks')]
    #[ORM\JoinColumn(name: 'purchase_request_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseRequest $purchaseRequest = null;

    #[ORM\ManyToOne(targetEntity: PurchaseContract::class, inversedBy: 'links')]
    #[ORM\JoinColumn(name: 'purchase_contract_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?PurchaseContract $contract = null;

    public function __construct(PurchaseRequest $purchaseRequest, PurchaseContract $contract)
    {
        $this->purchaseRequest = $purchaseRequest;
        $this->contract = $contract;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPurchaseRequest(): ?PurchaseRequest
    {
        return $this->purchaseRequest;
    }

    public function getContract(): PurchaseContract
    {
        if ($this->contract === null) {
            throw new \LogicException('Ссылка на договор без договора.');
        }

        return $this->contract;
    }
}
