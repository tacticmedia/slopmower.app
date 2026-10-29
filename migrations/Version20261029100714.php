<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261029100714 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The delivery choice of a review (F76, D85)';
    }

    /**
     * No stored event has a delivery yet, so null is the projected value of every review.
     */
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE review ADD delivery_workspace VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE review ADD delivery_pull_request BOOLEAN DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE review DROP delivery_workspace');
        $this->addSql('ALTER TABLE review DROP delivery_pull_request');
    }
}
