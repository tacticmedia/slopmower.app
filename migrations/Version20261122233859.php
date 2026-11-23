<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261122233859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The questions of a comment of an issue (D124)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue_entry ADD questions JSONB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE issue_entry DROP questions');
    }
}
