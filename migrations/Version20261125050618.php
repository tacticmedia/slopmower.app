<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125050618 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The entry sequence of a project for its updates (D128)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue_entry ADD seq INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_issue_entry_seq ON issue_entry (seq)');
        $this->addSql('ALTER TABLE project ADD entry_seq INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_issue_entry_seq');
        $this->addSql('ALTER TABLE issue_entry DROP seq');
        $this->addSql('ALTER TABLE project DROP entry_seq');
    }
}
