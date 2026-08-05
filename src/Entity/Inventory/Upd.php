<?php

declare(strict_types=1);

namespace App\Entity\Inventory;

use App\Entity\Organization\AbstractOrganization;
use App\Entity\User\User;
use App\Repository\Inventory\UpdRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Универсальный передаточный документ — то, по чему имущество приехало.
 *
 * Заводится раньше позиций: по одному УПД приходит партия, и документ должен
 * существовать до того, как к нему привяжут первую вещь. Поэтому это отдельная
 * сущность, а не файл на карточке товара — иначе один и тот же скан лёг бы
 * копией к каждой из тридцати позиций, и на вопрос «что приехало по УПД №123»
 * ответить было бы нечем.
 *
 * Организация — получатель поставки и она же основа видимости: документ целиком
 * виден админу этой организации и её поддерева, главному администратору — все.
 * Ответственный за категорию документ не открывает, но номер и дату на своей
 * позиции видит, иначе её карточка выглядела бы сломанной.
 */
#[ORM\Entity(repositoryClass: UpdRepository::class)]
#[ORM\Table(name: 'inventory_upd')]
#[ORM\Index(name: 'idx_inventory_upd_org_number', columns: ['organization_id', 'number'])]
class Upd
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Уникальности намеренно нет. Разные поставщики законно выдают документы
     * с одинаковыми номерами, и уникальный индекс сделал бы вторую такую поставку
     * незаводимой. Индекс на (организация, номер) — только чтобы искать.
     */
    #[ORM\Column(length: 64)]
    private string $number;

    #[ORM\Column(name: 'document_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    /**
     * Строкой: справочника контрагентов в проекте нет, а заводить его ради одного
     * поля — это отдельная история с ведением, слиянием дублей и правами.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $supplier = null;

    #[ORM\ManyToOne(targetEntity: AbstractOrganization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private AbstractOrganization $organization;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    #[Gedmo\Timestampable(on: 'create')]
    private ?\DateTimeImmutable $createdAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function setNumber(string $number): static
    {
        $this->number = $number;

        return $this;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): static
    {
        $this->date = $date;

        return $this;
    }

    public function getSupplier(): ?string
    {
        return $this->supplier;
    }

    public function setSupplier(?string $supplier): static
    {
        $this->supplier = $supplier;

        return $this;
    }

    public function getOrganization(): AbstractOrganization
    {
        return $this->organization;
    }

    public function setOrganization(AbstractOrganization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Подпись для истории товара и выпадающих списков: «УПД №123 от 01.02.2026».
     */
    public function getLabel(): string
    {
        return sprintf('УПД №%s от %s', $this->number, $this->date->format('d.m.Y'));
    }
}
