<?php

namespace App\Command;

use App\Entity\Organization\Department;
use App\Entity\Organization\Organization;
use App\Entity\User\User;
use App\Entity\User\Worker;
use App\Enum\WorkerStatus;
use App\Service\User\LoginGeneratorService;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:import-workers-from-excel',
    description: 'Add a short description for your command',
)]
class ImportWorkersFromExcelCommand extends Command
{
    public function __construct(
        #[Autowire('%private_upload_dir%')]
        private readonly string $privateUploadDir,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoginGeneratorService $loginGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // пока без аргументов/опций
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $this->privateUploadDir . '/documents/1c_files/Workers_1c_2.xlsx';

        if (!is_readable($filePath)) {
            $io->error(sprintf('File not found or not readable: %s', $filePath));
            return Command::FAILURE;
        }

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray();

        $organization = null;
        $department = null;

        foreach ($rows as $row) {
            if ($row[0] == 'Организация') {
                $organization = new Organization();
                $organization->setName($row[4]);
                $this->entityManager->persist($organization);
                $this->entityManager->flush();
                echo 'Организация сохранена --- ' . $row[0] . PHP_EOL;
                continue;
            }

            if ($row[0] == 'Всего сотрудников') continue;
            if ($row[0] == 'Подразделение') continue;
            if ($row[0] == '№') continue;

            $value = trim((string) ($row[0] ?? ''));
            if ($value === '') {
                continue;
            }
            if (!is_numeric($value)) {
                $department = new Department();
                $department->setName($value);
                $department->setParent($organization);
                $this->entityManager->persist($department);
                $this->entityManager->flush();
                echo '=== department --- ' . $value . PHP_EOL;
                continue;
            }

            if (is_numeric($value)) {

                $userData = array_filter($row, fn($v) => $v !== null);
                $userData = array_values($userData);

                $userName = $userData[1];
                $profession = $userData[2];
                $workerSchedual = $userData[3];
                $hiredAt = $userData[4];
                $birthDay = $userData[5];
                $workerStatus = $userData[6];

                echo '------------- ' . $userName . PHP_EOL;

                $user = new User();
                $fioParts = preg_split('/\s+/u', trim((string) ($userName ?? '')), 3, PREG_SPLIT_NO_EMPTY);
                $lastName = $fioParts[0] ?? '';
                $firstName = $fioParts[1] ?? '';
                $patronymic = $fioParts[2] ?? '';

                $user->setFirstname($firstName);
                $user->setLastname($lastName);
                $user->setPatronymic($patronymic);

                $user->setOrganization($department);
                $login = $this->loginGenerator->generateLoginBase($lastName, $firstName);
                $user->setLogin($login);
                $user->setPassword('$2y$13$QG.67c6h2u0y2e0YyRaWHOqZVgGAoLo0jOix4TaJLckj36PaQaQVO');
                $birthDayStr = trim((string) ($row[22] ?? ''), " \t\n\r\0\x0B'");
                $birthDay = $birthDayStr !== ''
                    ? \DateTimeImmutable::createFromFormat('d.m.Y', $birthDayStr) ?: null
                    : null;
                $user->setBirthDay($birthDay);

                $worker = new Worker();
                $worker->setProfession((string) ($profession ?? ''));
                $hiredAtStr = trim((string) ($row[23] ?? ''), " \t\n\r\0\x0B'");
                $hiredAt = $hiredAt !== ''
                    ? \DateTimeImmutable::createFromFormat('d.m.Y', $hiredAtStr) ?: null
                    : null;
                $worker->setHiredAt($hiredAt);

                switch ($workerStatus) {
                    case 'Работа':
                        $workerStatus = WorkerStatus::AT_WORK;
                        break;
                    case 'Отпуск основной':
                        $workerStatus = WorkerStatus::ANNUAL_LEAVE;
                        break;
                    case 'Отпуск неоплачиваемый по разрешению работодателя':
                        $workerStatus = WorkerStatus::UNPAID_LEAVE;
                        break;
                    case 'Отпуск по беременности и родам':
                        $workerStatus = WorkerStatus::MATERNITY_LEAVE;
                        break;
                    case 'Отпуск по уходу за ребенком':
                        $workerStatus = WorkerStatus::PARENTAL_LEAVE;
                        break;
                    case 'В командировке':
                        $workerStatus = WorkerStatus::ON_BUSINESS_TRIP;
                        break;
                    case 'Болезнь':
                        $workerStatus = WorkerStatus::SICK_LEAVE;
                        break;
                    case 'Удалённая работа':
                        $workerStatus = WorkerStatus::REMOTE;
                        break;
                    case 'Выходной / отгул':
                        $workerStatus = WorkerStatus::DAY_OFF;
                        break;
                    case 'Дежурство':
                        $workerStatus = WorkerStatus::ON_DUTY;
                        break;
                    case 'Не на связи':
                        $workerStatus = WorkerStatus::UNAVAILABLE;
                        break;
                    case 'Трудовой договор приостановлен':
                        $workerStatus = WorkerStatus::CONTRACT_SUSPENDED;
                        break;
                    case 'Прогул':
                        $workerStatus = WorkerStatus::UNEXCUSED_ABSENCE;
                        break;
                    case 'Отпуск учебный оплачиваемый':
                        $workerStatus = WorkerStatus::EDUCATIONAL_PAID_LEAVE;
                        break;
                }

                $worker->setWorkerStatus($workerStatus);

                $user->setWorker($worker);

                $this->entityManager->persist($user);
                $this->entityManager->flush();
            }
        }

        $io->success('You have a new command! Now make it your own! Pass --help to see your options.');

        return Command::SUCCESS;
    }
}
