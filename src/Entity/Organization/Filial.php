<?php

namespace App\Entity\Organization;

use App\Repository\Organization\FilialRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FilialRepository::class)]
class Filial extends AbstractOrganizationWithDetails
{
}
