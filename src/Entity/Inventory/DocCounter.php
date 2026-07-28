<?php

namespace App\Entity\Inventory;

use App\Enum\Inventory\DocumentType;
use App\Repository\Inventory\DocCounterRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocCounterRepository::class)]
#[ORM\Table(name: 'inventory_doc_counter')]
class DocCounter
{
    #[ORM\Id]
    #[ORM\Column(name: 'doc_type', length: 50, enumType: DocumentType::class)]
    private ?DocumentType $docType = null;

    #[ORM\Id]
    #[ORM\Column]
    private ?int $year = null;

    #[ORM\Column(name: 'last_no', options: ['default' => 0])]
    private int $lastNo = 0;

    public function getDocType(): ?DocumentType
    {
        return $this->docType;
    }

    public function setDocType(DocumentType $docType): static
    {
        $this->docType = $docType;

        return $this;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getLastNo(): int
    {
        return $this->lastNo;
    }

    public function setLastNo(int $lastNo): static
    {
        $this->lastNo = $lastNo;

        return $this;
    }
}
