<?php

namespace App\DataFixtures;

use App\Entity\Organization\AbstractOrganizationWithDetails;
use App\Entity\Organization\Department;
use App\Entity\Organization\Filial;
use App\Entity\Organization\Organization;
use App\Enum\TaxType;
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
        echo "  [OrganizationFixtures] Загрузка организаций...\n";
        $organizationsData = [
            [
                'short_name' => 'ООО "ДонСтройМаш"',
                'full_name' => 'Донецкая машиностроительная компания',
                'description' => 'Основная строительная организация',
                'legal_address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1',
                'actual_address' => 'г. Ростов-на-Дону, ул. Строительная, д. 1',
                'phone' => '+7 (863) 123-45-67',
                'email' => 'info@donstroymash.ru',
                'inn' => '6164000000',
                'kpp' => '616401001',
                'ogrn' => '1166164000001',
                'registration_date' => new \DateTimeImmutable('2010-05-15'),
                'registration_organ' => 'ИФНС России по Ростовской области',
                'bank_name' => 'ПАО Сбербанк',
                'bik' => '046577674',
                'bank_account' => '40702810000000000001',
                'tax_type' => TaxType::USN_INCOME,
            ],
            [
                'short_name' => 'ООО "СтройКомплекс"',
                'full_name' => 'Комплексное строительное предприятие',
                'description' => 'Комплексное строительное предприятие',
                'legal_address' => 'г. Ростов-на-Дону, пр. Ленина, д. 50',
                'actual_address' => 'г. Ростов-на-Дону, пр. Ленина, д. 50',
                'phone' => '+7 (863) 234-56-78',
                'email' => 'contact@stroykomplex.ru',
                'inn' => '6165000000',
                'kpp' => '616501001',
                'ogrn' => '1166165000001',
                'registration_date' => new \DateTimeImmutable('2012-08-20'),
                'registration_organ' => 'ИФНС России по Ростовской области',
                'bank_name' => 'ПАО Сбербанк',
                'bik' => '046577674',
                'bank_account' => '40702810000000000002',
                'tax_type' => TaxType::OSNO,
            ],
        ];

        // Чанк 1: организации (нужны ID для филиалов)
        $organizations = [];
        foreach ($organizationsData as $index => $data) {
            $org = new Organization();
            $org->setShortName($data['short_name']);
            $org->setFullName($data['full_name']);
            $org->setDescription($data['description']);
            $org->setLegalAddress($data['legal_address'] ?? null);
            $org->setActualAddress($data['actual_address'] ?? null);
            $org->setPhone($data['phone']);
            $org->setEmail($data['email']);
            $this->setRekvizity($org, $data);
            $manager->persist($org);
            $organizations[$index] = $org;
            $this->addReference('organization_' . ($index + 1), $org);
        }
        $manager->flush();
        echo "  [OrganizationFixtures] Организации сохранены (" . count($organizations) . ")\n";

        // Чанки 2, 3, …: по одной организации — все её филиалы и департаменты
        $filialSuffixes = ['Юг', 'Север', 'Восток', 'Запад', 'Центр'];
        $filialRefIndex = 0;
        $deptRefIndex = 0;
        foreach ($organizations as $orgIndex => $org) {
            $orgShortName = $organizationsData[$orgIndex]['short_name'];
            for ($f = 0; $f < self::FILIALS_PER_ORGANIZATION; $f++) {
                $suffix = $filialSuffixes[$f];
                $filial = new Filial();
                $filial->setShortName($orgShortName . ' — Филиал «' . $suffix . '»');
                $filial->setFullName($organizationsData[$orgIndex]['full_name'] . ' — Филиал «' . $suffix . '»');
                $filial->setDescription('Филиал организации');
                $filialAddr = 'г. Ростов-на-Дону, ул. Филиальная, д. ' . ($orgIndex * 10 + $f + 1);
                $filial->setLegalAddress($filialAddr);
                $filial->setActualAddress($filialAddr);
                $filial->setPhone('+7 (863) 5' . str_pad((string) ($filialRefIndex + 1), 2, '0', STR_PAD_LEFT) . '-00-' . str_pad((string) ($f + 1), 2, '0', STR_PAD_LEFT));
                $filial->setEmail('filial' . ($filialRefIndex + 1) . '@org' . ($orgIndex + 1) . '.local');
                $filial->setParent($org);
                $this->setRekvizity($filial, $organizationsData[$orgIndex]);
                $org->addChildOrganization($filial);
                $manager->persist($filial);
                $this->addReference('filial_' . (++$filialRefIndex), $filial);

                $deptCount = random_int(self::DEPARTMENTS_PER_FILIAL_MIN, self::DEPARTMENTS_PER_FILIAL_MAX);
                for ($d = 0; $d < $deptCount; $d++) {
                    $name = self::DEPARTMENT_NAMES[$d % count(self::DEPARTMENT_NAMES)];
                    $department = new Department();
                    $department->setShortName($name);
                    $department->setFullName($name);
                    $department->setParent($filial);
                    $filial->addChildOrganization($department);
                    $manager->persist($department);
                    $this->addReference('department_' . (++$deptRefIndex), $department);
                }
            }
            $manager->flush();
            echo "  [OrganizationFixtures] Филиалы и департаменты сохранены: " . $organizationsData[$orgIndex]['short_name'] . "\n";
        }
        echo "  [OrganizationFixtures] Готово.\n";
    }

    /**
     * Заполняет реквизиты и банковские данные организации/филиала из массива (ключи: inn, kpp, ogrn, registration_date, registration_organ, bank_name, bik, bank_account, tax_type).
     */
    private function setRekvizity(AbstractOrganizationWithDetails $org, array $data): void
    {
        $org->setInn($data['inn'] ?? null);
        $org->setKpp($data['kpp'] ?? null);
        $org->setOgrn($data['ogrn'] ?? null);
        $org->setRegistrationDate($data['registration_date'] ?? null);
        $org->setRegistrationOrgan($data['registration_organ'] ?? null);
        $org->setBankName($data['bank_name'] ?? null);
        $org->setBik($data['bik'] ?? null);
        $org->setBankAccount($data['bank_account'] ?? null);
        $org->setTaxType($data['tax_type'] ?? null);
    }
}
