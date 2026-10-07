<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Enum\Purchase\PurchaseContractStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Договор, который можно прикрепить к нескольким заявкам.
 *
 * Файлы остаются строками purchase_request_file и смотрят сюда. Заявка держит
 * только ссылку purchase_request_contract — копии файла нет.
 */
#[ORM\Entity]
#[ORM\Table(name: 'purchase_contract')]
class PurchaseContract
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PurchaseContractStatus::class, options: ['default' => 'ACTIVE'])]
    private PurchaseContractStatus $status = PurchaseContractStatus::ACTIVE;

    /** Своё имя. При появлении копируется с файла и дальше живёт отдельно. */
    #[ORM\Column(length: 255)]
    private string $name = '';

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, PurchaseRequestFile> */
    #[ORM\OneToMany(mappedBy: 'contract', targetEntity: PurchaseRequestFile::class)]
    private Collection $files;

    /** @var Collection<int, PurchaseRequestContract> */
    #[ORM\OneToMany(mappedBy: 'contract', targetEntity: PurchaseRequestContract::class)]
    private Collection $links;

    public function __construct()
    {
        $this->files = new ArrayCollection();
        $this->links = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatus(): PurchaseContractStatus
    {
        return $this->status;
    }

    public function setStatus(PurchaseContractStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
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
            $file->setContract($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, PurchaseRequestContract>
     */
    public function getLinks(): Collection
    {
        return $this->links;
    }

    public function addLink(PurchaseRequestContract $link): static
    {
        if (!$this->links->contains($link)) {
            $this->links->add($link);
        }

        return $this;
    }

    public function removeLink(PurchaseRequestContract $link): static
    {
        $this->links->removeElement($link);

        return $this;
    }
}
