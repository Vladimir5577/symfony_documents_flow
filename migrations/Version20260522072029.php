<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260522072029 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_analytics_agg_effective_at');
        $this->addSql('ALTER TABLE analytics_aggregated_data DROP metric_name_snapshot');
        $this->addSql('ALTER TABLE analytics_aggregated_data DROP metric_unit_snapshot');
        $this->addSql('ALTER TABLE analytics_aggregated_data DROP metric_type_snapshot');
        $this->addSql('ALTER TABLE analytics_aggregated_data DROP effective_at');
        $this->addSql('ALTER TABLE analytics_board_version_metrics ADD parent_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_board_version_metrics ADD CONSTRAINT FK_B5260707727ACA70 FOREIGN KEY (parent_id) REFERENCES analytics_board_version_metrics (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_analytics_board_version_metrics_parent ON analytics_board_version_metrics (parent_id)');
        $this->addSql('ALTER TABLE analytics_board_versions DROP status');
        $this->addSql('ALTER TABLE analytics_boards ADD active_version_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_boards ADD CONSTRAINT FK_5B2EEC7D6A1E45F3 FOREIGN KEY (active_version_id) REFERENCES analytics_board_versions (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_5B2EEC7D6A1E45F3 ON analytics_boards (active_version_id)');
        $this->addSql('ALTER TABLE analytics_metrics ADD category VARCHAR(32) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_analytics_metrics_category ON analytics_metrics (category)');
        $this->addSql('DROP INDEX uniq_analytics_periods_monthly');
        $this->addSql('DROP INDEX uniq_analytics_periods_daily');
        $this->addSql('DROP INDEX idx_analytics_periods_type');
        $this->addSql('DROP INDEX uniq_analytics_periods_weekly');
        $this->addSql('ALTER TABLE analytics_periods DROP iso_year');
        $this->addSql('ALTER TABLE analytics_periods DROP iso_week');
        $this->addSql('ALTER TABLE analytics_periods DROP period_date');
        $this->addSql('ALTER TABLE analytics_periods DROP year');
        $this->addSql('ALTER TABLE analytics_periods DROP month');
        $this->addSql('ALTER TABLE analytics_periods DROP is_closed');
        $this->addSql('ALTER TABLE analytics_periods DROP description');
        $this->addSql('ALTER TABLE analytics_periods DROP created_at');
        $this->addSql('ALTER TABLE analytics_periods DROP updated_at');
        $this->addSql('CREATE UNIQUE INDEX uniq_analytics_periods_type_start_date ON analytics_periods (type, start_date)');
        $this->addSql('DROP INDEX idx_analytics_report_values_effective_at');
        $this->addSql('DROP INDEX idx_analytics_report_values_bvm_effective_at');
        $this->addSql('ALTER TABLE analytics_report_values DROP metric_name_snapshot');
        $this->addSql('ALTER TABLE analytics_report_values DROP metric_unit_snapshot');
        $this->addSql('ALTER TABLE analytics_report_values DROP metric_type_snapshot');
        $this->addSql('ALTER TABLE analytics_report_values DROP effective_at');
        $this->addSql('ALTER TABLE analytics_reports DROP CONSTRAINT fk_5bbd2bbb4ea3cb3d');
        $this->addSql('DROP INDEX idx_5bbd2bbb4ea3cb3d');
        $this->addSql('DROP INDEX idx_analytics_reports_status_period');
        $this->addSql('ALTER TABLE analytics_reports DROP is_complete');
        $this->addSql('ALTER TABLE analytics_reports DROP submitted_at');
        $this->addSql('ALTER TABLE analytics_reports DROP approved_at');
        $this->addSql('ALTER TABLE analytics_reports DROP approved_by');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE analytics_aggregated_data ADD metric_name_snapshot VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_aggregated_data ADD metric_unit_snapshot VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_aggregated_data ADD metric_type_snapshot VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_aggregated_data ADD effective_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_analytics_agg_effective_at ON analytics_aggregated_data (effective_at)');
        $this->addSql('ALTER TABLE analytics_board_version_metrics DROP CONSTRAINT FK_B5260707727ACA70');
        $this->addSql('DROP INDEX idx_analytics_board_version_metrics_parent');
        $this->addSql('ALTER TABLE analytics_board_version_metrics DROP parent_id');
        $this->addSql('ALTER TABLE analytics_board_versions ADD status VARCHAR(16) NOT NULL');
        $this->addSql('ALTER TABLE analytics_boards DROP CONSTRAINT FK_5B2EEC7D6A1E45F3');
        $this->addSql('DROP INDEX IDX_5B2EEC7D6A1E45F3');
        $this->addSql('ALTER TABLE analytics_boards DROP active_version_id');
        $this->addSql('DROP INDEX idx_analytics_metrics_category');
        $this->addSql('ALTER TABLE analytics_metrics DROP category');
        $this->addSql('DROP INDEX uniq_analytics_periods_type_start_date');
        $this->addSql('ALTER TABLE analytics_periods ADD iso_year INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_periods ADD iso_week INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_periods ADD period_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_periods ADD year INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_periods ADD month INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_periods ADD is_closed BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE analytics_periods ADD description VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_periods ADD created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('ALTER TABLE analytics_periods ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_analytics_periods_monthly ON analytics_periods (year, month)');
        $this->addSql('CREATE UNIQUE INDEX uniq_analytics_periods_daily ON analytics_periods (period_date)');
        $this->addSql('CREATE INDEX idx_analytics_periods_type ON analytics_periods (type)');
        $this->addSql('CREATE UNIQUE INDEX uniq_analytics_periods_weekly ON analytics_periods (iso_year, iso_week)');
        $this->addSql('ALTER TABLE analytics_report_values ADD metric_name_snapshot VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_report_values ADD metric_unit_snapshot VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_report_values ADD metric_type_snapshot VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_report_values ADD effective_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL');
        $this->addSql('CREATE INDEX idx_analytics_report_values_effective_at ON analytics_report_values (effective_at)');
        $this->addSql('CREATE INDEX idx_analytics_report_values_bvm_effective_at ON analytics_report_values (board_version_metric_id, effective_at)');
        $this->addSql('ALTER TABLE analytics_reports ADD is_complete BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE analytics_reports ADD submitted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_reports ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_reports ADD approved_by INT DEFAULT NULL');
        $this->addSql('ALTER TABLE analytics_reports ADD CONSTRAINT fk_5bbd2bbb4ea3cb3d FOREIGN KEY (approved_by) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_5bbd2bbb4ea3cb3d ON analytics_reports (approved_by)');
        $this->addSql('CREATE INDEX idx_analytics_reports_status_period ON analytics_reports (status, period_id)');
    }
}
