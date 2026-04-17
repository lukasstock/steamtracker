<?php

namespace App\Command;

use App\Service\SteamApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'steam:find-game', description: 'Find a game in the Steam library and show its raw API data')]
class SteamFindGameCommand extends Command
{
    public function __construct(
        private readonly SteamApiService $steamApiService,
        private readonly string $steamId,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('search', InputArgument::REQUIRED, 'Partial game name to search for');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $search = strtolower($input->getArgument('search'));
        $games  = $this->steamApiService->getOwnedGames($this->steamId);

        $matches = array_filter($games, fn($g) => str_contains(strtolower($g['name']), $search));

        if (empty($matches)) {
            $output->writeln("<comment>No games found matching \"$search\"</comment>");
            return Command::SUCCESS;
        }

        foreach ($matches as $game) {
            $appid = $game['appid'];
            $name  = $game['name'];

            $output->writeln("<info>$name</info>");
            $output->writeln("  appid: $appid");

            $details = $this->steamApiService->getAppDetails($appid);
            if (!empty($details['header_image'])) {
                $output->writeln("  header_image (from appdetails): " . $details['header_image']);
            } else {
                $output->writeln("  <comment>appdetails returned no header_image</comment>");
            }

            $output->writeln('');
        }

        return Command::SUCCESS;
    }
}
