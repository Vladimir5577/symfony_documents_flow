<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Съём метрик подписания из БД для внешнего агента мониторинга (T7.2).
 * Выводит JSON в stdout. Как подключить к Prometheus — см. dev_docks/Readme_monitoring.txt.
 *
 * Счётчики ошибок проверки (invalid_signature/hash_mismatch) в БД не хранятся —
 * они считаются из структурированных логов канала signature (var/log/signature.log).
 */
#[AsCommand(
    name: 'app:signature:metrics',
    description: 'Метрики электронной подписи (JSON): подписания по уровням, отказы, сертификаты.',
)]
final class SignatureMetricsCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $metrics = $this->collect();

        $output->writeln((string) json_encode($metrics, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $byLevel = $this->connection->fetchAllKeyValue(
            'SELECT level, COUNT(*) FROM document_signature GROUP BY level',
        );

        return [
            'generated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'signatures_total' => [
                'simple' => (int) ($byLevel['simple'] ?? 0),
                'enhanced' => (int) ($byLevel['enhanced'] ?? 0),
            ],
            'declines_total' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM document_history WHERE action LIKE 'signing_declined%'",
            ),
            'documents_on_signing' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM document WHERE status = 'ON_SIGNING'",
            ),
            'documents_signed' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM document WHERE status = 'SIGNED'",
            ),
            'certificates_active' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM user_certificate WHERE status = 'active' AND valid_to >= NOW()",
            ),
            'certificates_revoked' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM user_certificate WHERE status = 'revoked'",
            ),
            'certificates_expiring_30d' => (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM user_certificate
                 WHERE status = 'active' AND valid_to >= NOW() AND valid_to <= NOW() + INTERVAL '30 days'",
            ),
        ];
    }
}
