<?php

namespace App\Command;

use App\Entity\GameCompletion;
use App\Entity\UserProfile;
use App\Enum\GameStatus;
use App\Repository\UserProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(name: 'app:seed-leaderboard', description: 'Seed fake leaderboard profiles from config/seeds/fake_users.json')]
class SeedLeaderboardCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserProfileRepository $userProfileRepository,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $seedFile = $this->projectDir . '/config/seeds/fake_users.json';

        if (!file_exists($seedFile)) {
            $output->writeln('<error>Seed file not found: ' . $seedFile . '</error>');
            return Command::FAILURE;
        }

        $users = json_decode(file_get_contents($seedFile), true);

        foreach ($users as $userData) {
            $steamId = $userData['steam_id'];

            if ($this->userProfileRepository->findOneBy(['steamId' => $steamId])) {
                $output->writeln("Skipping {$steamId} — already exists");
                continue;
            }

            $profile = (new UserProfile())
                ->setSteamId($steamId)
                ->setUserToken(Uuid::v4()->toRfc4122())
                ->setShowInLeaderboard(true)
                ->setCreatedAt(new \DateTimeImmutable());

            $this->em->persist($profile);
            $this->em->flush();

            $token = $profile->getUserToken();

            foreach ($userData['completed'] ?? [] as $entry) {
                $gc = (new GameCompletion())
                    ->setAppId($entry['app_id'])
                    ->setUserToken($token)
                    ->setStatus(GameStatus::Completed)
                    ->setCompletedAt(new \DateTimeImmutable())
                    ->setRating($entry['rating'] ?? null);
                $this->em->persist($gc);
            }

            foreach ($userData['playing'] ?? [] as $appId) {
                $gc = (new GameCompletion())
                    ->setAppId($appId)
                    ->setUserToken($token)
                    ->setStatus(GameStatus::Playing);
                $this->em->persist($gc);
            }

            $this->em->flush();
            $output->writeln("Created profile for Steam ID {$steamId}");
        }

        $output->writeln('Done.');
        return Command::SUCCESS;
    }
}
