<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261016074510 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The title of a request and of a draft (F58)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE change_request ADD title VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE draft ADD title VARCHAR(200) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE change_request DROP title');
        $this->addSql('ALTER TABLE draft DROP title');
    }
}
