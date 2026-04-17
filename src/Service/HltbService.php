<?php

namespace App\Service;

use GuzzleHttp\Client;

class HltbService
{
    private const BASE = 'https://howlongtobeat.com';
    private const UA   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    private Client $client;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 10.0]);
    }

    /**
     * Returns the main-story completion time in whole hours, or null if not found / unavailable.
     *
     * HLTB API flow (as of 2025):
     *   1. GET /api/find/init?t={ms} → {token, hpKey, hpVal}
     *   2. POST /api/find with x-auth-token header + honeypot fields
     */
    public function lookup(string $gameName): ?int
    {
        try {
            // Step 1: obtain a short-lived token and honeypot key/value
            $t    = (int) round(microtime(true) * 1000);
            $init = json_decode(
                $this->client->get(self::BASE . '/api/find/init?t=' . $t, [
                    'headers' => [
                        'User-Agent' => self::UA,
                        'Referer'    => self::BASE . '/',
                    ],
                ])->getBody()->getContents(),
                true
            );

            $token = $init['token'] ?? null;
            $hpKey = $init['hpKey'] ?? null;
            $hpVal = $init['hpVal'] ?? null;

            if (!$token) {
                return null;
            }

            // Step 2: search — honeypot field is merged into the body AND sent as headers
            $body = [
                'searchType'    => 'games',
                'searchTerms'   => array_values(array_filter(explode(' ', trim($gameName)))),
                'searchPage'    => 1,
                'size'          => 5,
                'searchOptions' => [
                    'games' => [
                        'userId'        => 0,
                        'platform'      => '',
                        'sortCategory'  => 'popular',
                        'rangeCategory' => 'main',
                        'rangeTime'     => ['min' => 0, 'max' => 0],
                        'gameplay'      => ['perspective' => '', 'flow' => '', 'genre' => '', 'difficulty' => ''],
                        'rangeYear'     => ['min' => 0, 'max' => 0],
                        'modifier'      => '',
                    ],
                    'users'  => ['sortCategory' => ''],
                    'lists'  => ['sortCategory' => ''],
                    'filter' => '',
                    'sort'   => 0,
                    'randomizer' => 0,
                ],
                'useCache' => true,
            ];

            // Inject honeypot into body if present
            if ($hpKey && $hpVal) {
                $body[$hpKey] = $hpVal;
            }

            $headers = [
                'User-Agent'    => self::UA,
                'Content-Type'  => 'application/json',
                'Origin'        => self::BASE,
                'Referer'       => self::BASE . '/',
                'x-auth-token'  => $token,
            ];
            if ($hpKey) $headers['x-hp-key'] = $hpKey;
            if ($hpVal) $headers['x-hp-val'] = $hpVal;

            $response = $this->client->post(self::BASE . '/api/find', [
                'headers' => $headers,
                'json'    => $body,
            ]);

            $data    = json_decode($response->getBody()->getContents(), true);
            $results = $data['data'] ?? [];

            if (empty($results)) {
                return null;
            }

            $compMain = $results[0]['comp_main'] ?? null;
            return $compMain ? (int) round($compMain / 3600) : null;

        } catch (\Throwable) {
            return null;
        }
    }
}
