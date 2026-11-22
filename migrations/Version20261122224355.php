<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261122224355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The comment that a comment of an issue replaces (D123)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue_entry ADD replaces_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE issue_entry ADD CONSTRAINT FK_B9ABF4239F193203 FOREIGN KEY (replaces_id) REFERENCES issue_entry (id)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B9ABF4239F193203 ON issue_entry (replaces_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE issue_entry DROP CONSTRAINT FK_B9ABF4239F193203');
        $this->addSql('DROP INDEX UNIQ_B9ABF4239F193203');
        $this->addSql('ALTER TABLE issue_entry DROP replaces_id');
    }
}
