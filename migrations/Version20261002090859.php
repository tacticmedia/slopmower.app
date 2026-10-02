<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002090859 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repository.fetchRequested';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        // The default fills the existing rows. The mapping has none.
        $this->addSql('ALTER TABLE repository ADD fetch_requested BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE repository ALTER fetch_requested DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE repository DROP fetch_requested');
    }
}
