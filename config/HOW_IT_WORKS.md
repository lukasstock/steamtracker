# How It Works

A plain-English walkthrough of the codebase - written to be read alongside the code,
not instead of it. Start here, then open the files mentioned.

---

## The Big Picture

This is a single Symfony app that does two separate things:

1. **A Discord bot** - Discord sends an HTTP POST to our server when someone types a slash command. We respond with data.
2. **A game library website** - A browser visits `/games` and sees your Steam library with completion tracking.

Both share the same Steam API service and the same database.

---

## How a Browser Request Works (Game Library)

When you visit `http://localhost:8000/games`:

```
Browser → nginx (port 8000, Docker) → php-fpm (Docker) → Symfony kernel
       → Router matches /games → GameLibraryController::index()
       → Renders templates/games/index.html.twig → HTML back to browser
```

**nginx** is just a reverse proxy - it receives the HTTP request and hands it to **php-fpm**,
which is the PHP process running inside Docker. Symfony then takes over from there.

The routes themselves are defined via `#[Route]` attributes directly on the controller methods
(see `src/Controller/GameLibraryController.php`). The `config/routes.yaml` just tells Symfony
to look for those attributes - you rarely need to touch it.

---

## The Service Container (config/services.yaml)

Symfony has a "dependency injection container" - a system that automatically creates and wires
together your PHP classes so you don't have to do `new SteamApiService(...)` yourself.

`config/services.yaml` does two things:

**1. Defines parameters** (top section) - reads values from `.env` and gives them names:
```yaml
steam_api_key: '%env(STEAM_API_KEY)%'
```
This means `STEAM_API_KEY` from `.env` becomes a parameter called `steam_api_key`.

**2. Wires services** (bottom section) - tells Symfony which parameter to inject where:
```yaml
App\Service\SteamApiService:
    arguments:
        $apiKey: '%steam_api_key%'
```
This means: when creating `SteamApiService`, inject the `steam_api_key` parameter as the `$apiKey`
constructor argument.

Everything else (`EntityManager`, `Request`, `Logger`, etc.) is auto-wired - Symfony figures out
what each class needs by reading the constructor type hints.

---

## The Game Library Flow (Reading Data)

`GET /games` → `GameLibraryController::index()` (`src/Controller/GameLibraryController.php`):

1. **Fetch Steam games** - calls `SteamApiService::getOwnedGames()`, which hits the Steam API
   and returns your full game list. This is cached for 1 hour so the page doesn't time out.

2. **Fetch DB records** - calls `GameCompletionRepository::findAllIndexedByAppId()`, which loads
   all rows from the `game_completion` table and returns them as an array keyed by `appId`.
   This makes step 3 O(1) per game instead of doing a DB query per game.

3. **Merge** - loops over every Steam game, looks up its `appId` in the DB map, and builds
   a combined array with both Steam data (name, playtime) and our data (status, rating, notes).

4. **Render** - passes the merged array to Twig, which loops over it and outputs the HTML cards.

---

## The Game Library Flow (Saving Data)

`POST /games/{appId}/update` → `GameLibraryController::update()`:

1. **Auth check** - reads `session['authenticated']`. If false, returns 401 immediately.
   `$session->save()` is called right after to release the PHP session file lock (a performance
   optimisation - PHP locks the session file for the whole request by default).

2. **Find or create** - looks for an existing `GameCompletion` entity by `appId`. If none exists,
   creates a new one. This is how a game goes from "no DB record" to "tracked".

3. **Update fields** - sets status, rating, notes, spotlight from the JSON request body.
   If status is set to Completed and there's no `completedAt` yet, it stamps the current date.
   If status is changed away from Completed, `completedAt` is cleared.

4. **Persist** - `$entityManager->persist($completion)` tells Doctrine to track this entity.
   `$entityManager->flush()` actually runs the SQL (INSERT or UPDATE).

5. **Return JSON** - sends back the saved values so the frontend can confirm what was stored.

**Why the modal feels instant:** The JavaScript doesn't wait for this response before closing.
It updates the DOM immediately with what it *expects* the response to be, then fires the POST
in the background. When the response arrives, it applies any corrections (mainly `completedAt`).
See `saveModal()` and `applyCardUpdate()` in `public/js/games.js`.

---

## The Steam API & Caching

`src/Service/SteamApiService.php` wraps all Steam API calls.

The `$cache` injected into the service is Symfony's cache (configured in
`config/packages/cache.yaml`). It works like this:

```php
return $this->cache->get('some_unique_key', function (ItemInterface $item) {
    $item->expiresAfter(3600); // 1 hour
    // This closure only runs on a cache miss.
    // The result is stored and returned on subsequent calls.
    return $this->callSteamApi(...);
});
```

Cache TTLs used:
- Owned games: **1 hour** (large call, doesn't change often)
- Now Playing: **60 seconds** (needs to feel live)
- Header image URLs: **30 days** (never changes once set)

The "header image URL" cache exists because newer Steam games (e.g. Death Stranding 2) don't
have images on the standard Cloudflare CDN path. We have to call `appdetails` to get the real
hashed Akamai URL. Since that's slow, we cache it aggressively.

---

## The Database (Doctrine ORM)

Doctrine lets you work with database rows as PHP objects (called "entities").

**Entity** (`src/Entity/GameCompletion.php`) - a PHP class where each property maps to a DB column.
The `#[ORM\Column]` attributes tell Doctrine the column type.

**Repository** (`src/Repository/GameCompletionRepository.php`) - where you put DB query methods.
`findAllIndexedByAppId()` is a custom method that returns all rows keyed by `appId` - useful for
fast lookups when merging with the Steam game list.

**Migrations** (`migrations/`) - every time you change an entity, you run:
```
php bin/console doctrine:migrations:diff   # generates a migration file
php bin/console doctrine:migrations:migrate # runs it against the DB
```
The migration files are SQL wrapped in PHP - they record the exact schema changes so the DB
stays in sync with the entities.

---

## The Discord Webhook Flow

When someone types `/steam profile` in Discord:

```
Discord → POST /discord/interactions → DiscordInteractionController
        → verifies Ed25519 signature (security - proves it's really from Discord)
        → routes to SteamCommandHandler
        → calls SteamApiService
        → returns JSON response (type 4 = immediate reply)
```

The signature check happens first - if it fails, we return 401 and stop. This prevents anyone
from faking Discord requests to our endpoint.

Discord requires a response within 3 seconds. We use a synchronous "type 4" response
(respond immediately in the same request). A "type 5" deferred approach (respond later via
a PATCH request) was tried but consistently returned 404 - so we keep it synchronous.

---

## The Frontend (JS + CSS)

There's no build step - everything is plain files served directly by nginx.

**`public/js/games.js`** - all interactivity:
- `applyFilters()` - hides/shows cards by checking `data-status` and the search input
- `sortGames()` - re-orders the DOM cards inside `#game-grid` (no page reload)
- `updateStats()` - recalculates the stat numbers by counting visible `data-status` values
- `openModal() / saveModal() / closeModal()` - edit modal lifecycle
- `updateSpotlightSection()` - rebuilds the spotlight cards from the current game card data

**`public/css/games.css`** - visual effects that Tailwind can't do:
- `box-shadow` status rings and hover glows (colored per status)
- `backdrop-filter: blur()` on the modal backdrop
- Spotlight card shadows

**Data flow in the frontend:** All game data (status, rating, notes, etc.) lives in `data-*`
attributes on each `.game-card` div. JS reads from and writes to those attributes, keeping
the DOM as the single source of truth on the client side. The server is only involved on save.

---

## Environment Variables → Config → Code

```
.env
  STEAM_API_KEY=xxx
       ↓
config/services.yaml
  steam_api_key: '%env(STEAM_API_KEY)%'
       ↓
  App\Service\SteamApiService:
      arguments:
          $apiKey: '%steam_api_key%'
       ↓
src/Service/SteamApiService.php
  public function __construct(private readonly string $apiKey, ...)
```

The value travels from the `.env` file → services.yaml parameter → constructor injection.
You never call `$_ENV['STEAM_API_KEY']` directly in the code - Symfony handles it.
