<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261002054421 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_user (id UUID NOT NULL, tenant_id UUID NOT NULL, sign_in_method VARCHAR(255) NOT NULL, github_user_id BIGINT DEFAULT NULL, login VARCHAR(255) NOT NULL, name VARCHAR(255) DEFAULT NULL, avatar_url TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, last_sign_in_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_88BDF3E9A8674B11 ON app_user (github_user_id)');
        $this->addSql('CREATE INDEX idx_app_user_tenant ON app_user (tenant_id)');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_user_local_login ON app_user (login) WHERE ((sign_in_method)::text = \'local\'::text)');
        $this->addSql('CREATE TABLE tenant (id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, event_seq BIGINT NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE app_user');
        $this->addSql('DROP TABLE tenant');
    }
}
