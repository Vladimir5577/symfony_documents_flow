<?php

namespace App\DataFixtures;

use App\Entity\Department;
use App\Entity\Filial;
use App\Entity\Organization;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class OrganizationFixtures extends Fixture
{
    private const FILIALS_PER_ORGANIZATION = 5;
    private const DEPARTMENTS_PER_FILIAL_MIN = 3;
    private const DEPARTMENTS_PER_FILIAL_MAX = 8;

    private const DEPARTMENT_NAMES = [
        'Бухгалтерия',
        'Отдел кадров',
        'Снабжение',
        'Производство',
        'Логистика',
        'IT-отдел',
        'Отдел продаж',
        'Охрана труда',
    ];

    public function load(ObjectManager $manager): void
    {
        $organizationsData = [
            [
                'name' => 'ООО "ДонСтройМаш"',
                'description' => 'Основная строительная организация',
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
        ];

        $organizations = [];
        foreach ($organizationsData as $index => $data) {
            $org = new Organization();
            $org->setName($data['name']);
            $org->setDescription($data['description']);
            $org->setAddress($data['address']);
            $org->setPhone($data['phone']);
            $org->setEmail($data['email']);
            $manager->persist($org);
            $organizations[$index] = $org;
            $this->addReference('organization_' . ($index + 1), $org);
        }

        $filialSuffixes = ['Юг', 'Север', 'Восток', 'Запад', 'Центр'];
        $filialRefIndex = 0;
        $deptRefIndex = 0;
        foreach ($organizations as $orgIndex => $org) {
            $orgShortName = preg_replace('/ООО\s*"([^"]+)".*/', '$1', $organizationsData[$orgIndex]['name']);
            for ($f = 0; $f < self::FILIALS_PER_ORGANIZATION; $f++) {
                $suffix = $filialSuffixes[$f];
                $filial = new Filial();
                $filial->setName($orgShortName . ' — Филиал «' . $suffix . '»');
                $filial->setDescription('Филиал организации');
                $filial->setAddress('г. Ростов-на-Дону, ул. Филиальная, д. ' . ($orgIndex * 10 + $f + 1));
                $filial->setPhone('+7 (863) 5' . str_pad((string) ($filialRefIndex + 1), 2, '0', STR_PAD_LEFT) . '-00-' . str_pad((string) ($f + 1), 2, '0', STR_PAD_LEFT));
                $filial->setEmail('filial' . ($filialRefIndex + 1) . '@org' . ($orgIndex + 1) . '.local');
                $filial->setParent($org);
                $org->addChildOrganization($filial);
                $manager->persist($filial);
                $this->addReference('filial_' . (++$filialRefIndex), $filial);

                $deptCount = random_int(self::DEPARTMENTS_PER_FILIAL_MIN, self::DEPARTMENTS_PER_FILIAL_MAX);
                for ($d = 0; $d < $deptCount; $d++) {
                    $name = self::DEPARTMENT_NAMES[$d % count(self::DEPARTMENT_NAMES)];
                    $department = new Department();
                    $department->setName($name);
                    $department->setParent($filial);
                    $filial->addChildOrganization($department);
                    $manager->persist($department);
                    $this->addReference('department_' . (++$deptRefIndex), $department);
                }
            }
        }

        $manager->flush();
    }
}
