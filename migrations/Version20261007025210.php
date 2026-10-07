<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007025210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The plan requirement and the current proposal on change_request, the plan requirement on draft';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE change_request ADD require_approval BOOLEAN DEFAULT false NOT NULL');
        $this->addSql('ALTER TABLE change_request ADD proposal_event_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE change_request ADD proposal_state VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE draft ADD require_approval BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE change_request DROP require_approval');
        $this->addSql('ALTER TABLE change_request DROP proposal_event_id');
        $this->addSql('ALTER TABLE change_request DROP proposal_state');
        $this->addSql('ALTER TABLE draft DROP require_approval');
    }
}
