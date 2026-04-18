<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260418100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create feedback_submission table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE feedback_submission (
            id INT AUTO_INCREMENT NOT NULL,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(150) DEFAULT NULL,
            type VARCHAR(20) NOT NULL,
            message LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE feedback_submission');
    }
}
