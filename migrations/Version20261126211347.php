<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126211347 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The acceptance criteria section of an issue (D147)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue ADD acceptance_criteria TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue DROP acceptance_criteria');
    }
}
