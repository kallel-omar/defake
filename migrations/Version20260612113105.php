<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260612113105 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE post_check ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD CONSTRAINT FK_AB9520FCA76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_AB9520FCA76ED395 ON post_check (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE post_check DROP CONSTRAINT FK_AB9520FCA76ED395');
        $this->addSql('DROP INDEX IDX_AB9520FCA76ED395');
        $this->addSql('ALTER TABLE post_check DROP user_id');
    }
}
