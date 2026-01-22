<?php

namespace App\DataFixtures;

use App\Entity\Department;
use App\Entity\DepartmentDivision;
use App\Entity\Organization;
use App\Entity\User;
use App\Repository\RoleRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RoleRepository $roleRepository
    ) {
    }

    public static function getGroups(): array
    {
        return ['users'];
    }

    public function getDependencies(): array
    {
        return [
            RoleFixtures::class,
            OrganizationFixtures::class,
            DepartmentFixtures::class,
            DepartmentDivisionFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        // Получаем первую организацию, департамент и подразделение
        $organization = $this->getReference('organization_1', Organization::class);
        $department = $this->getReference('department_1', Department::class);
        $departmentDivision = $this->getReference('department_division_1', DepartmentDivision::class);

        $admin = new User();
        $admin->setLogin('admin.0001');
        $admin->setLastname('Админ');
        $admin->setFirstname('Системный');
        $admin->setOrganization($organization);
        $admin->setDepartment($department);
        $admin->setDepartmentDivision($departmentDivision);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, '1234'));
        $this->attachRole($admin, 'ROLE_ADMIN');
        $manager->persist($admin);

        $hr = new User();
        $hr->setLogin('hr.0001');
        $hr->setLastname('Кадровик');
        $hr->setFirstname('Главный');
        $hr->setOrganization($organization);
        $hr->setDepartment($department);
        $hr->setDepartmentDivision($departmentDivision);
        $hr->setPassword($this->passwordHasher->hashPassword($hr, '1234'));
        $this->attachRole($hr, 'ROLE_HR');
        $manager->persist($hr);

        $editor = new User();
        $editor->setLogin('editor.0001');
        $editor->setLastname('Редактор');
        $editor->setFirstname('Главный');
        $editor->setOrganization($organization);
        $editor->setDepartment($department);
        $editor->setDepartmentDivision($departmentDivision);
        $editor->setPassword($this->passwordHasher->hashPassword($editor, '1234'));
        $this->attachRole($editor, 'ROLE_EDITOR');
        $manager->persist($editor);

        $user = new User();
        $user->setLogin('user.0001');
        $user->setLastname('Пользователь');
        $user->setFirstname('Обычный');
        $user->setOrganization($organization);
        $user->setDepartment($department);
        $user->setDepartmentDivision($departmentDivision);
        $user->setPassword($this->passwordHasher->hashPassword($user, '1234'));
        $this->attachRole($user, 'ROLE_USER');
        $manager->persist($user);

        // Store initial users for boss assignment
        $allUsers = [$admin, $hr, $editor, $user];

        // Generate 100 additional users
        $lastnames = ['Иванов', 'Петров', 'Сидоров', 'Смирнов', 'Кузнецов', 'Попов', 'Соколов', 
                     'Лебедев', 'Козлов', 'Новиков', 'Морозов', 'Петухов', 'Волков', 'Соловьев', 
                     'Васильев', 'Зайцев', 'Павлов', 'Семенов', 'Голубев', 'Виноградов', 'Богданов', 
                     'Воробьев', 'Федоров', 'Михайлов', 'Белов', 'Тарасов', 'Беляев', 'Комаров', 
                     'Орлов', 'Киселев', 'Макаров', 'Андреев', 'Ковалев', 'Ильин', 'Гусев', 'Титов', 
                     'Кузьмин', 'Кудрявцев', 'Баранов', 'Куликов', 'Алексеев', 'Степанов', 'Яковлев', 
                     'Сорокин', 'Сергеев', 'Романов', 'Захаров', 'Борисов', 'Королев', 'Герасимов', 
                     'Пономарев', 'Григорьев', 'Лазарев', 'Медведев', 'Ершов', 'Никитин', 'Соболев'];
        
        $firstnames = ['Александр', 'Дмитрий', 'Максим', 'Сергей', 'Андрей', 'Алексей', 'Артем', 
                      'Илья', 'Кирилл', 'Михаил', 'Никита', 'Матвей', 'Роман', 'Егор', 'Арсений', 
                      'Иван', 'Денис', 'Евгений', 'Тимур', 'Владислав', 'Игорь', 'Владимир', 
                      'Павел', 'Руслан', 'Марк', 'Лев', 'Анна', 'Мария', 'Елена', 'Ольга', 'Татьяна', 
                      'Наталья', 'Екатерина', 'Ирина', 'Светлана', 'Юлия', 'Анастасия', 'Дарья', 
                      'Валерия', 'Полина', 'Виктория', 'Ксения', 'София', 'Александра', 'Василиса', 
                      'Вероника', 'Маргарита', 'Диана', 'Алиса', 'Елизавета', 'Арина', 'Милана'];
        
        $patronymics = ['Александрович', 'Дмитриевич', 'Максимович', 'Сергеевич', 'Андреевич', 
                       'Алексеевич', 'Артемович', 'Ильич', 'Кириллович', 'Михайлович', 'Никитич', 
                       'Матвеевич', 'Романович', 'Егорович', 'Арсеньевич', 'Иванович', 'Денисович', 
                       'Евгеньевич', 'Тимурович', 'Владиславович', 'Игоревич', 'Владимирович', 
                       'Павлович', 'Русланович', 'Маркович', 'Львович', 'Александровна', 'Дмитриевна', 
                       'Максимовна', 'Сергеевна', 'Андреевна', 'Алексеевна', 'Артемовна', 'Ильинична', 
                       'Кирилловна', 'Михайловна', 'Никитична', 'Матвеевна', 'Романовна', 'Егоровна', 
                       'Арсеньевна', 'Ивановна', 'Денисовна', 'Евгеньевна', 'Тимуровна', 'Владиславовна', 
                       'Игоревна', 'Владимировна', 'Павловна', 'Руслановна', 'Марковна', 'Львовна'];

        // Получаем все доступные подразделения для случайного распределения
        $divisions = [];
        for ($i = 1; $i <= 24; $i++) {
            try {
                $divisions[] = $this->getReference('department_division_' . $i, DepartmentDivision::class);
            } catch (\Exception $e) {
                // Если подразделение не найдено, пропускаем
                break;
            }
        }

        for ($i = 2; $i <= 101; $i++) {
            $user = new User();
            $login = sprintf('user.%04d', $i);
            $user->setLogin($login);
            $user->setLastname($lastnames[array_rand($lastnames)]);
            $user->setFirstname($firstnames[array_rand($firstnames)]);
            
            // Random patronymic (not always set)
            if (rand(0, 100) < 80) {
                $user->setPatronymic($patronymics[array_rand($patronymics)]);
            }
            
            // Random phone number
            $user->setPhone(sprintf('+7(%03d) %03d-%02d-%02d', 
                rand(900, 999), 
                rand(100, 999), 
                rand(10, 99), 
                rand(10, 99)
            ));
            
            // Random password (all use same password for testing)
            $user->setPassword($this->passwordHasher->hashPassword($user, '1234'));
            
            // Random active status (90% active)
            $user->setIsActive(rand(0, 100) < 90);
            
            // Assign random organization, department and division
            if (!empty($divisions)) {
                $randomDivision = $divisions[array_rand($divisions)];
                $user->setDepartmentDivision($randomDivision);
                $user->setDepartment($randomDivision->getDepartment());
                $user->setOrganization($randomDivision->getDepartment()->getOrganization());
            } else {
                // Fallback to first organization/department/division if no divisions found
                $user->setOrganization($organization);
                $user->setDepartment($department);
                $user->setDepartmentDivision($departmentDivision);
            }
            
            // Assign ROLE_USER
            $this->attachRole($user, 'ROLE_USER');
            
            // Randomly assign boss (30% chance) from previously created users
            if (rand(0, 100) < 30 && !empty($allUsers)) {
                $boss = $allUsers[array_rand($allUsers)];
                $user->setBoss($boss);
            }
            
            $manager->persist($user);
            $allUsers[] = $user;
        }

        $manager->flush();
    }

    private function attachRole(User $user, string $roleName): void
    {
        $role = $this->roleRepository->findOneBy(['name' => $roleName]);
        if ($role) {
            $user->addRoleEntity($role);
        }
    }
}
