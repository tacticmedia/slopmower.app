<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126092840 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The notes section of an issue (D143)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue ADD notes TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue DROP notes');
    }
}
