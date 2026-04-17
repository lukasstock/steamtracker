<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260415120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add achievement_cache table for per-user Steam achievement data';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('achievement_cache');
        $table->addColumn('steam_id',         Types::STRING,           ['length' => 20, 'notnull' => true]);
        $table->addColumn('app_id',           Types::INTEGER,          ['notnull' => true]);
        $table->addColumn('unlocked_count',   Types::SMALLINT,         ['notnull' => true, 'default' => 0]);
        $table->addColumn('total_count',      Types::SMALLINT,         ['notnull' => true, 'default' => 0]);
        $table->addColumn('rare_achievements',Types::JSON,             ['notnull' => false]);
        $table->addColumn('fetched_at',       Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['steam_id', 'app_id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('achievement_cache');
    }
}
