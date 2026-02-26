<?php

namespace App\Entity\Organization;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity]
#[UniqueEntity(
    fields: ['shortName', 'fullName'],
    message: 'Организация с таким полным названием уже существует.',
    repositoryMethod: 'findOneByFullName',
    ignoreNull: false
)]
class Organization extends AbstractOrganizationWithDetails
{
    /* ,
     * // >Misha
     *
     * inn
     * kpp
     * ogrn
     * registration date
     * registration organ
     * bank name
     * bank number
     * type nalogoobloghenia array  --- ???
     *
     *
     *
     * inn  string 12
     * kpp  string 9
     * ogrn string 15
     * registration date
     * registration organ
     * bank name
     * bank number
     * type nalogoobloghenia array  --- ???
     *
     *
     * */
}
