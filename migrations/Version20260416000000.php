<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260416000000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE activity_log (
                id         INT AUTO_INCREMENT NOT NULL,
                user_token VARCHAR(36)  NOT NULL,
                steam_id   VARCHAR(20)  NOT NULL,
                type       VARCHAR(20)  NOT NULL,
                app_id     INT          NOT NULL,
                app_name   VARCHAR(255) NOT NULL,
                metadata   JSON         NULL,
                created_at DATETIME     NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX idx_created_at (created_at),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE activity_log');
    }
}
