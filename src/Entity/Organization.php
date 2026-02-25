<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

#[ORM\Entity]
#[UniqueEntity(
    fields: ['name'],
    message: 'Организация с таким названием уже существует.',
    repositoryMethod: 'findOneByName',
    ignoreNull: false
)]
class Organization extends AbstractOrganizationWithDetails
{
    /*
     * inn
     * kpp
     * ogrn
     * registration date
     * address
     * phone
     * email
     *
     * */
}
