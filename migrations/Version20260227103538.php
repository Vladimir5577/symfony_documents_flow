<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260227103538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE kanban_attachment (id UUID NOT NULL, filename VARCHAR(255) NOT NULL, storage_key VARCHAR(500) NOT NULL, content_type VARCHAR(100) NOT NULL, size_bytes INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_39C29C4F4ACC9A20 ON kanban_attachment (card_id)');
        $this->addSql('CREATE TABLE kanban_board (id UUID NOT NULL, title VARCHAR(200) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_by_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AA902B95B03A8386 ON kanban_board (created_by_id)');
        $this->addSql('CREATE TABLE kanban_board_member (id UUID NOT NULL, role VARCHAR(20) NOT NULL, board_id UUID NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_AA9C6FE4E7EC5785 ON kanban_board_member (board_id)');
        $this->addSql('CREATE INDEX IDX_AA9C6FE4A76ED395 ON kanban_board_member (user_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_board_member ON kanban_board_member (board_id, user_id)');
        $this->addSql('CREATE TABLE kanban_card (id UUID NOT NULL, title VARCHAR(500) NOT NULL, description TEXT DEFAULT NULL, position DOUBLE PRECISION NOT NULL, due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, priority SMALLINT DEFAULT NULL, is_archived BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, column_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B2140480BE8E8ED5 ON kanban_card (column_id)');
        $this->addSql('CREATE TABLE kanban_card_label (kanban_card_id UUID NOT NULL, kanban_label_id UUID NOT NULL, PRIMARY KEY (kanban_card_id, kanban_label_id))');
        $this->addSql('CREATE INDEX IDX_760EE4DAF9C2CF7B ON kanban_card_label (kanban_card_id)');
        $this->addSql('CREATE INDEX IDX_760EE4DACFB3A910 ON kanban_card_label (kanban_label_id)');
        $this->addSql('CREATE TABLE kanban_card_comment (id UUID NOT NULL, body TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, card_id UUID NOT NULL, author_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_B50809214ACC9A20 ON kanban_card_comment (card_id)');
        $this->addSql('CREATE INDEX IDX_B5080921F675F31B ON kanban_card_comment (author_id)');
        $this->addSql('CREATE TABLE kanban_checklist_item (id UUID NOT NULL, title VARCHAR(500) NOT NULL, is_completed BOOLEAN DEFAULT false NOT NULL, position DOUBLE PRECISION NOT NULL, card_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_548D5BE54ACC9A20 ON kanban_checklist_item (card_id)');
        $this->addSql('CREATE TABLE kanban_column (id UUID NOT NULL, title VARCHAR(200) NOT NULL, header_color VARCHAR(30) DEFAULT \'bg-primary\' NOT NULL, position DOUBLE PRECISION NOT NULL, board_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_157CF286E7EC5785 ON kanban_column (board_id)');
        $this->addSql('CREATE TABLE kanban_label (id UUID NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(30) NOT NULL, board_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_FC61503AE7EC5785 ON kanban_label (board_id)');
        $this->addSql('ALTER TABLE kanban_attachment ADD CONSTRAINT FK_39C29C4F4ACC9A20 FOREIGN KEY (card_id) REFERENCES kanban_card (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE kanban_board ADD CONSTRAINT FK_AA902B95B03A8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE kanban_board_member ADD CONSTRAINT FK_AA9C6FE4E7EC5785 FOREIGN KEY (board_id) REFERENCES kanban_board (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE kanban_board_member ADD CONSTRAINT FK_AA9C6FE4A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE kanban_card ADD CONSTRAINT FK_B2140480BE8E8ED5 FOREIGN KEY (column_id) REFERENCES kanban_column (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE kanban_card_label ADD CONSTRAINT FK_760EE4DAF9C2CF7B FOREIGN KEY (kanban_card_id) REFERENCES kanban_card (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kanban_card_label ADD CONSTRAINT FK_760EE4DACFB3A910 FOREIGN KEY (kanban_label_id) REFERENCES kanban_label (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kanban_card_comment ADD CONSTRAINT FK_B50809214ACC9A20 FOREIGN KEY (card_id) REFERENCES kanban_card (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE kanban_card_comment ADD CONSTRAINT FK_B5080921F675F31B FOREIGN KEY (author_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE');
        $this->addSql('ALTER TABLE kanban_checklist_item ADD CONSTRAINT FK_548D5BE54ACC9A20 FOREIGN KEY (card_id) REFERENCES kanban_card (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE kanban_column ADD CONSTRAINT FK_157CF286E7EC5785 FOREIGN KEY (board_id) REFERENCES kanban_board (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE kanban_label ADD CONSTRAINT FK_FC61503AE7EC5785 FOREIGN KEY (board_id) REFERENCES kanban_board (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE kanban_attachment DROP CONSTRAINT FK_39C29C4F4ACC9A20');
        $this->addSql('ALTER TABLE kanban_board DROP CONSTRAINT FK_AA902B95B03A8386');
        $this->addSql('ALTER TABLE kanban_board_member DROP CONSTRAINT FK_AA9C6FE4E7EC5785');
        $this->addSql('ALTER TABLE kanban_board_member DROP CONSTRAINT FK_AA9C6FE4A76ED395');
        $this->addSql('ALTER TABLE kanban_card DROP CONSTRAINT FK_B2140480BE8E8ED5');
        $this->addSql('ALTER TABLE kanban_card_label DROP CONSTRAINT FK_760EE4DAF9C2CF7B');
        $this->addSql('ALTER TABLE kanban_card_label DROP CONSTRAINT FK_760EE4DACFB3A910');
        $this->addSql('ALTER TABLE kanban_card_comment DROP CONSTRAINT FK_B50809214ACC9A20');
        $this->addSql('ALTER TABLE kanban_card_comment DROP CONSTRAINT FK_B5080921F675F31B');
        $this->addSql('ALTER TABLE kanban_checklist_item DROP CONSTRAINT FK_548D5BE54ACC9A20');
        $this->addSql('ALTER TABLE kanban_column DROP CONSTRAINT FK_157CF286E7EC5785');
        $this->addSql('ALTER TABLE kanban_label DROP CONSTRAINT FK_FC61503AE7EC5785');
        $this->addSql('DROP TABLE kanban_attachment');
        $this->addSql('DROP TABLE kanban_board');
        $this->addSql('DROP TABLE kanban_board_member');
        $this->addSql('DROP TABLE kanban_card');
        $this->addSql('DROP TABLE kanban_card_label');
        $this->addSql('DROP TABLE kanban_card_comment');
        $this->addSql('DROP TABLE kanban_checklist_item');
        $this->addSql('DROP TABLE kanban_column');
        $this->addSql('DROP TABLE kanban_label');
    }
}
