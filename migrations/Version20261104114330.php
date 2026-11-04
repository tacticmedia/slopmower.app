<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261104114330 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The code access of a repository (F116, D111)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE repository ADD code_access VARCHAR(255) DEFAULT 'clone' NOT NULL");
        $this->addSql('ALTER TABLE repository ALTER code_access DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE repository DROP code_access');
    }
}
