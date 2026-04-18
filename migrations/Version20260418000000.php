<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260418000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add show_in_leaderboard flag to user_profile';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profile ADD show_in_leaderboard TINYINT(1) NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user_profile DROP COLUMN show_in_leaderboard');
    }
}
