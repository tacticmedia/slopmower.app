<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261013191621 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The suggestion state of a request (F48)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE change_request ADD suggestion_state VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE change_request DROP suggestion_state');
    }
}
