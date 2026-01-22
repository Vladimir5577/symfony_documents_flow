<?php

namespace App\DataFixtures;

use App\Entity\Department;
use App\Entity\Organization;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class DepartmentFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // Департаменты для первой организации (ДонСтройМаш)
        $departments = [
            [
                'organization_ref' => 'organization_1',
                'name' => 'Отдел производства',
                'description' => 'Отдел, отвечающий за производство строительных материалов',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, корп. А',
                'phone' => '+7 (863) 123-45-68',
                'email' => 'production@donstroymash.ru',
            ],
            [
                'organization_ref' => 'organization_1',
                'name' => 'Отдел продаж',
                'description' => 'Отдел продаж и работы с клиентами',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, корп. Б',
                'phone' => '+7 (863) 123-45-69',
                'email' => 'sales@donstroymash.ru',
            ],
            [
                'organization_ref' => 'organization_1',
                'name' => 'Отдел логистики',
                'description' => 'Отдел доставки и складского учета',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, склад',
                'phone' => '+7 (863) 123-45-70',
                'email' => 'logistics@donstroymash.ru',
            ],
            [
                'organization_ref' => 'organization_1',
                'name' => 'Отдел кадров',
                'description' => 'Отдел управления персоналом',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, офис 201',
                'phone' => '+7 (863) 123-45-71',
                'email' => 'hr@donstroymash.ru',
            ],
            // Департаменты для второй организации (СтройКомплекс)
            [
                'organization_ref' => 'organization_2',
                'name' => 'Отдел строительства',
                'description' => 'Отдел управления строительными проектами',
                'address' => 'г. Ростов-на-Дону, пр. Ленина, д. 50, офис 1',
                'phone' => '+7 (863) 234-56-79',
                'email' => 'construction@stroykomplex.ru',
            ],
            [
                'organization_ref' => 'organization_2',
                'name' => 'Отдел проектирования',
                'description' => 'Отдел разработки проектной документации',
                'address' => 'г. Ростов-на-Дону, пр. Ленина, д. 50, офис 2',
                'phone' => '+7 (863) 234-56-80',
                'email' => 'design@stroykomplex.ru',
            ],
            // Департаменты для третьей организации (МашСтрой)
            [
                'organization_ref' => 'organization_3',
                'name' => 'Отдел разработки',
                'description' => 'Отдел разработки и конструирования техники',
                'address' => 'г. Ростов-на-Дону, ул. Промышленная, д. 25, корп. 1',
                'phone' => '+7 (863) 345-67-90',
                'email' => 'development@mashstroy.ru',
            ],
            [
                'organization_ref' => 'organization_3',
                'name' => 'Отдел сборки',
                'description' => 'Отдел сборки и тестирования оборудования',
                'address' => 'г. Ростов-на-Дону, ул. Промышленная, д. 25, цех 1',
                'phone' => '+7 (863) 345-67-91',
                'email' => 'assembly@mashstroy.ru',
            ],
            // Департаменты для четвертой организации (СтройМатериалы)
            [
                'organization_ref' => 'organization_4',
                'name' => 'Отдел закупок',
                'description' => 'Отдел закупки строительных материалов',
                'address' => 'г. Ростов-на-Дону, ул. Торговая, д. 10, офис 101',
                'phone' => '+7 (863) 456-78-91',
                'email' => 'purchasing@stroymaterialy.ru',
            ],
            [
                'organization_ref' => 'organization_4',
                'name' => 'Отдел продаж',
                'description' => 'Отдел оптовых и розничных продаж',
                'address' => 'г. Ростов-на-Дону, ул. Торговая, д. 10, офис 102',
                'phone' => '+7 (863) 456-78-92',
                'email' => 'sales@stroymaterialy.ru',
            ],
            // Департаменты для пятой организации (СтройПроект)
            [
                'organization_ref' => 'organization_5',
                'name' => 'Архитектурный отдел',
                'description' => 'Отдел архитектурного проектирования',
                'address' => 'г. Ростов-на-Дону, ул. Архитекторов, д. 5, офис 301',
                'phone' => '+7 (863) 567-89-02',
                'email' => 'architecture@stroyproekt.ru',
            ],
            [
                'organization_ref' => 'organization_5',
                'name' => 'Отдел инженерных систем',
                'description' => 'Отдел проектирования инженерных систем',
                'address' => 'г. Ростов-на-Дону, ул. Архитекторов, д. 5, офис 302',
                'phone' => '+7 (863) 567-89-03',
                'email' => 'engineering@stroyproekt.ru',
            ],
        ];

        foreach ($departments as $index => $deptData) {
            $organization = $this->getReference($deptData['organization_ref'], Organization::class);
            
            $department = new Department();
            $department->setOrganization($organization);
            $department->setName($deptData['name']);
            $department->setDescription($deptData['description']);
            $department->setAddress($deptData['address']);
            $department->setPhone($deptData['phone']);
            $department->setEmail($deptData['email']);

            $manager->persist($department);
            
            // Сохраняем ссылку на департамент для использования в других фикстурах
            $this->addReference('department_' . ($index + 1), $department);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            OrganizationFixtures::class,
        ];
    }
}
