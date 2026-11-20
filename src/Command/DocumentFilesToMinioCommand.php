<?php

namespace App\Command;

use App\Repository\Document\DocumentCommentFileRepository;
use App\Repository\Document\FileRepository;
use App\Service\Document\DocumentFileStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:documents:files-to-minio',
    description: 'Копирует вложения документов и файлы комментариев с диска в MinIO. Уже лежащие в бакете объекты не трогает — повторный запуск безопасен.',
)]
class DocumentFilesToMinioCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FileRepository $fileRepository,
        private readonly DocumentCommentFileRepository $commentFileRepository,
        private readonly DocumentFileStorage $storage,
        #[Autowire('%private_upload_dir_documents_originals%')]
        private readonly string $originalsDir,
        #[Autowire('%private_upload_dir_documents_comments%')]
        private readonly string $commentsDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $copied = 0;
        $skipped = 0;
        $missing = [];

        // ponytail: все строки в памяти разом; на десятках тысяч файлов — toIterable() и clear() пачками.
        foreach ($this->fileRepository->findAll() as $file) {
            $path = DocumentFileStorage::attachmentPath($file);
            if ($path === null) {
                continue;
            }
            $local = $this->originalsDir . '/' . $path;
            if (!is_file($local)) {
                $missing[] = $local;
                continue;
            }
            // Размер и тип у старых строк не заполнены: раньше их брали с диска при каждом показе.
            $file->setContentType($file->getContentType() ?? (mime_content_type($local) ?: null));
            $file->setSizeBytes($file->getSizeBytes() ?? (filesize($local) ?: null));
            if ($this->storage->exists(DocumentFileStorage::ATTACHMENTS . $path)) {
                ++$skipped;
                continue;
            }
            $this->storage->putFile(DocumentFileStorage::ATTACHMENTS . $path, $local, $file->getContentType());
            ++$copied;
        }

        foreach ($this->commentFileRepository->findAll() as $file) {
            $path = DocumentFileStorage::commentFilePath($file);
            if ($path === null) {
                continue;
            }
            $local = $this->commentsDir . '/' . $path;
            if (!is_file($local)) {
                $missing[] = $local;
                continue;
            }
            if ($this->storage->exists(DocumentFileStorage::COMMENTS . $path)) {
                ++$skipped;
                continue;
            }
            $this->storage->putFile(DocumentFileStorage::COMMENTS . $path, $local, $file->getContentType());
            ++$copied;
        }

        $this->entityManager->flush();

        $io->success(sprintf('Скопировано: %d, уже были в бакете: %d.', $copied, $skipped));
        if ($missing !== []) {
            $io->warning(sprintf('Нет на диске (%d), в базе есть строки:', count($missing)));
            $io->listing($missing);

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
