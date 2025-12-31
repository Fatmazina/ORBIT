<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251231191856 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE user_follows (user_id INT NOT NULL, following_user_id INT NOT NULL, INDEX IDX_136E9479A76ED395 (user_id), INDEX IDX_136E94791896F387 (following_user_id), PRIMARY KEY (user_id, following_user_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE user_follows ADD CONSTRAINT FK_136E9479A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE user_follows ADD CONSTRAINT FK_136E94791896F387 FOREIGN KEY (following_user_id) REFERENCES `user` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE user_follows DROP FOREIGN KEY FK_136E9479A76ED395');
        $this->addSql('ALTER TABLE user_follows DROP FOREIGN KEY FK_136E94791896F387');
        $this->addSql('DROP TABLE user_follows');
    }
}
