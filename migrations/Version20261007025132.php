<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007025132 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The linked pull request URLs on change_request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE change_request ADD pull_request_urls JSONB DEFAULT \'[]\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE change_request DROP pull_request_urls');
    }
}
