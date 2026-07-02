<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702091731 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add low confidence evidence candidates to post checks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post_check ADD low_confidence_evidence_candidates JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post_check DROP low_confidence_evidence_candidates');
    }
}