<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260417200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing indexes on activity_log for steam_id and user_token+app_id queries';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_steam_id ON activity_log (steam_id)');
        $this->addSql('CREATE INDEX idx_user_token_app_id ON activity_log (user_token, app_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_steam_id ON activity_log');
        $this->addSql('DROP INDEX idx_user_token_app_id ON activity_log');
    }
}
