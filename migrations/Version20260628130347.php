<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260628130347 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE post_check ADD scoring_version VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD score_breakdown JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD evidence_decision VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD source_decision VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD risk_decision VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD caps_applied JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE post_check DROP scoring_version');
        $this->addSql('ALTER TABLE post_check DROP score_breakdown');
        $this->addSql('ALTER TABLE post_check DROP evidence_decision');
        $this->addSql('ALTER TABLE post_check DROP source_decision');
        $this->addSql('ALTER TABLE post_check DROP risk_decision');
        $this->addSql('ALTER TABLE post_check DROP caps_applied');
    }
}
