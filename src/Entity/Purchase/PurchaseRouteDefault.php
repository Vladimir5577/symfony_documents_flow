<?php

declare(strict_types=1);

namespace App\Entity\Purchase;

use App\Entity\User\User;
use App\Enum\Purchase\PurchaseRequestKind;
use App\Repository\Purchase\PurchaseRouteDefaultRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Маршрут по умолчанию для вида заявки: по нему пойдёт заявка, которой маршрут
 * не назначили отдельно.
 *
 * Отдельная таблица, а не строка в ключ-значение настройках: внешний ключ не даёт
 * дефолту указывать на маршрут, которого нет, а RESTRICT — выключить из-под него
 * заготовку молча. Настройка «как согласуют все закупки этого вида» слишком
 * дорога, чтобы жить безымянной строкой в JSON.
 *
 * Смена дефолта касается только будущих подач: у заявки в пути свой снимок.
 */
#[ORM\Entity(repositoryClass: PurchaseRouteDefaultRepository::class)]
#[ORM\Table(name: 'purchase_route_default')]
#[ORM\UniqueConstraint(name: 'uniq_purchase_route_default_kind', columns: ['kind'])]
class PurchaseRouteDefault
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::STRING, length: 20, enumType: PurchaseRequestKind::class)]
    private ?PurchaseRequestKind $kind = null;

    #[ORM\ManyToOne(targetEntity: PurchaseRouteTemplate::class)]
    #[ORM\JoinColumn(name: 'template_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private ?PurchaseRouteTemplate $template = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKind(): ?PurchaseRequestKind
    {
        return $this->kind;
    }

    public function setKind(PurchaseRequestKind $kind): static
    {
        $this->kind = $kind;

        return $this;
    }

    public function getTemplate(): ?PurchaseRouteTemplate
    {
        return $this->template;
    }

    public function setTemplate(PurchaseRouteTemplate $template): static
    {
        $this->template = $template;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function setUpdatedBy(?User $updatedBy): static
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }
}
