<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127071854 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'YOLO mode of a project (D148)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project ADD yolo_mode BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE project DROP yolo_mode');
    }
}
