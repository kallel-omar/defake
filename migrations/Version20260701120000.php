<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add opaque public tokens to post check results.';
    }

    public function up(Schema $schema): void
    {
        $this->connection->executeStatement(
            'ALTER TABLE post_check ADD COLUMN IF NOT EXISTS public_token VARCHAR(64) DEFAULT NULL'
        );

        $knownTokens = array_flip(array_filter(
            $this->connection->fetchFirstColumn('SELECT public_token FROM post_check WHERE public_token IS NOT NULL')
        ));

        $ids = $this->connection->fetchFirstColumn('SELECT id FROM post_check WHERE public_token IS NULL');

        foreach ($ids as $id) {
            do {
                $token = bin2hex(random_bytes(32));
            } while (isset($knownTokens[$token]));

            $knownTokens[$token] = true;

            $this->connection->update(
                'post_check',
                ['public_token' => $token],
                ['id' => (int) $id]
            );
        }

        $this->addSql('ALTER TABLE post_check ALTER COLUMN public_token SET NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_POST_CHECK_PUBLIC_TOKEN ON post_check (public_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS UNIQ_POST_CHECK_PUBLIC_TOKEN');
        $this->addSql('ALTER TABLE post_check DROP COLUMN IF EXISTS public_token');
    }
}
