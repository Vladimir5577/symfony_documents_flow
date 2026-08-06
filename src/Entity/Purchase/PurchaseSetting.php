<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Enum\Purchase\PurchaseSettingKey;
use App\Repository\Purchase\PurchaseSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Настройки модуля закупок: ключ-значение.
 *
 * Строки заводятся по мере сохранения через интерфейс — отсутствие строки
 * означает «действует дефолт из PurchaseSettingKey::getDefault()».
 * Значение в JSON, чтобы число оставалось числом, а флаг — булевым.
 */
#[ORM\Entity(repositoryClass: PurchaseSettingRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_purchase_setting_key', columns: ['key'])]
class PurchaseSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 100, enumType: PurchaseSettingKey::class)]
    private PurchaseSettingKey $key;

    #[ORM\Column(type: Types::JSON)]
    private mixed $value = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): PurchaseSettingKey
    {
        return $this->key;
    }

    public function setKey(PurchaseSettingKey $key): static
    {
        $this->key = $key;

        return $this;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function setValue(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }
}
