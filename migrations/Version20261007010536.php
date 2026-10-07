<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007010536 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The source request of a carried request on change_request';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE change_request ADD carried_from_review_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE change_request ADD carried_from_request_id UUID DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE change_request DROP carried_from_review_id');
        $this->addSql('ALTER TABLE change_request DROP carried_from_request_id');
    }
}
