<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\FileGroup\FileGroupFile;
use App\Service\FileGroup\FileGroupStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:file-groups:migrate-to-minio',
    description: 'Копирует файлы групп с диска в MinIO (идемпотентно, ключ = stored_name).',
)]
final class MigrateFileGroupFilesToMinioCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FileGroupStorage $storage,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Только посчитать, ничего не писать')
            ->addOption('delete-disk', null, InputOption::VALUE_NONE, 'Удалить локальный файл после успешного putObject');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $deleteDisk = (bool) $input->getOption('delete-disk');

        /** @var list<FileGroupFile> $files */
        $files = $this->em->getRepository(FileGroupFile::class)->findAll();

        $ok = $skip = $missing = 0;

        foreach ($files as $file) {
            $id = (int) $file->getId();
            $key = $file->getStoredName();

            if ($dryRun) {
                if ($this->storage->hasObject($key)) {
                    ++$skip;
                    $io->writeln(sprintf('[%d] skip (уже в MinIO) %s', $id, $key));
                    continue;
                }
                if (is_file($this->storage->diskPath($file))) {
                    ++$ok;
                    $io->writeln(sprintf('[%d] would migrate %s', $id, $key));
                } else {
                    ++$missing;
                    $io->writeln(sprintf('[%d] missing %s', $id, $key));
                }
                continue;
            }

            $status = $this->storage->migrateFromDisk($file, $deleteDisk);
            if ($status === 'ok') {
                ++$ok;
                $io->writeln(sprintf('[%d] ok %s', $id, $key));
            } elseif ($status === 'skip') {
                ++$skip;
                $io->writeln(sprintf('[%d] skip %s', $id, $key));
            } else {
                ++$missing;
                $io->writeln(sprintf('[%d] missing %s', $id, $key));
            }
        }

        $io->success(sprintf(
            '%sok=%d skip=%d missing=%d total=%d',
            $dryRun ? 'dry-run ' : '',
            $ok,
            $skip,
            $missing,
            count($files),
        ));

        return $missing > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
