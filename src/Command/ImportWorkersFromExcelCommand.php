<?php

namespace App\Command;

use App\Entity\Organization\Department;
use App\Entity\Organization\Organization;
use App\Entity\User\User;
use App\Entity\User\Worker;
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
        $this
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $arg1 = $input->getArgument('arg1');

        if ($arg1) {
            $io->note(sprintf('You passed an argument: %s', $arg1));
        }

        if ($input->getOption('option1')) {
            // ...
        }


        $filePath = $this->privateUploadDir . '/documents/1c_files/Workers_1c.xlsx';

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
                $organization->setShortName('bla');
                $organization->setFullName($row[4]);
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
                $department->organization = $organization;
                $department->setShortName('bal');
                $department->setFullName($value);
                $department->organization = $organization;
                $this->entityManager->persist($department);
                $this->entityManager->flush();
                echo '=== department --- ' . $value . PHP_EOL;
                continue;
            }

            if (is_numeric($value)) {
                $user = new User();
                $fioParts = preg_split('/\s+/u', trim((string) ($row[1] ?? '')), 3, PREG_SPLIT_NO_EMPTY);
                $lastName = $fioParts[0] ?? '';
                $firstName = $fioParts[1] ?? '';
                $patronymic = $fioParts[2] ?? '';

                $user->setFirstName($firstName);
                $user->setLastName($lastName);
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
                $worker->setProfession((string) ($row[9] ?? ''));
                $user->setWorker($worker);

                $this->entityManager->persist($user);
                $this->entityManager->flush();
            }
        }



        $io->success('You have a new command! Now make it your own! Pass --help to see your options.');

        return Command::SUCCESS;
    }
}
