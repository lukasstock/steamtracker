<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260414150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add hltb_cache table for HowLongToBeat data';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('hltb_cache');
        $table->addColumn('app_id',     Types::INTEGER,          ['notnull' => true]);
        $table->addColumn('hours_main', Types::SMALLINT,         ['notnull' => false]);
        $table->addColumn('fetched_at', Types::DATETIME_IMMUTABLE);
        $table->setPrimaryKey(['app_id']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('hltb_cache');
    }
}
