<?php

namespace App\Command;

use App\Entity\User\Role;
use App\Enum\UserRole;
use App\Repository\User\RoleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:roles:sync',
    description: 'Синхронизирует таблицу role с enum UserRole: создаёт недостающие записи и подписи (label), если они отличаются от enum.',
)]
class SyncRolesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RoleRepository $roleRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $maxSort = (int) $this->roleRepository->createQueryBuilder('r')
            ->select('COALESCE(MAX(r.sortOrder), 0)')
            ->getQuery()
            ->getSingleScalarResult();

        $created = [];
        $relabeled = [];

        foreach (UserRole::cases() as $case) {
            $role = $this->roleRepository->findOneBy(['name' => $case->value]);

            if ($role === null) {
                $maxSort++;
                $role = new Role($case);
                $role->setLabel($case->getLabel());
                $role->setSortOrder($maxSort);
                $this->entityManager->persist($role);
                $created[] = $case->value;
                continue;
            }

            $enumLabel = $case->getLabel();
            if (($role->getLabel() ?? '') !== $enumLabel) {
                $role->setLabel($enumLabel);
                $relabeled[] = $case->value;
            }
        }

        $this->entityManager->flush();

        $orphans = [];
        foreach ($this->roleRepository->findAll() as $role) {
            if (UserRole::tryFrom($role->getName()) === null) {
                $orphans[] = $role->getName();
            }
        }

        if ($created) {
            $io->success(sprintf('Создано ролей: %d (%s)', count($created), implode(', ', $created)));
        }

        if ($relabeled) {
            $io->writeln(sprintf('Обновлён label у %d ролей (по enum): %s', count($relabeled), implode(', ', $relabeled)));
        }

        if ($orphans) {
            $io->warning(sprintf(
                'В БД найдены %d ролей, которых нет в enum UserRole: %s. Проверьте, нужно ли их удалить вручную.',
                count($orphans),
                implode(', ', $orphans),
            ));
        }

        if (!$created && !$relabeled && !$orphans) {
            $io->success('Все роли из enum уже синхронизированы. Изменений не требуется.');
        }

        return Command::SUCCESS;
    }
}
