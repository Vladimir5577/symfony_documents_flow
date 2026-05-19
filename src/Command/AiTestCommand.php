<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AI\AnthropicClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:test', description: 'Диагностический вызов Anthropic API через AnthropicClient.')]
final class AiTestCommand extends Command
{
    public function __construct(
        private readonly AnthropicClient $client,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('message', InputArgument::OPTIONAL, 'Текст сообщения', 'Ответь одним словом: работает?')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Путь к файлу (image/* или application/pdf) для прикрепления');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $message  = (string) $input->getArgument('message');
        $filePath = $input->getOption('file');

        $content = [['type' => 'text', 'text' => $message]];

        if ($filePath !== null) {
            if (!is_file($filePath) || !is_readable($filePath)) {
                $output->writeln('<error>Файл не найден или нечитаем: ' . $filePath . '</error>');
                return Command::FAILURE;
            }
            $mime = mime_content_type($filePath) ?: 'application/octet-stream';
            $data = (string) file_get_contents($filePath);

            if (str_starts_with($mime, 'image/')) {
                $content[] = [
                    'type'   => 'image',
                    'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($data)],
                ];
            } elseif ($mime === 'application/pdf') {
                $content[] = [
                    'type'   => 'document',
                    'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode($data)],
                ];
            } else {
                $output->writeln('<error>Неподдерживаемый mime: ' . $mime . '</error>');
                return Command::FAILURE;
            }
            $output->writeln('<info>📎</info> ' . $filePath . ' (' . $mime . ', ' . strlen($data) . ' байт)');
        }

        $output->writeln('<info>>>></info> ' . $message);

        $startedAt = microtime(true);
        try {
            $response = $this->client->sendMessage([
                ['role' => 'user', 'content' => $filePath === null ? $message : $content],
            ]);
        } catch (\Throwable $e) {
            $output->writeln('<error>FAIL: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $elapsed = round((microtime(true) - $startedAt) * 1000);

        $output->writeln('<comment><<<</comment> ' . $response->text);
        $output->writeln(sprintf(
            '<info>(ответ за %d мс, токены: in=%s out=%s, модель=%s)</info>',
            $elapsed,
            $response->tokensIn ?? '?',
            $response->tokensOut ?? '?',
            $response->model
        ));

        return Command::SUCCESS;
    }
}
