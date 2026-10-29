<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261029003653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The time of the check run request of a pull request, which expires (F87, D96)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pull_request ADD check_run_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE pull_request DROP check_run_requested');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE pull_request ADD check_run_requested BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE pull_request DROP check_run_requested_at');
    }
}
