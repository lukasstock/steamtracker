<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260411213644 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_completion ADD status VARCHAR(20) NOT NULL DEFAULT \'unplayed\', ADD rating INT DEFAULT NULL, ADD notes LONGTEXT DEFAULT NULL');
        $this->addSql('UPDATE game_completion SET status = \'completed\' WHERE completed = 1');
        $this->addSql('ALTER TABLE game_completion DROP completed');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE game_completion ADD completed TINYINT NOT NULL, DROP status, DROP rating, DROP notes');
    }
}
