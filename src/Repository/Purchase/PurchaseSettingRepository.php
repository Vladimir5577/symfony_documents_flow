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

    /** От какой суммы шаг директора обязателен в маршруте. 0 — обязателен всегда. */
    public function getCeoApproveMinAmount(): float
    {
        return (float) $this->get(PurchaseSettingKey::CEO_APPROVE_MIN_AMOUNT);
    }

    /**
     * До какой суммы доступна короткая форма быстрой заявки.
     * С порогом директора НЕ связан: потолок 20 000 при пороге 5 000 —
     * штатная ситуация, быстрая заявка на 8 000 просто получит шаг директора.
     */
    public function getFastMaxAmount(): float
    {
        return (float) $this->get(PurchaseSettingKey::FAST_MAX_AMOUNT);
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
