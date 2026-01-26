<?php

namespace App\DataFixtures;

use App\Entity\Organization;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class OrganizationFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $organizations = [
            [
                'name' => 'ООО "ДонСтройМаш"',
                'description' => 'Основная строительная организация, специализирующаяся на производстве строительных материалов и машин',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1',
                'phone' => '+7 (863) 123-45-67',
                'email' => 'info@donstroymash.ru',
            ],
            [
                'name' => 'ООО "СтройКомплекс"',
                'description' => 'Комплексное строительное предприятие',
                'address' => 'г. Ростов-на-Дону, пр. Ленина, д. 50',
                'phone' => '+7 (863) 234-56-78',
                'email' => 'contact@stroykomplex.ru',
            ],
            [
                'name' => 'ООО "МашСтрой"',
                'description' => 'Производство строительной техники и оборудования',
                'address' => 'г. Ростов-на-Дону, ул. Промышленная, д. 25',
                'phone' => '+7 (863) 345-67-89',
                'email' => 'office@mashstroy.ru',
            ],
            [
                'name' => 'ООО "СтройМатериалы"',
                'description' => 'Оптовая и розничная торговля строительными материалами',
                'address' => 'г. Ростов-на-Дону, ул. Торговая, д. 10',
                'phone' => '+7 (863) 456-78-90',
                'email' => 'sales@stroymaterialy.ru',
            ],
            [
                'name' => 'ООО "СтройПроект"',
                'description' => 'Проектирование и архитектурное бюро',
                'address' => 'г. Ростов-на-Дону, ул. Архитекторов, д. 5',
                'phone' => '+7 (863) 567-89-01',
                'email' => 'project@stroyproekt.ru',
            ],
            [
                'name' => 'Административная организация',
                'description' => 'Организация для системных администраторов',
                'address' => 'г. Ростов-на-Дону, ул. Административная, д. 1',
                'phone' => '+7 (863) 000-00-01',
                'email' => 'admin@system.local',
            ],
        ];

        foreach ($organizations as $index => $orgData) {
            $organization = new Organization();
            $organization->setName($orgData['name']);
            $organization->setDescription($orgData['description']);
            $organization->setAddress($orgData['address']);
            $organization->setPhone($orgData['phone']);
            $organization->setEmail($orgData['email']);

            $manager->persist($organization);
            
            // Сохраняем ссылку на организацию для использования в других фикстурах
            $this->addReference('organization_' . ($index + 1), $organization);
        }

        $manager->flush();
    }
}
