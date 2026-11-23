<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261123014011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The kind of a link of two issues (D126)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue_link ADD kind VARCHAR(255) DEFAULT \'relates\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE issue_link DROP kind');
    }
}
