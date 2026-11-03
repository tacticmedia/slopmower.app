<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103014441 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The request type of a draft (F97, D102)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE draft ADD type VARCHAR(255) DEFAULT \'change\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE draft DROP type');
    }
}
