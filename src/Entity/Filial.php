<?php

namespace App\Entity;

use App\Repository\FilialRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FilialRepository::class)]
class Filial extends AbstractOrganizationWithDetails
{
}
