<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261007195224 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Browser sessions move from PostgreSQL to files (D58)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE sessions');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE sessions (sess_id VARCHAR(128) NOT NULL, sess_data BYTEA NOT NULL, sess_lifetime INT NOT NULL, sess_time INT NOT NULL, PRIMARY KEY (sess_id))');
        $this->addSql('CREATE INDEX sess_lifetime_idx ON sessions (sess_lifetime)');
    }
}
