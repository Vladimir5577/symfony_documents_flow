<?php

namespace App\Entity\Document;

use App\Entity\User\User;
use App\Enum\Document\SignatureLevel;
use App\Repository\Document\DocumentSignatureRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DocumentSignatureRepository::class)]
#[ORM\Table(name: 'document_signature')]
#[ORM\UniqueConstraint(name: 'uniq_document_signature_signer', columns: ['document_id', 'signer_id'])]
class DocumentSignature
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Document::class, inversedBy: 'signatures')]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Document $document = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'signer_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?User $signer = null;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: SignatureLevel::class)]
    private ?SignatureLevel $level = null;

    #[ORM\Column(name: 'document_hash', length: 64)]
    private ?string $documentHash = null;

    #[ORM\Column(name: 'signature_value', type: Types::TEXT, nullable: true)]
    private ?string $signatureValue = null;

    #[ORM\ManyToOne(targetEntity: UserCertificate::class)]
    #[ORM\JoinColumn(name: 'certificate_id', referencedColumnName: 'id', nullable: true, onDelete: 'RESTRICT')]
    private ?UserCertificate $certificate = null;

    #[ORM\Column(length: 64)]
    private ?string $algorithm = null;

    #[ORM\Column(name: 'signed_at', type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $signedAt = null;

    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'user_agent', length: 255, nullable: true)]
    private ?string $userAgent = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): ?Document
    {
        return $this->document;
    }

    public function setDocument(?Document $document): static
    {
        $this->document = $document;

        return $this;
    }

    public function getSigner(): ?User
    {
        return $this->signer;
    }

    public function setSigner(?User $signer): static
    {
        $this->signer = $signer;

        return $this;
    }

    public function getLevel(): ?SignatureLevel
    {
        return $this->level;
    }

    public function setLevel(SignatureLevel $level): static
    {
        $this->level = $level;

        return $this;
    }

    public function getDocumentHash(): ?string
    {
        return $this->documentHash;
    }

    public function setDocumentHash(string $documentHash): static
    {
        $this->documentHash = $documentHash;

        return $this;
    }

    public function getSignatureValue(): ?string
    {
        return $this->signatureValue;
    }

    public function setSignatureValue(?string $signatureValue): static
    {
        $this->signatureValue = $signatureValue;

        return $this;
    }

    public function getCertificate(): ?UserCertificate
    {
        return $this->certificate;
    }

    public function setCertificate(?UserCertificate $certificate): static
    {
        $this->certificate = $certificate;

        return $this;
    }

    public function getAlgorithm(): ?string
    {
        return $this->algorithm;
    }

    public function setAlgorithm(string $algorithm): static
    {
        $this->algorithm = $algorithm;

        return $this;
    }

    public function getSignedAt(): ?\DateTimeImmutable
    {
        return $this->signedAt;
    }

    public function setSignedAt(\DateTimeImmutable $signedAt): static
    {
        $this->signedAt = $signedAt;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }
}
