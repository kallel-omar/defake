<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260613001715 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE post_check ADD evidence_score INT DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD source_score INT DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD language_score INT DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD verification_score INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE post_check DROP evidence_score');
        $this->addSql('ALTER TABLE post_check DROP source_score');
        $this->addSql('ALTER TABLE post_check DROP language_score');
        $this->addSql('ALTER TABLE post_check DROP verification_score');
    }
}
