<?php

declare(strict_types=1);

namespace App\Repository\Purchase;

use App\Entity\Purchase\PurchaseSetting;
use App\Enum\Purchase\PurchaseSettingKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PurchaseSetting>
 */
class PurchaseSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurchaseSetting::class);
    }

    /** Значение настройки; строки нет — значит её ни разу не сохраняли, действует дефолт. */
    public function get(PurchaseSettingKey $key): mixed
    {
        return $this->findOneBy(['key' => $key])?->getValue() ?? $key->getDefault();
    }

    /** Порог директорского согласования в рублях. 0 — выключено, все заявки идут к директору. */
    public function getCeoApproveMinAmount(): float
    {
        return (float) $this->get(PurchaseSettingKey::CEO_APPROVE_MIN_AMOUNT);
    }

    /** Создаёт строку при первом сохранении. Flush на вызывающей стороне. */
    public function set(PurchaseSettingKey $key, mixed $value): void
    {
        $setting = $this->findOneBy(['key' => $key]);
        if ($setting === null) {
            $setting = (new PurchaseSetting())->setKey($key);
            $this->getEntityManager()->persist($setting);
        }

        $setting->setValue($value);
    }
}
