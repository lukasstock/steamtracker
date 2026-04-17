<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260414100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Multi-user: add user_token to game_completion, create user_profile table';
    }

    public function up(Schema $schema): void
    {
        $gameCompletion = $schema->getTable('game_completion');

        // Drop the old single-column unique index on app_id
        foreach ($gameCompletion->getIndexes() as $index) {
            if ($index->isUnique() && !$index->isPrimary() && $index->getColumns() === ['app_id']) {
                $gameCompletion->dropIndex($index->getName());
                break;
            }
        }

        // Add nullable user_token column (nullable so existing rows aren't broken)
        $gameCompletion->addColumn('user_token', Types::STRING, ['length' => 36, 'notnull' => false]);

        // New composite unique: one tracker entry per (user, game)
        $gameCompletion->addUniqueIndex(['user_token', 'app_id'], 'uq_user_app');

        // Create user_profile table
        $userProfile = $schema->createTable('user_profile');
        $userProfile->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
        $userProfile->addColumn('user_token', Types::STRING, ['length' => 36]);
        $userProfile->addColumn('steam_id', Types::STRING, ['length' => 20]);
        $userProfile->addColumn('vanity_url', Types::STRING, ['length' => 100, 'notnull' => false]);
        $userProfile->addColumn('created_at', Types::DATETIME_IMMUTABLE);
        $userProfile->setPrimaryKey(['id']);
        $userProfile->addUniqueIndex(['user_token'], 'UNIQ_user_profile_token');
        $userProfile->addUniqueIndex(['steam_id'], 'UNIQ_user_profile_steam_id');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('user_profile');

        $gameCompletion = $schema->getTable('game_completion');
        $gameCompletion->dropIndex('uq_user_app');
        $gameCompletion->dropColumn('user_token');
        $gameCompletion->addUniqueIndex(['app_id']);
    }
}
