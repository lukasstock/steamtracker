# Steam Game Tracker — Technical Documentation

## Table of Contents

1. [Overview](#1-overview)
2. [Stack](#2-stack)
3. [Project Structure](#3-project-structure)
4. [Configuration & Environment Variables](#4-configuration--environment-variables)
5. [Docker Setup](#5-docker-setup)
6. [Database & Migrations](#6-database--migrations)
7. [Authentication Flow](#7-authentication-flow)
8. [Routes & Controllers](#8-routes--controllers)
9. [Services](#9-services)
10. [Entities](#10-entities)
11. [Commands](#11-commands)
12. [Security](#13-security)
13. [Deployment Checklist](#14-deployment-checklist)

---

## 1. Overview

Steam Game Tracker is a personal game library manager built on top of the Steam API. Users sign in with their Steam account via OpenID, after which the app imports their game library and lets them track completion status, write notes, give star ratings, and spotlight favourites. A stats dashboard, friend comparison view, and activity feed round out the feature set.

The app is live at **steamgametracker.com**.

---

## 2. Stack

| Layer         | Technology                                              |
|---------------|---------------------------------------------------------|
| Language      | PHP 8.4                                                 |
| Framework     | Symfony 8                                               |
| ORM           | Doctrine ORM + Doctrine Migrations                      |
| Database      | MySQL 8.0                                               |
| Templates     | Twig                                                    |
| CSS           | Tailwind CSS (Play CDN — no build step)                 |
| HTTP client   | Guzzle 7                                                |
| Image gen     | GD (PHP extension)                                      |
| Web server    | nginx + php-fpm (Docker)                                |
| Cache         | Symfony Cache (filesystem, PSR-6)                       |

---

## 3. Project Structure

```
src/
  Command/          Console commands (cache clear, Discord setup)
  Controller/       HTTP controllers — one concern per file
  Entity/           Doctrine entities
  Enum/             PHP 8.1+ backed enums
  EventListener/    Kernel event listeners (security headers, Discord queue)
  Handler/          Discord slash command handlers
  Repository/       Custom Doctrine query methods
  Service/          Business logic — Steam API, badges, OG image, etc.

templates/
  auth/             Login, invite, setup pages
  feed/             Activity feed
  games/            Main library pages (index, detail, stats, compare, landing)
  base.html.twig    Shared layout

public/
  css/base.css      Global animations and utility classes
  js/games.js       Library page interactivity (modal, filters, drag-and-drop)
  images/           Static assets

migrations/         Doctrine migration files
docker/             nginx config
```

---

## 4. Configuration & Environment Variables

All secrets live in `.env.local` (not committed). The `.env` file contains safe defaults.

| Variable               | Description                                              |
|------------------------|----------------------------------------------------------|
| `APP_ENV`              | `dev` or `prod` — controls cache, cookie Secure flag, etc.|
| `APP_SECRET`           | Symfony CSRF / session secret                            |
| `DATABASE_URL`         | Doctrine DSN (`mysql://user:pass@host:3306/db`)          |
| `STEAM_API_KEY`        | Steam Web API key (get one at steamcommunity.com/dev)    |
| `STEAM_ID`             | Owner's 64-bit Steam ID (used by the Discord bot command)|
| `APP_PASSWORD`         | Password for the admin login page                        |
| `FRIEND_INVITE_TOKEN`  | Secret token in invite links — leave blank to disable    |
| `DISCORD_PUBLIC_KEY`   | Discord application public key                           |
| `DISCORD_APP_ID`       | Discord application ID                                   |
| `DISCORD_BOT_TOKEN`    | Discord bot token                                        |
| `DISCORD_GUILD_ID`     | Discord server ID for slash command registration         |

---

## 5. Docker Setup

Three services are defined in `docker-compose.yml`:

| Service | Image           | Port  | Purpose                     |
|---------|-----------------|-------|-----------------------------|
| mysql   | mysql:8.0       | 3306  | Database                    |
| php     | (Dockerfile)    | 9000  | php-fpm                     |
| nginx   | nginx:alpine    | 8000  | Reverse proxy to php-fpm    |

**Start the stack:**
```bash
docker compose up -d
```

**Run migrations:**
```bash
docker compose exec php bin/console doctrine:migrations:migrate
```

**Run a console command:**
```bash
docker compose exec php bin/console <command>
```

**Tail logs:**
```bash
docker compose logs -f php
```

---

## 6. Database & Migrations

Migrations live in `migrations/` and are managed by Doctrine Migrations.

### Tables

| Table               | Entity            | Purpose                                                  |
|---------------------|-------------------|----------------------------------------------------------|
| `user_profile`      | `UserProfile`     | Maps a Steam ID to a tracker token (UUID cookie)         |
| `game_completion`   | `GameCompletion`  | Per-game status, rating, notes, spotlight, completedAt   |
| `hltb_cache`        | `HltbCache`       | Cached HowLongToBeat main-story hours per appId          |
| `achievement_cache` | `AchievementCache`| Cached achievement counts + top-8 rarest per user+game  |
| `activity_log`      | `ActivityLog`     | Event log of status/rating changes per user+game        |
| `steam_account`     | `SteamAccount`    | Legacy Discord bot table (Steam ID ↔ Discord user ID)   |

---

## 7. Authentication Flow

The app uses **Steam OpenID** for login — no passwords are stored.

```
User clicks "Sign in with Steam"
  → GET /auth/steam
  → Redirect to steamcommunity.com/openid/login
  → Steam redirects back to /auth/steam/callback?openid.*=...
  → SteamApiService::verifyOpenIdCallback() confirms with Steam
  → If new user: UserProfile created, tracker_token UUID generated
  → tracker_token set as HttpOnly cookie (SameSite=Lax, Secure in prod)
  → Redirect to /games/{steamId}
```

**New user import:** After the first login, the user is sent to a loading screen (`/auth/steam/setup/{steamId}`) that calls `POST /auth/steam/import-games/{steamId}`. This auto-classifies played games as Playing or Completed based on HLTB data and sets them up in bulk.

**Authorization model:** There are no server-side sessions. Every request that modifies data reads the `tracker_token` cookie and checks it matches `user_profile.user_token` for the requested Steam ID. This token is a UUIDv4 — effectively a bearer token stored in a cookie.

**Invite links:** `FRIEND_INVITE_TOKEN` is a shared secret embedded in a URL (`/auth/invite/{token}`). Anyone with the link can register a new tracker account without going through the full Steam OpenID flow — useful for adding friends manually.

---

## 8. Routes & Controllers

### `GameLibraryController` — Landing & library list

| Method | Route                                | Name                  | Description                                      |
|--------|--------------------------------------|-----------------------|--------------------------------------------------|
| GET    | `/`                                  | `game_library_root`   | Redirect to library if logged in, else landing   |
| GET    | `/games`                             | `game_library_home`   | Same as above                                    |
| POST   | `/games/view`                        | `game_library_view`   | Look up a Steam ID and redirect to their library |
| GET    | `/games/{steamId}`                   | `game_library`        | Main library page with all games                 |
| POST   | `/games/{steamId}/{appId}/update`    | `game_update`         | AJAX — save status, rating, notes, spotlight     |

### `GameDetailController` — Per-game detail & data endpoints

| Method | Route                                      | Name                  | Description                                      |
|--------|--------------------------------------------|-----------------------|--------------------------------------------------|
| GET    | `/games/{steamId}/{appId}`                 | `game_detail`         | Full detail page for one game                    |
| GET    | `/games/{steamId}/{appId}/image-url`       | `game_image_url`      | Returns Steam header image URL as JSON           |
| GET    | `/games/{steamId}/{appId}/hltb`            | `game_hltb`           | Fetch/cache HLTB hours for a game (AJAX)         |
| GET    | `/games/{steamId}/{appId}/achievements`    | `game_achievements`   | Fetch/cache achievement data for a game (AJAX)   |

### `GameStatsController` — Stats & OG image

| Method | Route                          | Name             | Description                                      |
|--------|--------------------------------|------------------|--------------------------------------------------|
| GET    | `/games/{steamId}/stats`       | `game_stats`     | Stats dashboard (playtime, ratings, badges, etc.)|
| GET    | `/games/{steamId}/og-image`    | `game_og_image`  | Generates a 1200×630 PNG for Open Graph embeds   |

### `CompareController` — Friend comparison

| Method | Route                                               | Name                  | Description                                      |
|--------|-----------------------------------------------------|-----------------------|--------------------------------------------------|
| GET    | `/games/{steamId}/compare`                          | `game_compare_pick`   | Friend picker — lists friends with/without tracker|
| GET    | `/games/{steamId}/compare/{friendSteamId}`          | `game_compare`        | Side-by-side library comparison                  |

### `FeedController` — Activity feed

| Method | Route                          | Name        | Description                  |
|--------|--------------------------------|-------------|------------------------------|
| GET    | `/games/{steamId}/feed`        | `game_feed` | Chronological activity log   |

### `BadgesController`

| Method | Route                          | Name          | Description                          |
|--------|--------------------------------|---------------|--------------------------------------|
| GET    | `/games/{steamId}/badges`      | `game_badges` | Full badge collection page           |

### `SteamAuthController`

| Method    | Route                                    | Name                   | Description                              |
|-----------|------------------------------------------|------------------------|------------------------------------------|
| GET       | `/auth/steam`                            | `steam_auth`           | Redirect to Steam OpenID                 |
| GET       | `/auth/steam/callback`                   | `steam_auth_callback`  | Handle Steam OpenID return               |
| GET       | `/auth/steam/setup/{steamId}`            | `steam_auth_setup`     | New-user loading/import page             |
| POST      | `/auth/steam/import-games/{steamId}`     | `steam_auth_import`    | Auto-import games for new user (AJAX)    |
| GET/POST  | `/auth/invite/{token}`                   | `steam_auth_invite`    | Invite-link registration                 |

### `LoginController`

| Method | Route        | Name            | Description                    |
|--------|--------------|-----------------|--------------------------------|
| GET    | `/login`     | `login`         | Admin login form               |
| POST   | `/login`     | `login`         | Admin login submit             |
| GET    | `/logout`    | `logout`        | Clear session                  |

### `DiscordInteractionController`

| Method | Route                   | Name                  | Description                                    |
|--------|-------------------------|-----------------------|------------------------------------------------|
| POST   | `/discord/interactions` | `discord_interaction` | Receives and verifies Discord slash commands   |

---

## 9. Services

### `SteamApiService`

The central Steam API wrapper. All API calls go through this service. Responses that are safe to cache are stored in the Symfony cache pool.

**Cache TTLs (defined as class constants):**

| Constant              | TTL       | Used for                            |
|-----------------------|-----------|-------------------------------------|
| `TTL_NOW_PLAYING`     | 60s       | Currently playing game              |
| `TTL_OWNED_GAMES`     | 1 hour    | Full game library                   |
| `TTL_FRIENDS`         | 15 min    | Friend list                         |
| `TTL_GLOBAL_ACH_PCT`  | 7 days    | Global achievement percentages      |
| `TTL_GAME_SCHEMA`     | 30 days   | Achievement icons + display names   |
| `TTL_HEADER_IMAGE`    | 30 days   | Steam header image URL              |

**Key methods:**

| Method                           | Description                                              |
|----------------------------------|----------------------------------------------------------|
| `getPlayerSummary($steamId)`     | Player name, avatar, currently playing game              |
| `getPlayersSummaries($ids)`      | Batch player summaries (up to 100 IDs per call)          |
| `getOwnedGames($steamId)`        | Full game library with playtime                          |
| `getCurrentlyPlaying($steamId)`  | Returns `{appid, name}` or null                          |
| `getFriendList($steamId)`        | Array of friend Steam IDs, or null if private            |
| `getPlayerAchievements($id,$app)`| Achievement list with unlock state                       |
| `getGlobalAchievementPercentages($app)` | Global unlock % keyed by apiname                  |
| `getGameSchema($app)`            | Achievement icons and display names                      |
| `getHeaderImageUrl($app)`        | Steam header image URL (460×215)                         |
| `verifyOpenIdCallback($params)`  | Verify Steam OpenID response, return Steam ID or null    |
| `resolveSteamInput($input)`      | Accept URL, vanity name, or 17-digit ID                  |

---

### `HltbService`

Queries HowLongToBeat for the main-story completion time of a game. HLTB has an unofficial API that requires a two-step handshake (init token + honeypot fields) before posting a search. Returns whole hours or null.

Results are cached in the `hltb_cache` database table (not the Symfony cache) with a 30-day TTL, keyed by Steam `appId`.

---

### `OgImageService`

Generates the 1200×630 Open Graph preview image returned by `/games/{steamId}/og-image`. Uses PHP's GD extension to draw directly onto a canvas — no external dependencies.

**Layout:** Left panel (730px) shows the GAME TRACKR logo, player avatar, player name, a "Top X% of all Steam users" badge, completion rate, and a 2×2 stats grid. Right panel (470px) shows three game cover images stacked vertically (spotlight games first, then most-played).

The "Top %" badge uses a composite score: 50% completion rank + 50% playtime rank, calibrated to Steam's distribution. Badge colour: gold ≤ 2%, red ≤ 5%, teal ≤ 10%, blue ≤ 25%, gray otherwise.

Fonts used: DejaVu Sans (ships in the Docker image at `/usr/share/fonts/truetype/dejavu/`).

---

### `BadgeService`

Computes and ranks achievement badges based on a user's library state. Takes an array of `GameCompletion` entities, total hours, and game count as input — no database calls.

**Badge categories:** Completion, Playtime, Rating, Dropped, Library, Special

**Tiers:** bronze → silver → gold → platinum → legendary

Two helper methods: `earnedBadges()` (all earned, sorted by tier) and `topEarned($n)` (top N for quick display).

---

### `ActivityLogService`

Persists status and rating change events for the activity feed. Applies a 1-hour deduplication window per (userToken, appId, eventType) to prevent double-logging on rapid saves. Called from `GameLibraryController::update()`.

---

### `UserTokenService`

Manages the `tracker_token` UUID cookie. Generates UUIDv4 tokens, reads them from requests with UUID format validation, and sets/clears the cookie.

The `Secure` flag is set automatically when `APP_ENV=prod`.

---

### `DiscordApiService` / `InteractionQueue` / `SteamCommandHandler`

These three handle the legacy Discord slash-command integration. `DiscordInteractionController` receives the webhook, verifies the Ed25519 signature, enqueues the payload via `InteractionQueue`, and returns an immediate `DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE`. After the response is sent, `ProcessInteractionsListener` fires on `kernel.terminate`, dequeues the payload, and sends the follow-up via `SteamCommandHandler`.

---

## 10. Entities

### `UserProfile`

| Column       | Type    | Description                                        |
|--------------|---------|----------------------------------------------------|
| id           | int     | Auto-increment PK                                  |
| steam_id     | string  | 17-digit Steam ID (unique)                         |
| user_token   | string  | UUIDv4, stored in cookie, used as auth token       |
| created_at   | datetime| Account creation timestamp                         |

---

### `GameCompletion`

Tracks a user's relationship with a single game.

| Column        | Type     | Description                                        |
|---------------|----------|----------------------------------------------------|
| id            | int      | Auto-increment PK                                  |
| app_id        | int      | Steam appId                                        |
| user_token    | string   | Foreign key to UserProfile.user_token              |
| status        | string   | Enum: `unplayed`, `playing`, `completed`, `dropped`, `on_hold` |
| rating        | int/null | 1–5 stars, nullable                                |
| notes         | text/null| Free-text notes (max 2000 chars)                   |
| is_spotlight  | bool     | Pinned to the top of the library / used for OG image covers |
| completed_at  | datetime | Set when status → completed, cleared otherwise     |

---

### `HltbCache`

| Column      | Type      | Description                              |
|-------------|-----------|------------------------------------------|
| app_id      | int       | Steam appId (PK)                         |
| hours_main  | float/null| Main story hours from HLTB, null if not found |
| fetched_at  | datetime  | Last fetch timestamp (TTL: 30 days)      |

---

### `AchievementCache`

Cached per (steamId, appId) pair.

| Column            | Type       | Description                                        |
|-------------------|------------|----------------------------------------------------|
| id                | int        | Auto-increment PK                                  |
| steam_id          | string     | Player's Steam ID                                  |
| app_id            | int        | Steam appId                                        |
| unlocked_count    | int        | Number of unlocked achievements                    |
| total_count       | int        | Total achievements (0 = game has none)             |
| rare_achievements | json/null  | Array of up to 8 rarest unlocked achievements      |
| fetched_at        | datetime   | Last fetch (TTL: 24 hours)                         |

Each entry in `rare_achievements`:
```json
{
  "displayName": "...",
  "description": "...",
  "icon": "https://...",
  "globalPercent": 1.3
}
```

---

### `ActivityLog`

| Column     | Type      | Description                                         |
|------------|-----------|-----------------------------------------------------|
| id         | int       | Auto-increment PK                                   |
| user_token | string    | Links to UserProfile                                |
| steam_id   | string    | For display purposes                                |
| type       | string    | `completed`, `dropped`, `rating`                    |
| app_id     | int       | Steam appId                                         |
| app_name   | string    | Game name at time of logging                        |
| metadata   | json/null | Snapshot of rating/notes at time of event           |
| created_at | datetime  | Event timestamp                                     |

---

## 11. Commands

| Command                           | Description                                               |
|-----------------------------------|-----------------------------------------------------------|
| `app:cache:clear-achievements`    | Truncates the `achievement_cache` table — forces a full re-fetch on next page load |
| `app:steam:find-game`             | Discord bot helper — looks up the owner's Steam games     |
| `discord:register-commands`       | Registers slash commands with the Discord API             |
| `discord:send-message`            | Sends a one-off message to a Discord channel              |

---

## 12. Security

### Authentication & Authorization
- All write operations (`/update`, `/import-games`) verify the `tracker_token` cookie matches `user_profile.user_token` for the requested Steam ID.
- The cookie is `HttpOnly`, `SameSite=Lax`, and `Secure` in production. `SameSite=Lax` blocks cross-site POST requests from including the cookie, providing CSRF protection for all state-modifying endpoints.
- UUID format validation is applied before using any token value.

### HTTP Headers
`SecurityHeadersListener` adds the following to every main response:

| Header                  | Value                          | Purpose                                  |
|-------------------------|--------------------------------|------------------------------------------|
| `X-Frame-Options`       | `SAMEORIGIN`                   | Prevents clickjacking via iframes        |
| `X-Content-Type-Options`| `nosniff`                      | Blocks MIME-sniffing of responses        |
| `Referrer-Policy`       | `strict-origin-when-cross-origin` | Limits referrer leakage on navigation |
| `Permissions-Policy`    | `camera=(), microphone=(), geolocation=()` | Disables unused browser APIs  |

### Input Validation
- `status` — validated against the `GameStatus` enum via `tryFrom()`
- `rating` — validated to integer range 1–5
- `notes` — truncated to 2000 characters
- `app_name` — truncated to 200 characters
- Route parameters `steamId` and `appId` are constrained by regex requirements (`\d{17}` and `\d+`)

### Output Escaping
Twig auto-escapes all template variables by default, preventing XSS in rendered HTML.

### Discord Webhook
`DiscordInteractionController` verifies the Ed25519 signature on every incoming interaction using the application's public key before processing any payload.

---

## 13. Deployment Checklist

- [ ] Set `APP_ENV=prod` in `.env.local`
- [ ] Set a strong random `APP_SECRET`
- [ ] Set `DATABASE_URL` to the production database
- [ ] Confirm HTTPS is terminating at the load balancer / reverse proxy
- [ ] Run `composer install --no-dev --optimize-autoloader`
- [ ] Run `bin/console doctrine:migrations:migrate --no-interaction`
- [ ] Run `bin/console cache:clear`
- [ ] Verify `tracker_token` cookie shows `Secure` flag in browser dev tools
- [ ] Verify response headers include `X-Frame-Options`, `X-Content-Type-Options`
- [ ] Test Steam OpenID login end-to-end (callback URL must match the `realm` sent to Steam)
- [ ] (Optional) Set `FRIEND_INVITE_TOKEN` to a secret value to enable invite links
