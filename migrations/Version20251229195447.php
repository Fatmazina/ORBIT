<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251229195447 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment ADD is_archived TINYINT NOT NULL, ADD archived_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE post CHANGE is_archived is_archived TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE comment DROP is_archived, DROP archived_at');
        $this->addSql('ALTER TABLE post CHANGE is_archived is_archived TINYINT DEFAULT 0 NOT NULL');
    }
}
