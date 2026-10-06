<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261006225541 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Symbol lookup by the SHA-256 of the FQN for code navigation (V14)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE symbol ADD fqn_sha256 CHAR(64) DEFAULT NULL');
        // The hash of IndexSql: lower-case hex of the UTF-8 bytes.
        $this->addSql("UPDATE symbol SET fqn_sha256 = encode(sha256(convert_to(fqn, 'UTF8')), 'hex')");
        $this->addSql('ALTER TABLE symbol ALTER fqn_sha256 SET NOT NULL');
        $this->addSql('CREATE INDEX idx_symbol_fqn ON symbol (tenant_id, repository_id, fqn_sha256)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX idx_symbol_fqn');
        $this->addSql('ALTER TABLE symbol DROP fqn_sha256');
    }
}
