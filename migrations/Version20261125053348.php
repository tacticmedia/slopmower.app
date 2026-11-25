<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261125053348 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The approval of an issue and the approval guidelines of a project (D129)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue ADD approval VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE project ADD approval_guidelines TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE issue DROP approval');
        $this->addSql('ALTER TABLE project DROP approval_guidelines');
    }
}
