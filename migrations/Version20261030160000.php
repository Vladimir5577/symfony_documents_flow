<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261030160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'File group upload permission and file author';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE file_group_uploader (group_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (group_id, user_id))');
        $this->addSql('CREATE INDEX IDX_FILE_GROUP_UPLOADER_GROUP ON file_group_uploader (group_id)');
        $this->addSql('CREATE INDEX IDX_FILE_GROUP_UPLOADER_USER ON file_group_uploader (user_id)');
        $this->addSql('ALTER TABLE file_group_uploader ADD CONSTRAINT FK_FILE_GROUP_UPLOADER_GROUP FOREIGN KEY (group_id) REFERENCES file_group (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE file_group_uploader ADD CONSTRAINT FK_FILE_GROUP_UPLOADER_USER FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE file_group_file ADD uploaded_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE file_group_file ADD CONSTRAINT FK_FILE_GROUP_FILE_UPLOADED_BY FOREIGN KEY (uploaded_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX idx_file_group_file_uploaded_by ON file_group_file (uploaded_by_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE file_group_file DROP CONSTRAINT FK_FILE_GROUP_FILE_UPLOADED_BY');
        $this->addSql('DROP INDEX idx_file_group_file_uploaded_by');
        $this->addSql('ALTER TABLE file_group_file DROP uploaded_by_id');
        $this->addSql('ALTER TABLE file_group_uploader DROP CONSTRAINT FK_FILE_GROUP_UPLOADER_GROUP');
        $this->addSql('ALTER TABLE file_group_uploader DROP CONSTRAINT FK_FILE_GROUP_UPLOADER_USER');
        $this->addSql('DROP TABLE file_group_uploader');
    }
}
