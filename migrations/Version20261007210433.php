<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007210433 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The language of the symbol rows of an indexed blob (D56)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE indexed_blob ADD language VARCHAR(32) DEFAULT NULL');
        // Every blob indexed so far was parsed as PHP.
        $this->addSql("UPDATE indexed_blob SET language = 'php'");
        $this->addSql('ALTER TABLE indexed_blob ALTER language SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE indexed_blob DROP language');
    }
}
