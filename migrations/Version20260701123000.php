<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add optional manual text context fields to post checks.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post_check ADD context_country VARCHAR(80) DEFAULT NULL');
        $this->addSql('ALTER TABLE post_check ADD context_topic VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE post_check DROP context_country');
        $this->addSql('ALTER TABLE post_check DROP context_topic');
    }
}
