<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261122062002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The label of a comment of an issue (D121)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE issue_entry ADD label VARCHAR(255) DEFAULT NULL');
        // The word that a stored comment starts with is its label. LIKE is case-sensitive, and the bodies stay.
        $this->addSql(<<<'SQL'
            UPDATE issue_entry SET label = CASE
                WHEN body LIKE 'Decision:%' THEN 'decision'
                WHEN body LIKE 'Finding:%' THEN 'finding'
                WHEN body LIKE 'Deviation:%' THEN 'deviation'
                WHEN body LIKE 'Scope creep:%' THEN 'scope_creep'
                WHEN body LIKE 'Papercut:%' THEN 'papercut'
                WHEN body LIKE 'Question:%' THEN 'question'
                WHEN body LIKE 'Plan review%' THEN 'review'
                ELSE 'comment'
            END
            WHERE kind = 'comment'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE issue_entry DROP label');
    }
}
