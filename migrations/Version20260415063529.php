<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260415063529 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_document_user');
        $this->addSql('ALTER TABLE document_user_recipient ADD role VARCHAR(32) DEFAULT \'executor\' NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_document_user_role ON document_user_recipient (document_id, user_id, role)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_document_user_role');
        $this->addSql('ALTER TABLE document_user_recipient DROP role');
        $this->addSql('CREATE UNIQUE INDEX uniq_document_user ON document_user_recipient (document_id, user_id)');
    }
}
