<?php

namespace App\Entity\Document;

use App\Entity\User\User;
use App\Enum\Document\CertificateStatus;
use App\Repository\Document\UserCertificateRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

#[ORM\Entity(repositoryClass: UserCertificateRepository::class)]
#[ORM\Table(name: 'user_certificate')]
class UserCertificate
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $user = null;

    #[ORM\Column(name: 'serial_number', length: 255, unique: true)]
    private ?string $serialNumber = null;

    #[ORM\Column(name: 'subject_dn', length: 255)]
    private ?string $subjectDn = null;

    #[ORM\Column(name: 'certificate_pem', type: Types::TEXT)]
    private ?string $certificatePem = null;

    #[ORM\Column(name: 'valid_from', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(name: 'valid_to', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $validTo = null;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: CertificateStatus::class, options: ['default' => 'active'])]
    private CertificateStatus $status = CertificateStatus::ACTIVE;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'revocation_reason', length: 255, nullable: true)]
    private ?string $revocationReason = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'issued_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $issuedBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(string $serialNumber): static
    {
        $this->serialNumber = $serialNumber;

        return $this;
    }

    public function getSubjectDn(): ?string
    {
        return $this->subjectDn;
    }

    public function setSubjectDn(string $subjectDn): static
    {
        $this->subjectDn = $subjectDn;

        return $this;
    }

    public function getCertificatePem(): ?string
    {
        return $this->certificatePem;
    }

    public function setCertificatePem(string $certificatePem): static
    {
        $this->certificatePem = $certificatePem;

        return $this;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(\DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(\DateTimeImmutable $validTo): static
    {
        $this->validTo = $validTo;

        return $this;
    }

    public function getStatus(): CertificateStatus
    {
        return $this->status;
    }

    public function setStatus(CertificateStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function setRevokedAt(?\DateTimeImmutable $revokedAt): static
    {
        $this->revokedAt = $revokedAt;

        return $this;
    }

    public function getRevocationReason(): ?string
    {
        return $this->revocationReason;
    }

    public function setRevocationReason(?string $revocationReason): static
    {
        $this->revocationReason = $revocationReason;

        return $this;
    }

    public function getIssuedBy(): ?User
    {
        return $this->issuedBy;
    }

    public function setIssuedBy(?User $issuedBy): static
    {
        $this->issuedBy = $issuedBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
