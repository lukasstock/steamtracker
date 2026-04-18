<?php

namespace App\Service;

use GuzzleHttp\Client as GuzzleClient;

/**
 * Generates the 1200×630 Open Graph preview image for a player's library page.
 *
 * The canvas is split into a wide left panel (stats + player info) and a narrow
 * right panel showing up to three game covers stacked vertically.
 */
class OgImageService
{
    // Canvas dimensions
    private const W      = 1200;
    private const H      = 630;
    private const SPLIT  = 730;   // x-coordinate where the right panel begins

    // Font paths (DejaVu ships in the Docker image)
    private const FONT_BOLD   = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    private const FONT_NORMAL = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';

    /**
     * @param  array  $player       Player summary from Steam API
     * @param  array  $games        Owned games from Steam API
     * @param  array  $completionMap  GameCompletion entities indexed by appId
     * @param  string $steamId      Used for the owner's special "top %" badge
     * @return string               Raw PNG bytes
     */
    public function generate(array $player, array $games, array $completionMap, string $steamId): string
    {
        [$stats, $coverAppIds] = $this->buildStats($games, $completionMap);

        $img     = $this->createCanvas();
        $colors  = $this->allocateColors($img);
        $guzzle  = new GuzzleClient(['timeout' => 5]);

        $this->drawBackground($img, $colors);
        $this->drawTopGradientBar($img);
        $this->drawPlayerSection($img, $colors, $player, $stats, $steamId, $guzzle);
        $this->drawStatsSection($img, $colors, $stats);
        $this->drawProgressBar($img, $stats['pct']);
        $this->drawGameCovers($img, $coverAppIds, $guzzle);

        ob_start();
        imagepng($img);
        $png = ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    // ── Data preparation ──────────────────────────────────────────────────────

    private function buildStats(array $games, array $completionMap): array
    {
        $completed = 0;
        $playing   = 0;
        $totalMins = 0;
        $spotlightAppIds = [];

        foreach ($games as $game) {
            $completion = $completionMap[$game['appid']] ?? null;
            $status     = $completion?->getStatus()->value ?? 'unplayed';

            if ($status === 'completed') $completed++;
            if ($status === 'playing')   $playing++;
            $totalMins += $game['playtime_forever'];
            if ($completion?->isSpotlight()) {
                $spotlightAppIds[] = $game['appid'];
            }
        }

        $total      = count($games);
        $totalHours = (int) round($totalMins / 60);
        $pct        = $total > 0 ? round(($completed / $total) * 100, 1) : 0.0;

        // Cover art: spotlight games first, then fill from most-played
        $coverAppIds = array_slice($spotlightAppIds, 0, 3);
        if (count($coverAppIds) < 3 && !empty($games)) {
            $byPlaytime = $games;
            usort($byPlaytime, fn($a, $b) => $b['playtime_forever'] - $a['playtime_forever']);
            foreach ($byPlaytime as $g) {
                if (!in_array($g['appid'], $coverAppIds)) {
                    $coverAppIds[] = $g['appid'];
                    if (count($coverAppIds) >= 3) break;
                }
            }
        }

        $stats = [
            'total'      => $total,
            'completed'  => $completed,
            'playing'    => $playing,
            'backlog'    => $total - $completed - $playing,
            'totalHours' => $totalHours,
            'pct'        => $pct,
        ];

        return [$stats, $coverAppIds];
    }

    // ── Canvas + color setup ──────────────────────────────────────────────────

    /** @return \GdImage */
    private function createCanvas(): mixed
    {
        return imagecreatetruecolor(self::W, self::H);
    }

    /** @return array<string, int> */
    private function allocateColors(mixed $img): array
    {
        return [
            'bg'    => imagecolorallocate($img,  10,  16,  24),
            'dark'  => imagecolorallocate($img,  14,  22,  32),
            'blue'  => imagecolorallocate($img,  26, 159, 255),
            'green' => imagecolorallocate($img, 164, 208,   7),
            'white' => imagecolorallocate($img, 255, 255, 255),
            'label' => imagecolorallocate($img, 175, 188, 200),
            'muted' => imagecolorallocate($img, 120, 135, 150),
            'sep'   => imagecolorallocate($img,  28,  40,  54),
            'barBg' => imagecolorallocate($img,  20,  32,  44),
        ];
    }

    // ── Drawing helpers ───────────────────────────────────────────────────────

    private function drawBackground(mixed $img, array $c): void
    {
        imagefill($img, 0, 0, $c['bg']);
        imagefilledrectangle($img, self::SPLIT, 0, self::W, self::H, $c['dark']);
        // Thin separator line between the two panels
        imagefilledrectangle($img, self::SPLIT, 6, self::SPLIT + 1, self::H, $c['sep']);
    }

    private function drawTopGradientBar(mixed $img): void
    {
        // 6px horizontal gradient bar from Steam blue → Steam green
        for ($x = 0; $x < self::W; $x++) {
            $t = $x / self::W;
            $r = (int)(26  + $t * (164 - 26));
            $g = (int)(159 + $t * (208 - 159));
            $b = (int)(255 + $t * (7   - 255));
            $color = imagecolorallocate($img, $r, $g, $b);
            imagefilledrectangle($img, $x, 0, $x, 5, $color);
        }
    }

    private function drawPlayerSection(
        mixed $img, array $c, array $player, array $stats, string $steamId, GuzzleClient $guzzle
    ): void {
        // Logo
        $logoSize = 44;
        $logoY    = 68;
        $gameBox  = imagettfbbox($logoSize, 0, self::FONT_BOLD, 'GAME ');
        $gameW    = abs($gameBox[2] - $gameBox[0]);
        imagettftext($img, $logoSize, 0,           45, $logoY, $c['white'], self::FONT_BOLD, 'GAME ');
        imagettftext($img, $logoSize, 0, 45 + $gameW, $logoY, $c['blue'],  self::FONT_BOLD, 'TRACKR');

        // Avatar (circle-ish: just square, Discord rounds it anyway)
        $avatarSize = 55;
        $avatarX    = 45;
        $avatarY    = 90;

        if (!empty($player['avatarmedium'])) {
            try {
                $avatarData = $guzzle->get($player['avatarmedium'])->getBody()->getContents();
                $avatarImg  = imagecreatefromstring($avatarData);
                if ($avatarImg !== false) {
                    imagecopyresampled(
                        $img, $avatarImg,
                        $avatarX, $avatarY, 0, 0,
                        $avatarSize, $avatarSize,
                        imagesx($avatarImg), imagesy($avatarImg)
                    );
                    imagedestroy($avatarImg);
                }
            } catch (\Throwable) {
                // Non-fatal - image just won't appear
            }
        }

        $playerName = mb_substr($player['personaname'] ?? 'Steam Player', 0, 20);
        $nameX      = $avatarX + $avatarSize + 14;
        $nameY      = $avatarY + 40;
        imagettftext($img, 22, 0, $nameX, $nameY, $c['white'], self::FONT_BOLD, $playerName);

        $this->drawTopPercentBadge($img, $c, $stats, $steamId, $playerName, $nameX, $nameY);

        // Divider below player section
        imagefilledrectangle($img, 45, 162, self::SPLIT - 20, 163, $c['sep']);
    }

    private function drawTopPercentBadge(
        mixed $img, array $c, array $stats, string $steamId, string $playerName, int $nameX, int $nameY
    ): void {
        if ($steamId === '76561198088051125') {
            $topPct = 0.3;
        } else {
            $pct        = $stats['pct'];
            $totalHours = $stats['totalHours'];
            $compRank   = $pct > 0 ? max(0.1, 100.0 * pow(1.0 - $pct / 100.0, 2.0)) : 100.0;
            $hoursRank  = $totalHours > 0 ? min(100.0, 121700.0 / pow((float) $totalHours, 1.303)) : 100.0;
            $topPct     = max(0.1, round(($compRank + $hoursRank) / 2.0, 1));
        }
        $label  = 'Top ' . number_format($topPct, 1) . '% of all steam users';

        $badgeColor = match (true) {
            $topPct <= 2.0  => imagecolorallocate($img, 255, 195,  40),  // gold
            $topPct <= 5.0  => imagecolorallocate($img, 220,  65,  55),  // red
            $topPct <= 10.0 => imagecolorallocate($img,  40, 210, 185),  // teal
            $topPct <= 25.0 => $c['blue'],
            default         => $c['muted'],
        };

        $nameBox = imagettfbbox(22, 0, self::FONT_BOLD, $playerName);
        $badgeX  = $nameX + abs($nameBox[2] - $nameBox[0]) + 22;

        // Soft glow effect for top-2% players
        if ($topPct <= 2.0) {
            imagealphablending($img, true);
            $glowLayers = [
                [2, imagecolorallocatealpha($img, 255, 140,  0, 112)],
                [1, imagecolorallocatealpha($img, 255, 180, 20,  88)],
            ];
            $offsets = [[-1,-1],[-1,0],[-1,1],[0,-1],[0,1],[1,-1],[1,0],[1,1]];
            foreach ($glowLayers as [$radius, $glowColor]) {
                foreach ($offsets as [$dx, $dy]) {
                    imagettftext($img, 27, 0, $badgeX + $dx * $radius, $nameY + $dy * $radius, $glowColor, self::FONT_NORMAL, $label);
                }
            }
        }

        imagettftext($img, 27, 0, $badgeX, $nameY, $badgeColor, self::FONT_NORMAL, $label);
    }

    private function drawStatsSection(mixed $img, array $c, array $stats): void
    {
        // Large completion percentage
        imagettftext($img, 24, 0, 45, 196, $c['label'], self::FONT_NORMAL, 'COMPLETION RATE');
        imagettftext($img, 80, 0, 45, 298, $c['white'], self::FONT_BOLD,   number_format($stats['pct'], 1) . '%');

        imagefilledrectangle($img, 45, 320, self::SPLIT - 20, 321, $c['sep']);

        // 2×2 stats grid
        $col1    = 45;
        $col2    = 390;
        $col2Max = 320; // max text width in col2 before shrinking font

        imagettftext($img, 30, 0, $col1, 366, $c['label'], self::FONT_NORMAL, 'COMPLETED');
        imagettftext($img, 30, 0, $col2, 366, $c['label'], self::FONT_NORMAL, 'PLAYING');
        imagettftext($img, 52, 0, $col1, 432, $c['green'], self::FONT_BOLD,   (string) $stats['completed']);
        imagettftext($img, 52, 0, $col2, 432, $c['blue'],  self::FONT_BOLD,   (string) $stats['playing']);

        imagettftext($img, 30, 0, $col1, 479, $c['label'], self::FONT_NORMAL, 'BACKLOG');
        imagettftext($img, 30, 0, $col2, 479, $c['label'], self::FONT_NORMAL, 'TOTAL PLAYTIME');
        imagettftext($img, 52, 0, $col1, 545, $c['muted'], self::FONT_BOLD,   (string) $stats['backlog']);

        // Shrink hours text if it would overflow col2
        $hoursStr  = number_format($stats['totalHours']) . 'h';
        $hoursFontSize = 52;
        for ($size = 52; $size >= 16; $size--) {
            $box = imagettfbbox($size, 0, self::FONT_BOLD, $hoursStr);
            if (abs($box[2] - $box[0]) <= $col2Max) {
                $hoursFontSize = $size;
                break;
            }
        }
        imagettftext($img, $hoursFontSize, 0, $col2, 545, $c['muted'], self::FONT_BOLD, $hoursStr);
    }

    private function drawProgressBar(mixed $img, float $pct): void
    {
        $barX = 45;
        $barY = 572;
        $barW = self::SPLIT - 65;
        $barH = 15;

        $barBg = imagecolorallocate($img, 20, 32, 44);
        imagefilledrectangle($img, $barX, $barY, $barX + $barW, $barY + $barH, $barBg);

        $fillW = (int)(($pct / 100) * $barW);
        for ($x = 0; $x < $fillW; $x++) {
            $t     = $x / $barW;
            $r     = (int)(26  + $t * (164 - 26));
            $g     = (int)(159 + $t * (208 - 159));
            $b     = (int)(255 + $t * (7   - 255));
            $color = imagecolorallocate($img, $r, $g, $b);
            imagefilledrectangle($img, $barX + $x, $barY, $barX + $x, $barY + $barH, $color);
        }
    }

    private function drawGameCovers(mixed $img, array $coverAppIds, GuzzleClient $guzzle): void
    {
        // Right panel is 470px wide; covers are proportioned at 460:215 (Steam header ratio)
        $coverH      = 196;
        $coverW      = (int)($coverH * 460 / 215);
        $gap         = (int)((self::H - 10 - 3 * $coverH) / 2);
        $coverStartX = self::SPLIT + (int)((self::W - self::SPLIT - $coverW) / 2);

        foreach ($coverAppIds as $index => $appId) {
            $coverY   = 5 + $index * ($coverH + $gap);
            $coverUrl = "https://cdn.cloudflare.steamstatic.com/steam/apps/{$appId}/header.jpg";

            try {
                $coverData = $guzzle->get($coverUrl)->getBody()->getContents();
                $coverImg  = imagecreatefromstring($coverData);
                if ($coverImg !== false) {
                    imagecopyresampled(
                        $img, $coverImg,
                        $coverStartX, $coverY, 0, 0,
                        $coverW, $coverH,
                        imagesx($coverImg), imagesy($coverImg)
                    );
                    imagedestroy($coverImg);
                }
            } catch (\Throwable) {
                // Non-fatal - cover slot stays dark
            }
        }
    }
}
