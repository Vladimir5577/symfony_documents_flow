<?php

namespace App\Entity\Organization;

use App\Repository\Organization\DepartmentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
class Department extends AbstractOrganization
{

}
