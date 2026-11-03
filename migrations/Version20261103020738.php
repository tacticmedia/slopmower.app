<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261103020738 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The group of a draft (F98, D103)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE draft ADD group_label VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE draft DROP group_label');
    }
}
