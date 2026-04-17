<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:cache:clear-achievements', description: 'Truncate the achievement_cache table so all entries re-fetch on next request')]
class ClearAchievementCacheCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM achievement_cache');
        $this->connection->executeStatement('TRUNCATE TABLE achievement_cache');
        $output->writeln("Cleared $count achievement cache rows. They will re-fetch on next request.");
        return Command::SUCCESS;
    }
}
