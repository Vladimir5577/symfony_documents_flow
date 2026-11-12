<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112060710 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE purchase_approval_stage DROP CONSTRAINT fk_7fc3a3d64e4def6f');
        $this->addSql('ALTER TABLE purchase_approval_task DROP CONSTRAINT fk_50bc78d52298d193');
        $this->addSql('ALTER TABLE purchase_approval_task DROP CONSTRAINT fk_50bc78d5ba8d7f59');
        $this->addSql('ALTER TABLE purchase_approval_task DROP CONSTRAINT fk_50bc78d5e26b496b');
        $this->addSql('ALTER TABLE purchase_approval_task DROP CONSTRAINT fk_50bc78d5b03a8386');
        $this->addSql('ALTER TABLE purchase_approver DROP CONSTRAINT fk_a1417e1ea76ed395');
        $this->addSql('ALTER TABLE purchase_approver_role DROP CONSTRAINT fk_98386e62bb23766c');
        $this->addSql('ALTER TABLE purchase_category_item DROP CONSTRAINT fk_4292e64612469de2');
        $this->addSql('ALTER TABLE purchase_request DROP CONSTRAINT fk_204d45e6b03a8386');
        $this->addSql('ALTER TABLE purchase_request DROP CONSTRAINT fk_204d45e632c8a3de');
        $this->addSql('ALTER TABLE purchase_request DROP CONSTRAINT fk_204d45e612469de2');
        $this->addSql('ALTER TABLE purchase_request DROP CONSTRAINT fk_204d45e68e2784af');
        $this->addSql('ALTER TABLE purchase_request DROP CONSTRAINT fk_204d45e68abd09bb');
        $this->addSql('ALTER TABLE purchase_request DROP CONSTRAINT fk_204d45e63bba0fa9');
        $this->addSql('ALTER TABLE purchase_request_comment DROP CONSTRAINT fk_c1b7f39f4e4def6f');
        $this->addSql('ALTER TABLE purchase_request_comment DROP CONSTRAINT fk_c1b7f39ff675f31b');
        $this->addSql('ALTER TABLE purchase_request_contract DROP CONSTRAINT fk_purchase_request_contract_request');
        $this->addSql('ALTER TABLE purchase_request_contract DROP CONSTRAINT fk_purchase_request_contract_contract');
        $this->addSql('ALTER TABLE purchase_request_file DROP CONSTRAINT fk_purchase_file_source');
        $this->addSql('ALTER TABLE purchase_request_file DROP CONSTRAINT fk_3722e64aa2b28fe8');
        $this->addSql('ALTER TABLE purchase_request_file DROP CONSTRAINT fk_3722e64a4e4def6f');
        $this->addSql('ALTER TABLE purchase_request_file DROP CONSTRAINT fk_purchase_file_catalog');
        $this->addSql('ALTER TABLE purchase_request_file DROP CONSTRAINT fk_purchase_file_contract');
        $this->addSql('ALTER TABLE purchase_request_history DROP CONSTRAINT fk_7279d1b84e4def6f');
        $this->addSql('ALTER TABLE purchase_request_history DROP CONSTRAINT fk_7279d1b8a76ed395');
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT fk_a4a6f54461124fbf');
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT fk_a4a6f5444e4def6f');
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT fk_a4a6f544d5b71220');
        $this->addSql('ALTER TABLE purchase_request_item DROP CONSTRAINT fk_purchase_item_contract');
        $this->addSql('ALTER TABLE purchase_route_default DROP CONSTRAINT fk_cd1fe4585da0fb8');
        $this->addSql('ALTER TABLE purchase_route_default DROP CONSTRAINT fk_cd1fe458896dbbde');
        $this->addSql('ALTER TABLE purchase_route_template DROP CONSTRAINT fk_e49248e4896dbbde');
        $this->addSql('ALTER TABLE purchase_route_template_stage DROP CONSTRAINT fk_14b064ba5da0fb8');
        $this->addSql('ALTER TABLE purchase_route_template_task DROP CONSTRAINT fk_482ae0852298d193');
        $this->addSql('DROP TABLE purchase_approval_stage');
        $this->addSql('DROP TABLE purchase_approval_task');
        $this->addSql('DROP TABLE purchase_approver');
        $this->addSql('DROP TABLE purchase_approver_role');
        $this->addSql('DROP TABLE purchase_category');
        $this->addSql('DROP TABLE purchase_category_item');
        $this->addSql('DROP TABLE purchase_contract');
        $this->addSql('DROP TABLE purchase_request');
        $this->addSql('DROP TABLE purchase_request_comment');
        $this->addSql('DROP TABLE purchase_request_contract');
        $this->addSql('DROP TABLE purchase_request_file');
        $this->addSql('DROP TABLE purchase_request_history');
        $this->addSql('DROP TABLE purchase_request_item');
        $this->addSql('DROP TABLE purchase_route_default');
        $this->addSql('DROP TABLE purchase_route_template');
        $this->addSql('DROP TABLE purchase_route_template_stage');
        $this->addSql('DROP TABLE purchase_route_template_task');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE purchase_approval_stage (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, "position" SMALLINT NOT NULL, title VARCHAR(255) DEFAULT NULL, purpose VARCHAR(30) NOT NULL, allows_reject BOOLEAN DEFAULT true NOT NULL, status VARCHAR(30) DEFAULT \'PENDING\' NOT NULL, candidate_role_code VARCHAR(50) DEFAULT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, completed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, purchase_request_id INT NOT NULL, review_minutes INT DEFAULT NULL, due_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, allows_in_stock BOOLEAN DEFAULT false NOT NULL, allows_exclude BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_7fc3a3d64e4def6f ON purchase_approval_stage (purchase_request_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_purchase_approval_stage_position ON purchase_approval_stage (purchase_request_id, "position")');
        $this->addSql('CREATE INDEX idx_7fc3a3d64e4def6f7b00651c ON purchase_approval_stage (purchase_request_id, status)');
        $this->addSql('CREATE TABLE purchase_approval_task (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, "position" SMALLINT NOT NULL, assignment_type VARCHAR(30) NOT NULL, role_code VARCHAR(50) DEFAULT NULL, role_name VARCHAR(100) DEFAULT NULL, title VARCHAR(255) DEFAULT NULL, requires_file_type VARCHAR(30) DEFAULT NULL, decision VARCHAR(20) DEFAULT \'PENDING\' NOT NULL, decided_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, comment TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, stage_id INT NOT NULL, assignee_user_id INT DEFAULT NULL, decided_by_id INT DEFAULT NULL, created_by_id INT DEFAULT NULL, contract_review VARCHAR(20) DEFAULT NULL, contract_reviewed_file_ids JSON DEFAULT \'[]\' NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_50bc78d52298d193 ON purchase_approval_task (stage_id)');
        $this->addSql('CREATE INDEX idx_50bc78d52298d193462ce4f5 ON purchase_approval_task (stage_id, "position")');
        $this->addSql('CREATE INDEX idx_50bc78d5e26b496b ON purchase_approval_task (decided_by_id)');
        $this->addSql('CREATE INDEX idx_50bc78d5c9aa420c84acbe48 ON purchase_approval_task (role_code, decision)');
        $this->addSql('CREATE INDEX idx_50bc78d5ba8d7f5984acbe48 ON purchase_approval_task (assignee_user_id, decision)');
        $this->addSql('CREATE INDEX idx_50bc78d5ba8d7f59 ON purchase_approval_task (assignee_user_id)');
        $this->addSql('CREATE INDEX idx_50bc78d5b03a8386 ON purchase_approval_task (created_by_id)');
        $this->addSql('CREATE TABLE purchase_approver (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, "position" INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_purchase_approver_user ON purchase_approver (user_id)');
        $this->addSql('CREATE TABLE purchase_approver_role (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, role_code VARCHAR(50) NOT NULL, approver_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_purchase_approver_role ON purchase_approver_role (approver_id, role_code)');
        $this->addSql('CREATE INDEX idx_98386e62bb23766c ON purchase_approver_role (approver_id)');
        $this->addSql('CREATE TABLE purchase_category (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, name VARCHAR(255) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, image_key VARCHAR(255) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_purchase_category_name ON purchase_category (name)');
        $this->addSql('CREATE TABLE purchase_category_item (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, name VARCHAR(255) NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, image_key VARCHAR(255) DEFAULT NULL, category_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_4292e64612469de2 ON purchase_category_item (category_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_purchase_category_item ON purchase_category_item (category_id, name)');
        $this->addSql('CREATE TABLE purchase_contract (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, status VARCHAR(20) DEFAULT \'ACTIVE\' NOT NULL, name VARCHAR(255) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE TABLE purchase_request (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, title VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, law VARCHAR(20) DEFAULT NULL, method VARCHAR(30) DEFAULT NULL, technical_spec TEXT DEFAULT NULL, supplier VARCHAR(255) DEFAULT NULL, status VARCHAR(50) NOT NULL, created_as VARCHAR(20) DEFAULT \'STANDARD\' NOT NULL, priority VARCHAR(20) DEFAULT \'NORMAL\' NOT NULL, applied_route_template_name VARCHAR(255) DEFAULT NULL, due_date DATE DEFAULT NULL, version INT DEFAULT 1 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, organization_id INT NOT NULL, created_by_id INT DEFAULT NULL, executor_id INT DEFAULT NULL, category_id INT DEFAULT NULL, route_template_id INT DEFAULT NULL, applied_route_template_id INT DEFAULT NULL, progress_status VARCHAR(20) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_204d45e6b03a8386 ON purchase_request (created_by_id)');
        $this->addSql('CREATE INDEX idx_204d45e68e2784af ON purchase_request (applied_route_template_id)');
        $this->addSql('CREATE INDEX idx_204d45e68b8e8428 ON purchase_request (created_at)');
        $this->addSql('CREATE INDEX idx_204d45e68abd09bb ON purchase_request (executor_id)');
        $this->addSql('CREATE INDEX idx_204d45e67b00651c ON purchase_request (status)');
        $this->addSql('CREATE INDEX idx_204d45e63bba0fa9 ON purchase_request (route_template_id)');
        $this->addSql('CREATE INDEX idx_204d45e632c8a3de7b00651c ON purchase_request (organization_id, status)');
        $this->addSql('CREATE INDEX idx_204d45e632c8a3de ON purchase_request (organization_id)');
        $this->addSql('CREATE INDEX idx_204d45e612469de2 ON purchase_request (category_id)');
        $this->addSql('CREATE INDEX idx_204d45e6cae934cb ON purchase_request (progress_status)');
        $this->addSql('CREATE TABLE purchase_request_comment (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, text TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, purchase_request_id INT NOT NULL, author_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_c1b7f39f4e4def6f ON purchase_request_comment (purchase_request_id)');
        $this->addSql('CREATE INDEX idx_c1b7f39ff675f31b ON purchase_request_comment (author_id)');
        $this->addSql('CREATE TABLE purchase_request_contract (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, purchase_request_id INT NOT NULL, purchase_contract_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_cd6e485e5ecbf804 ON purchase_request_contract (purchase_contract_id)');
        $this->addSql('CREATE INDEX idx_cd6e485e4e4def6f ON purchase_request_contract (purchase_request_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_purchase_request_contract ON purchase_request_contract (purchase_request_id, purchase_contract_id)');
        $this->addSql('CREATE TABLE purchase_request_file (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, storage_key VARCHAR(255) NOT NULL, original_name VARCHAR(255) NOT NULL, type VARCHAR(30) DEFAULT \'OTHER\' NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, purchase_request_id INT DEFAULT NULL, uploaded_by_id INT DEFAULT NULL, editor_revision INT DEFAULT 1 NOT NULL, color VARCHAR(7) DEFAULT NULL, contract_file_id INT DEFAULT NULL, source_file_id INT DEFAULT NULL, purchase_contract_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_3722e64a5ecbf804 ON purchase_request_file (purchase_contract_id)');
        $this->addSql('CREATE INDEX idx_3722e64aa2b28fe8 ON purchase_request_file (uploaded_by_id)');
        $this->addSql('CREATE INDEX idx_3722e64ada14c104 ON purchase_request_file (source_file_id)');
        $this->addSql('CREATE INDEX idx_3722e64a9b9a04a4 ON purchase_request_file (contract_file_id)');
        $this->addSql('CREATE INDEX idx_3722e64a4e4def6f ON purchase_request_file (purchase_request_id)');
        $this->addSql('CREATE TABLE purchase_request_history (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, action VARCHAR(30) DEFAULT NULL, from_status VARCHAR(50) DEFAULT NULL, to_status VARCHAR(50) NOT NULL, comment TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, purchase_request_id INT NOT NULL, user_id INT NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_7279d1b8a76ed395 ON purchase_request_history (user_id)');
        $this->addSql('CREATE INDEX idx_7279d1b84e4def6f ON purchase_request_history (purchase_request_id)');
        $this->addSql('CREATE TABLE purchase_request_item (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, quantity NUMERIC(12, 3) NOT NULL, unit VARCHAR(20) NOT NULL, estimated_price NUMERIC(12, 2) NOT NULL, "position" INT NOT NULL, excluded BOOLEAN DEFAULT false NOT NULL, approved_quantity NUMERIC(12, 3) DEFAULT NULL, purchase_request_id INT NOT NULL, category_item_id INT DEFAULT NULL, in_stock BOOLEAN DEFAULT false NOT NULL, invoice_file_id INT DEFAULT NULL, contract_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_a4a6f544d5b71220 ON purchase_request_item (category_item_id)');
        $this->addSql('CREATE INDEX idx_a4a6f5444e4def6f ON purchase_request_item (purchase_request_id)');
        $this->addSql('CREATE INDEX idx_a4a6f5442576e0fd ON purchase_request_item (contract_id)');
        $this->addSql('CREATE INDEX idx_a4a6f54461124fbf ON purchase_request_item (invoice_file_id)');
        $this->addSql('CREATE TABLE purchase_route_default (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, kind VARCHAR(20) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, template_id INT NOT NULL, updated_by_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_cd1fe458896dbbde ON purchase_route_default (updated_by_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_purchase_route_default_kind ON purchase_route_default (kind)');
        $this->addSql('CREATE INDEX idx_cd1fe4585da0fb8 ON purchase_route_default (template_id)');
        $this->addSql('CREATE TABLE purchase_route_template (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, code VARCHAR(50) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, allowed_kinds JSON NOT NULL, is_active BOOLEAN DEFAULT true NOT NULL, sort_order SMALLINT DEFAULT 0 NOT NULL, version INT DEFAULT 1 NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_by_id INT DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_purchase_route_template_code ON purchase_route_template (code)');
        $this->addSql('CREATE INDEX idx_e49248e4896dbbde ON purchase_route_template (updated_by_id)');
        $this->addSql('CREATE INDEX idx_e49248e41b5771dd45afa4ea ON purchase_route_template (is_active, sort_order)');
        $this->addSql('CREATE TABLE purchase_route_template_stage (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, "position" SMALLINT NOT NULL, title VARCHAR(255) DEFAULT NULL, purpose VARCHAR(30) NOT NULL, allows_reject BOOLEAN DEFAULT true NOT NULL, template_id INT NOT NULL, review_minutes INT DEFAULT NULL, allows_in_stock BOOLEAN DEFAULT false NOT NULL, allows_exclude BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_14b064ba5da0fb8 ON purchase_route_template_stage (template_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_purchase_route_template_stage_position ON purchase_route_template_stage (template_id, "position")');
        $this->addSql('CREATE TABLE purchase_route_template_task (id INT GENERATED BY DEFAULT AS IDENTITY NOT NULL, "position" SMALLINT NOT NULL, assignment_type VARCHAR(20) NOT NULL, role_code VARCHAR(50) DEFAULT NULL, candidate_role_code VARCHAR(50) DEFAULT NULL, title VARCHAR(255) DEFAULT NULL, requires_file_type VARCHAR(30) DEFAULT NULL, stage_id INT NOT NULL, contract_review VARCHAR(20) DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_482ae0852298d193 ON purchase_route_template_task (stage_id)');
        $this->addSql('CREATE INDEX idx_482ae0852298d193462ce4f5 ON purchase_route_template_task (stage_id, "position")');
        $this->addSql('ALTER TABLE purchase_approval_stage ADD CONSTRAINT fk_7fc3a3d64e4def6f FOREIGN KEY (purchase_request_id) REFERENCES purchase_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_approval_task ADD CONSTRAINT fk_50bc78d52298d193 FOREIGN KEY (stage_id) REFERENCES purchase_approval_stage (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_approval_task ADD CONSTRAINT fk_50bc78d5ba8d7f59 FOREIGN KEY (assignee_user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_approval_task ADD CONSTRAINT fk_50bc78d5e26b496b FOREIGN KEY (decided_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_approval_task ADD CONSTRAINT fk_50bc78d5b03a8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_approver ADD CONSTRAINT fk_a1417e1ea76ed395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_approver_role ADD CONSTRAINT fk_98386e62bb23766c FOREIGN KEY (approver_id) REFERENCES purchase_approver (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_category_item ADD CONSTRAINT fk_4292e64612469de2 FOREIGN KEY (category_id) REFERENCES purchase_category (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request ADD CONSTRAINT fk_204d45e6b03a8386 FOREIGN KEY (created_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request ADD CONSTRAINT fk_204d45e632c8a3de FOREIGN KEY (organization_id) REFERENCES organization (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request ADD CONSTRAINT fk_204d45e612469de2 FOREIGN KEY (category_id) REFERENCES purchase_category (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request ADD CONSTRAINT fk_204d45e68e2784af FOREIGN KEY (applied_route_template_id) REFERENCES purchase_route_template (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request ADD CONSTRAINT fk_204d45e68abd09bb FOREIGN KEY (executor_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request ADD CONSTRAINT fk_204d45e63bba0fa9 FOREIGN KEY (route_template_id) REFERENCES purchase_route_template (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_comment ADD CONSTRAINT fk_c1b7f39f4e4def6f FOREIGN KEY (purchase_request_id) REFERENCES purchase_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_comment ADD CONSTRAINT fk_c1b7f39ff675f31b FOREIGN KEY (author_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_contract ADD CONSTRAINT fk_purchase_request_contract_request FOREIGN KEY (purchase_request_id) REFERENCES purchase_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_contract ADD CONSTRAINT fk_purchase_request_contract_contract FOREIGN KEY (purchase_contract_id) REFERENCES purchase_contract (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_file ADD CONSTRAINT fk_purchase_file_source FOREIGN KEY (source_file_id) REFERENCES purchase_request_file (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_file ADD CONSTRAINT fk_3722e64aa2b28fe8 FOREIGN KEY (uploaded_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_file ADD CONSTRAINT fk_3722e64a4e4def6f FOREIGN KEY (purchase_request_id) REFERENCES purchase_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_file ADD CONSTRAINT fk_purchase_file_catalog FOREIGN KEY (purchase_contract_id) REFERENCES purchase_contract (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_file ADD CONSTRAINT fk_purchase_file_contract FOREIGN KEY (contract_file_id) REFERENCES purchase_request_file (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_history ADD CONSTRAINT fk_7279d1b84e4def6f FOREIGN KEY (purchase_request_id) REFERENCES purchase_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_history ADD CONSTRAINT fk_7279d1b8a76ed395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT fk_a4a6f54461124fbf FOREIGN KEY (invoice_file_id) REFERENCES purchase_request_file (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT fk_a4a6f5444e4def6f FOREIGN KEY (purchase_request_id) REFERENCES purchase_request (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT fk_a4a6f544d5b71220 FOREIGN KEY (category_item_id) REFERENCES purchase_category_item (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_request_item ADD CONSTRAINT fk_purchase_item_contract FOREIGN KEY (contract_id) REFERENCES purchase_contract (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_route_default ADD CONSTRAINT fk_cd1fe4585da0fb8 FOREIGN KEY (template_id) REFERENCES purchase_route_template (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_route_default ADD CONSTRAINT fk_cd1fe458896dbbde FOREIGN KEY (updated_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_route_template ADD CONSTRAINT fk_e49248e4896dbbde FOREIGN KEY (updated_by_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_route_template_stage ADD CONSTRAINT fk_14b064ba5da0fb8 FOREIGN KEY (template_id) REFERENCES purchase_route_template (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE purchase_route_template_task ADD CONSTRAINT fk_482ae0852298d193 FOREIGN KEY (stage_id) REFERENCES purchase_route_template_stage (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
