<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260227111501 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE kanban_card_assignee (card_id UUID NOT NULL, user_id INT NOT NULL, PRIMARY KEY (card_id, user_id))');
        $this->addSql('CREATE INDEX IDX_74D1BD7A4ACC9A20 ON kanban_card_assignee (card_id)');
        $this->addSql('CREATE INDEX IDX_74D1BD7AA76ED395 ON kanban_card_assignee (user_id)');
        $this->addSql('ALTER TABLE kanban_card_assignee ADD CONSTRAINT FK_74D1BD7A4ACC9A20 FOREIGN KEY (card_id) REFERENCES kanban_card (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE kanban_card_assignee ADD CONSTRAINT FK_74D1BD7AA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE kanban_card_assignee DROP CONSTRAINT FK_74D1BD7A4ACC9A20');
        $this->addSql('ALTER TABLE kanban_card_assignee DROP CONSTRAINT FK_74D1BD7AA76ED395');
        $this->addSql('DROP TABLE kanban_card_assignee');
    }
}
