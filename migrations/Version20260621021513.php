<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260621021513 extends AbstractMigration
{
     public function getDescription(): string
    {
        return 'Promote main user to admin';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE \"user\" SET roles = '[\"ROLE_ADMIN\", \"ROLE_USER\"]' WHERE email = 'omarkallel93@gmail.com'"
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            "UPDATE \"user\" SET roles = '[\"ROLE_USER\"]' WHERE email = 'omarkallel93@gmail.com'"
        );
    }
}
