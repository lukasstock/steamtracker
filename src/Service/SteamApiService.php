<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class SteamApiService
{
    // Cache TTLs - kept as named constants so they're easy to find and adjust
    private const TTL_NOW_PLAYING     = 60;       // 1 minute - keep current game fresh
    private const TTL_OWNED_GAMES     = 3600;     // 1 hour
    private const TTL_FRIENDS         = 900;      // 15 minutes
    private const TTL_GLOBAL_ACH_PCT  = 604800;   // 7 days   - percentages change slowly
    private const TTL_GAME_SCHEMA     = 2592000;  // 30 days  - achievement metadata is stable
    private const TTL_HEADER_IMAGE    = 2592000;  // 30 days

    private Client $client;

    public function __construct(
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
    ) {
        $this->client = new Client();
    }

    public function getPlayerSummary(string $steamId): array
    {
        $data = $this->get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/', [
            'key' => $this->apiKey,
            'steamids' => $steamId,
        ]);

        return $data['response']['players'][0] ?? [];
    }

    public function getPlayersSummaries(array $steamIds): array
    {
        $data = $this->get('https://api.steampowered.com/ISteamUser/GetPlayerSummaries/v0002/', [
            'key' => $this->apiKey,
            'steamids' => implode(',', $steamIds),
        ]);

        return $data['response']['players'] ?? [];
    }

    public function getOwnedGames(string $steamId): array
    {
        $cacheKey = 'steam_owned_games_' . $steamId;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($steamId) {
            $item->expiresAfter(self::TTL_OWNED_GAMES);

            $data = $this->get('https://api.steampowered.com/IPlayerService/GetOwnedGames/v1/', [
                'key'                   => $this->apiKey,
                'steamid'               => $steamId,
                'include_appinfo'       => 1,
                'include_played_free_games' => 1,
            ]);

            return $data['response']['games'] ?? [];
        });
    }

    public function getRecentGames(string $steamId): array
    {
        $data = $this->get('https://api.steampowered.com/IPlayerService/GetRecentlyPlayedGames/v0001/', [
            'key' => $this->apiKey,
            'steamid' => $steamId,
        ]);

        return $data['response']['games'] ?? [];
    }

    public function getTotalPlaytimeHours(string $steamId): float
    {
        $games = $this->getOwnedGames($steamId);
        $totalMinutes = array_sum(array_column($games, 'playtime_forever'));

        return round($totalMinutes / 60, 1);
    }

    public function getPlaytimeLast2WeeksHours(array $recentGames): float
    {
        $totalMinutes = array_sum(array_column($recentGames, 'playtime_2weeks'));

        return round($totalMinutes / 60, 1);
    }

    public function getCurrentlyPlaying(string $steamId): ?array
    {
        $cacheKey = 'steam_now_playing_' . $steamId;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($steamId) {
            $item->expiresAfter(self::TTL_NOW_PLAYING);

            $summary = $this->getPlayerSummary($steamId);
            if (empty($summary['gameid'])) {
                return null;
            }

            return [
                'appid' => (int) $summary['gameid'],
                'name'  => $summary['gameextrainfo'] ?? 'Unknown Game',
            ];
        });
    }

    /**
     * Verifies a Steam OpenID callback and returns the confirmed Steam ID, or null on failure.
     *
     * @param array<string, string> $params The query parameters from the callback request
     */
    public function verifyOpenIdCallback(array $params): ?string
    {
        if (($params['openid.mode'] ?? '') !== 'id_res') {
            return null;
        }

        $claimedId = $params['openid.claimed_id'] ?? '';
        if (!preg_match('~steamcommunity\.com/openid/id/(\d{17})~', $claimedId, $m)) {
            return null;
        }
        $steamId = $m[1];

        $verifyParams                = $params;
        $verifyParams['openid.mode'] = 'check_authentication';

        try {
            $response = $this->client->post('https://steamcommunity.com/openid/login', [
                'form_params' => $verifyParams,
            ]);
            $body = $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            $this->logger->error('Steam OpenID verification failed', ['error' => $e->getMessage()]);
            return null;
        }

        return str_contains($body, 'is_valid:true') ? $steamId : null;
    }

    /**
     * Accepts a full Steam profile URL, a vanity name, or a 17-digit Steam ID.
     * Returns the resolved 64-bit Steam ID string, or null if not found.
     */
    public function resolveSteamInput(string $input): ?string
    {
        $input = trim($input);

        if (str_contains($input, 'steamcommunity.com')) {
            if (preg_match('~steamcommunity\.com/profiles/(\d{17})~', $input, $m)) {
                return $m[1];
            }
            if (preg_match('~steamcommunity\.com/id/([^/?#\s]+)~', $input, $m)) {
                return $this->resolveVanityUrl($m[1]);
            }
            return null;
        }

        if (preg_match('/^\d{17}$/', $input)) {
            return $input;
        }

        return $this->resolveVanityUrl($input);
    }

    public function resolveVanityUrl(string $vanityUrl): ?string
    {
        $data = $this->get('https://api.steampowered.com/ISteamUser/ResolveVanityURL/v0001/', [
            'key'       => $this->apiKey,
            'vanityurl' => $vanityUrl,
        ]);

        if (($data['response']['success'] ?? 0) !== 1) {
            return null;
        }

        return $data['response']['steamid'] ?? null;
    }

    public function getHeaderImageUrl(int $appId): ?string
    {
        $cacheKey = 'steam_header_image_' . $appId;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($appId) {
            $item->expiresAfter(self::TTL_HEADER_IMAGE);

            $details = $this->getAppDetails($appId);
            return $details['header_image'] ?? null;
        });
    }

    /**
     * Returns an array of friend Steam IDs, or null if the friend list is private.
     *
     * @return string[]|null
     */
    public function getFriendList(string $steamId): ?array
    {
        $cacheKey = 'steam_friends_' . $steamId;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($steamId) {
            $item->expiresAfter(self::TTL_FRIENDS);
            try {
                $data = $this->get('https://api.steampowered.com/ISteamUser/GetFriendList/v0001/', [
                    'key'          => $this->apiKey,
                    'steamid'      => $steamId,
                    'relationship' => 'friend',
                ]);
                return array_column($data['friendslist']['friends'] ?? [], 'steamid');
            } catch (\RuntimeException) {
                return null; // private friend list or API error
            }
        });
    }

    /**
     * Returns all achievements for a player in a specific game, or null if the game
     * has no achievements, the profile is private, or the API call fails.
     * Each entry: apiname, achieved (0|1), unlocktime, name, description.
     *
     * @return array<array{apiname:string,achieved:int,unlocktime:int,name:string,description:string}>|null
     */
    public function getPlayerAchievements(string $steamId, int $appId): ?array
    {
        try {
            $data = $this->get('https://api.steampowered.com/ISteamUserStats/GetPlayerAchievements/v0001/', [
                'key'     => $this->apiKey,
                'steamid' => $steamId,
                'appid'   => $appId,
                'l'       => 'english',
            ]);
        } catch (\RuntimeException) {
            return null;
        }

        if (!($data['playerstats']['success'] ?? false)) {
            return null;
        }

        return $data['playerstats']['achievements'] ?? [];
    }

    /**
     * Returns global achievement unlock percentages keyed by achievement API name.
     * E.g., ['ACH_WIN_100_GAMES' => 12.5, ...]
     *
     * @return array<string, float>
     */
    public function getGlobalAchievementPercentages(int $appId): array
    {
        $cacheKey = 'steam_global_ach_pct_' . $appId;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($appId) {
            $item->expiresAfter(self::TTL_GLOBAL_ACH_PCT);

            try {
                $data = $this->get(
                    'https://api.steampowered.com/ISteamUserStats/GetGlobalAchievementPercentagesForApp/v0002/',
                    ['gameid' => $appId]
                );
            } catch (\RuntimeException) {
                return [];
            }

            $result = [];
            foreach ($data['achievementpercentages']['achievements'] ?? [] as $ach) {
                $result[$ach['name']] = (float) $ach['percent'];
            }
            return $result;
        });
    }

    /**
     * Returns game achievement schema keyed by achievement API name.
     * Each entry includes: name, displayName, description, icon, icongray.
     *
     * @return array<string, array{name:string,displayName:string,description:string,icon:string,icongray:string}>
     */
    public function getGameSchema(int $appId): array
    {
        $cacheKey = 'steam_game_schema_' . $appId;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($appId) {
            $item->expiresAfter(self::TTL_GAME_SCHEMA);

            try {
                $data = $this->get('https://api.steampowered.com/ISteamUserStats/GetSchemaForGame/v0002/', [
                    'key'   => $this->apiKey,
                    'appid' => $appId,
                    'l'     => 'english',
                ]);
            } catch (\RuntimeException) {
                return [];
            }

            $result = [];
            foreach ($data['game']['availableGameStats']['achievements'] ?? [] as $ach) {
                $result[$ach['name']] = $ach;
            }
            return $result;
        });
    }

    public function getAppDetails(int $appId): array
    {
        $data = $this->get("https://store.steampowered.com/api/appdetails", [
            'appids' => $appId,
        ]);

        if (!($data[$appId]['success'] ?? false)) {
            return [];
        }

        return $data[$appId]['data'] ?? [];
    }

    private function get(string $url, array $query = []): array
    {
        try {
            $response = $this->client->get($url, ['query' => $query]);
            $data = json_decode($response->getBody()->getContents(), true);

            if (!is_array($data)) {
                throw new \RuntimeException('Failed to decode Steam API response.');
            }

            return $data;
        } catch (GuzzleException $e) {
            $this->logger->error('Steam API request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Steam API request failed: ' . $e->getMessage(), 0, $e);
        }
    }
}
