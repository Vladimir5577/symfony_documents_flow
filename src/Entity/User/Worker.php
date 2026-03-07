<?php

namespace App\Entity\User;

use App\Enum\WorkerStatus;
use App\Repository\User\WorkerRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkerRepository::class)]
class Worker
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: User::class, inversedBy: 'worker')]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE', unique: true)]
    private User $user;

    #[ORM\Column(length: 255)]
    private ?string $profession = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(name: 'worker_status', length: 50, enumType: WorkerStatus::class, options: ['default' => 'AT_WORK'])]
    private WorkerStatus $workerStatus = WorkerStatus::AT_WORK;

    #[ORM\Column(name: 'hired_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $hiredAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getProfession(): ?string
    {
        return $this->profession;
    }

    public function setProfession(string $profession): static
    {
        $this->profession = $profession;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getWorkerStatus(): WorkerStatus
    {
        return $this->workerStatus;
    }

    public function setWorkerStatus(WorkerStatus $workerStatus): static
    {
        $this->workerStatus = $workerStatus;

        return $this;
    }

    /**
     * Дата приёма на работу (только дата, без времени).
     */
    public function getHiredAt(): ?\DateTimeImmutable
    {
        return $this->hiredAt;
    }

    public function setHiredAt(?\DateTimeImmutable $hiredAt): static
    {
        $this->hiredAt = $hiredAt;

        return $this;
    }

    /**
     * Дата приёма в формате ДД.ММ.ГГГГ (без часов, минут, секунд).
     */
    public function getHiredAtFormatted(): ?string
    {
        return $this->hiredAt?->format('d.m.Y');
    }
}
