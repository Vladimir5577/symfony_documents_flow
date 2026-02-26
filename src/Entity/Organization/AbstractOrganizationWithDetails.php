<?php

namespace App\Entity\Organization;

use App\Enum\TaxType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\MappedSuperclass]
abstract class AbstractOrganizationWithDetails extends AbstractOrganization
{
    #[ORM\Column(length: 12, nullable: true)]
    #[Assert\Length(max: 12, maxMessage: 'ИНН не более {{ limit }} символов.')]
    #[Assert\Regex(pattern: '/^\d{10}$|^\d{12}$/', message: 'ИНН должен содержать 10 или 12 цифр.')]
    private ?string $inn = null;

    #[ORM\Column(length: 9, nullable: true)]
    #[Assert\Length(max: 9, maxMessage: 'КПП не более {{ limit }} символов.')]
    #[Assert\Regex(pattern: '/^\d{9}$/', message: 'КПП должен содержать 9 цифр.')]
    private ?string $kpp = null;

    #[ORM\Column(length: 15, nullable: true)]
    #[Assert\Length(max: 15, maxMessage: 'ОГРН/ОГРНИП не более {{ limit }} символов.')]
    #[Assert\Regex(pattern: '/^\d{13}$|^\d{15}$/', message: 'ОГРН — 13 цифр, ОГРНИП — 15 цифр.')]
    private ?string $ogrn = null;

    #[ORM\Column(name: 'registration_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $registrationDate = null;

    #[ORM\Column(name: 'registration_organ', length: 500, nullable: true)]
    #[Assert\Length(max: 500, maxMessage: 'Орган регистрации не более {{ limit }} символов.')]
    private ?string $registrationOrgan = null;

    #[ORM\Column(name: 'bank_name', length: 255, nullable: true)]
    #[Assert\Length(max: 255, maxMessage: 'Наименование банка не более {{ limit }} символов.')]
    private ?string $bankName = null;

    #[ORM\Column(length: 9, nullable: true)]
    #[Assert\Length(max: 9, maxMessage: 'БИК — 9 цифр.')]
    #[Assert\Regex(pattern: '/^\d{9}$/', message: 'БИК должен содержать 9 цифр.')]
    private ?string $bik = null;

    #[ORM\Column(name: 'bank_account', length: 20, nullable: true)]
    #[Assert\Length(max: 20, maxMessage: 'Расчётный счёт — 20 цифр.')]
    #[Assert\Regex(pattern: '/^\d{20}$/', message: 'Расчётный счёт должен содержать 20 цифр.')]
    private ?string $bankAccount = null;

    #[ORM\Column(name: 'tax_type', type: Types::STRING, length: 50, nullable: true, enumType: TaxType::class)]
    private ?TaxType $taxType = null;

    public function getInn(): ?string
    {
        return $this->inn;
    }

    public function setInn(?string $inn): static
    {
        $this->inn = $inn;

        return $this;
    }

    public function getKpp(): ?string
    {
        return $this->kpp;
    }

    public function setKpp(?string $kpp): static
    {
        $this->kpp = $kpp;

        return $this;
    }

    public function getOgrn(): ?string
    {
        return $this->ogrn;
    }

    public function setOgrn(?string $ogrn): static
    {
        $this->ogrn = $ogrn;

        return $this;
    }

    public function getRegistrationDate(): ?\DateTimeImmutable
    {
        return $this->registrationDate;
    }

    public function setRegistrationDate(?\DateTimeImmutable $registrationDate): static
    {
        $this->registrationDate = $registrationDate;

        return $this;
    }

    public function getRegistrationOrgan(): ?string
    {
        return $this->registrationOrgan;
    }

    public function setRegistrationOrgan(?string $registrationOrgan): static
    {
        $this->registrationOrgan = $registrationOrgan;

        return $this;
    }

    public function getBankName(): ?string
    {
        return $this->bankName;
    }

    public function setBankName(?string $bankName): static
    {
        $this->bankName = $bankName;

        return $this;
    }

    public function getBik(): ?string
    {
        return $this->bik;
    }

    public function setBik(?string $bik): static
    {
        $this->bik = $bik;

        return $this;
    }

    public function getBankAccount(): ?string
    {
        return $this->bankAccount;
    }

    public function setBankAccount(?string $bankAccount): static
    {
        $this->bankAccount = $bankAccount;

        return $this;
    }

    public function getTaxType(): ?TaxType
    {
        return $this->taxType;
    }

    public function setTaxType(?TaxType $taxType): static
    {
        $this->taxType = $taxType;

        return $this;
    }
}
