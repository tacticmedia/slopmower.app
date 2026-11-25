<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125053538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The claim of an issue by an agent session (D130)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue ADD claim_session VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE issue ADD claim_since TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE issue ADD claim_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE issue ADD claim_holder_kind VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE issue ADD claim_holder_user_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE issue ADD claim_holder_agent_name VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE issue DROP claim_session');
        $this->addSql('ALTER TABLE issue DROP claim_since');
        $this->addSql('ALTER TABLE issue DROP claim_until');
        $this->addSql('ALTER TABLE issue DROP claim_holder_kind');
        $this->addSql('ALTER TABLE issue DROP claim_holder_user_id');
        $this->addSql('ALTER TABLE issue DROP claim_holder_agent_name');
    }
}
