<?php

namespace App\Entity\Inventory;

use App\Entity\User\User;
use App\Repository\Inventory\CredentialViewLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: CredentialViewLogRepository::class)]
#[ORM\Table(name: 'inventory_credential_view_log')]
#[ORM\Index(name: 'idx_inv_cred_view_log_credential', columns: ['credential_id'])]
class CredentialViewLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: DeviceCredential::class)]
    #[ORM\JoinColumn(name: 'credential_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?DeviceCredential $credential = null;

    #[ORM\Column(name: 'credential_title', length: 255)]
    private ?string $credentialTitle = null;

    #[ORM\Column(name: 'device_name', length: 255)]
    private ?string $deviceName = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCredential(): ?DeviceCredential
    {
        return $this->credential;
    }

    public function setCredential(?DeviceCredential $credential): static
    {
        $this->credential = $credential;

        return $this;
    }

    public function getCredentialTitle(): ?string
    {
        return $this->credentialTitle;
    }

    public function setCredentialTitle(string $credentialTitle): static
    {
        $this->credentialTitle = $credentialTitle;

        return $this;
    }

    public function getDeviceName(): ?string
    {
        return $this->deviceName;
    }

    public function setDeviceName(string $deviceName): static
    {
        $this->deviceName = $deviceName;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
