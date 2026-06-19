<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613021243 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE post_check ADD evidence_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD source_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD language_reason TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD verification_reason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE post_check DROP evidence_reason');
        $this->addSql('ALTER TABLE post_check DROP source_reason');
        $this->addSql('ALTER TABLE post_check DROP language_reason');
        $this->addSql('ALTER TABLE post_check DROP verification_reason');
    }
}
