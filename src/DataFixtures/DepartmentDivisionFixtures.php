<?php

namespace App\DataFixtures;

use App\Entity\Department;
use App\Entity\DepartmentDivision;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class DepartmentDivisionFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        $divisions = [
            // Подразделения для Отдела производства (department_1)
            [
                'department_ref' => 'department_1',
                'name' => 'Цех производства бетона',
                'description' => 'Производство бетонных смесей различных марок',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, цех 1',
                'phone' => '+7 (863) 123-45-72',
                'email' => 'concrete@donstroymash.ru',
            ],
            [
                'department_ref' => 'department_1',
                'name' => 'Цех производства ЖБИ',
                'description' => 'Производство железобетонных изделий',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, цех 2',
                'phone' => '+7 (863) 123-45-73',
                'email' => 'rbi@donstroymash.ru',
            ],
            // Подразделения для Отдела продаж (department_2)
            [
                'department_ref' => 'department_2',
                'name' => 'Группа оптовых продаж',
                'description' => 'Работа с крупными клиентами и оптовыми заказами',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, корп. Б, офис 101',
                'phone' => '+7 (863) 123-45-74',
                'email' => 'wholesale@donstroymash.ru',
            ],
            [
                'department_ref' => 'department_2',
                'name' => 'Группа розничных продаж',
                'description' => 'Работа с розничными клиентами',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, корп. Б, офис 102',
                'phone' => '+7 (863) 123-45-75',
                'email' => 'retail@donstroymash.ru',
            ],
            // Подразделения для Отдела логистики (department_3)
            [
                'department_ref' => 'department_3',
                'name' => 'Склад готовой продукции',
                'description' => 'Хранение и учет готовой продукции',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, склад 1',
                'phone' => '+7 (863) 123-45-76',
                'email' => 'warehouse@donstroymash.ru',
            ],
            [
                'department_ref' => 'department_3',
                'name' => 'Отдел доставки',
                'description' => 'Организация доставки продукции клиентам',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, диспетчерская',
                'phone' => '+7 (863) 123-45-77',
                'email' => 'delivery@donstroymash.ru',
            ],
            // Подразделения для Отдела кадров (department_4)
            [
                'department_ref' => 'department_4',
                'name' => 'Группа подбора персонала',
                'description' => 'Рекрутинг и подбор сотрудников',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, офис 201',
                'phone' => '+7 (863) 123-45-78',
                'email' => 'recruitment@donstroymash.ru',
            ],
            [
                'department_ref' => 'department_4',
                'name' => 'Группа обучения и развития',
                'description' => 'Обучение и развитие персонала',
                'address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1, офис 202',
                'phone' => '+7 (863) 123-45-79',
                'email' => 'training@donstroymash.ru',
            ],
            // Подразделения для Отдела строительства (department_5)
            [
                'department_ref' => 'department_5',
                'name' => 'Участок гражданского строительства',
                'description' => 'Строительство жилых и общественных зданий',
                'address' => 'г. Ростов-на-Дону, пр. Ленина, д. 50, офис 1-1',
                'phone' => '+7 (863) 234-56-81',
                'email' => 'civil@stroykomplex.ru',
            ],
            [
                'department_ref' => 'department_5',
                'name' => 'Участок промышленного строительства',
                'description' => 'Строительство промышленных объектов',
                'address' => 'г. Ростов-на-Дону, пр. Ленина, д. 50, офис 1-2',
                'phone' => '+7 (863) 234-56-82',
                'email' => 'industrial@stroykomplex.ru',
            ],
            // Подразделения для Отдела проектирования (department_6)
            [
                'department_ref' => 'department_6',
                'name' => 'Группа архитектурного проектирования',
                'description' => 'Разработка архитектурных решений',
                'address' => 'г. Ростов-на-Дону, пр. Ленина, д. 50, офис 2-1',
                'phone' => '+7 (863) 234-56-83',
                'email' => 'arch-design@stroykomplex.ru',
            ],
            [
                'department_ref' => 'department_6',
                'name' => 'Группа конструкторского проектирования',
                'description' => 'Разработка конструкторских решений',
                'address' => 'г. Ростов-на-Дону, пр. Ленина, д. 50, офис 2-2',
                'phone' => '+7 (863) 234-56-84',
                'email' => 'struct-design@stroykomplex.ru',
            ],
            // Подразделения для Отдела разработки (department_7)
            [
                'department_ref' => 'department_7',
                'name' => 'Конструкторское бюро',
                'description' => 'Разработка конструкций строительной техники',
                'address' => 'г. Ростов-на-Дону, ул. Промышленная, д. 25, корп. 1, офис 201',
                'phone' => '+7 (863) 345-67-92',
                'email' => 'design-bureau@mashstroy.ru',
            ],
            [
                'department_ref' => 'department_7',
                'name' => 'Отдел испытаний',
                'description' => 'Испытания и тестирование техники',
                'address' => 'г. Ростов-на-Дону, ул. Промышленная, д. 25, корп. 1, полигон',
                'phone' => '+7 (863) 345-67-93',
                'email' => 'testing@mashstroy.ru',
            ],
            // Подразделения для Отдела сборки (department_8)
            [
                'department_ref' => 'department_8',
                'name' => 'Сборочный цех №1',
                'description' => 'Сборка крупногабаритного оборудования',
                'address' => 'г. Ростов-на-Дону, ул. Промышленная, д. 25, цех 1',
                'phone' => '+7 (863) 345-67-94',
                'email' => 'assembly1@mashstroy.ru',
            ],
            [
                'department_ref' => 'department_8',
                'name' => 'Сборочный цех №2',
                'description' => 'Сборка малогабаритного оборудования',
                'address' => 'г. Ростов-на-Дону, ул. Промышленная, д. 25, цех 2',
                'phone' => '+7 (863) 345-67-95',
                'email' => 'assembly2@mashstroy.ru',
            ],
            // Подразделения для Отдела закупок (department_9)
            [
                'department_ref' => 'department_9',
                'name' => 'Группа закупок сырья',
                'description' => 'Закупка сырья и основных материалов',
                'address' => 'г. Ростов-на-Дону, ул. Торговая, д. 10, офис 101-1',
                'phone' => '+7 (863) 456-78-93',
                'email' => 'raw-materials@stroymaterialy.ru',
            ],
            [
                'department_ref' => 'department_9',
                'name' => 'Группа закупок оборудования',
                'description' => 'Закупка оборудования и инструментов',
                'address' => 'г. Ростов-на-Дону, ул. Торговая, д. 10, офис 101-2',
                'phone' => '+7 (863) 456-78-94',
                'email' => 'equipment@stroymaterialy.ru',
            ],
            // Подразделения для Отдела продаж (department_10)
            [
                'department_ref' => 'department_10',
                'name' => 'Группа оптовых продаж',
                'description' => 'Оптовая торговля строительными материалами',
                'address' => 'г. Ростов-на-Дону, ул. Торговая, д. 10, офис 102-1',
                'phone' => '+7 (863) 456-78-95',
                'email' => 'wholesale-sales@stroymaterialy.ru',
            ],
            [
                'department_ref' => 'department_10',
                'name' => 'Группа розничных продаж',
                'description' => 'Розничная торговля в магазинах',
                'address' => 'г. Ростов-на-Дону, ул. Торговая, д. 10, офис 102-2',
                'phone' => '+7 (863) 456-78-96',
                'email' => 'retail-sales@stroymaterialy.ru',
            ],
            // Подразделения для Архитектурного отдела (department_11)
            [
                'department_ref' => 'department_11',
                'name' => 'Группа жилой архитектуры',
                'description' => 'Проектирование жилых зданий',
                'address' => 'г. Ростов-на-Дону, ул. Архитекторов, д. 5, офис 301-1',
                'phone' => '+7 (863) 567-89-04',
                'email' => 'residential@stroyproekt.ru',
            ],
            [
                'department_ref' => 'department_11',
                'name' => 'Группа общественной архитектуры',
                'description' => 'Проектирование общественных зданий',
                'address' => 'г. Ростов-на-Дону, ул. Архитекторов, д. 5, офис 301-2',
                'phone' => '+7 (863) 567-89-05',
                'email' => 'public@stroyproekt.ru',
            ],
            // Подразделения для Отдела инженерных систем (department_12)
            [
                'department_ref' => 'department_12',
                'name' => 'Группа ОВКВ',
                'description' => 'Проектирование отопления, вентиляции и кондиционирования',
                'address' => 'г. Ростов-на-Дону, ул. Архитекторов, д. 5, офис 302-1',
                'phone' => '+7 (863) 567-89-06',
                'email' => 'hvac@stroyproekt.ru',
            ],
            [
                'department_ref' => 'department_12',
                'name' => 'Группа электроснабжения',
                'description' => 'Проектирование систем электроснабжения',
                'address' => 'г. Ростов-на-Дону, ул. Архитекторов, д. 5, офис 302-2',
                'phone' => '+7 (863) 567-89-07',
                'email' => 'electrical@stroyproekt.ru',
            ],
        ];

        foreach ($divisions as $index => $divData) {
            $department = $this->getReference($divData['department_ref'], Department::class);
            
            $division = new DepartmentDivision();
            $division->setDepartment($department);
            $division->setName($divData['name']);
            $division->setDescription($divData['description']);
            $division->setAddress($divData['address']);
            $division->setPhone($divData['phone']);
            $division->setEmail($divData['email']);

            $manager->persist($division);
            
            // Сохраняем ссылку на подразделение для использования в других фикстурах
            $this->addReference('department_division_' . ($index + 1), $division);
        }

        $manager->flush();
    }

    public function getDependencies(): array
    {
        return [
            DepartmentFixtures::class,
        ];
    }
}
