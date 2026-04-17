# GameTrackr - Go-Live Roadmap

## Onboarding Friction Solution

The core insight: Steam playtime data is available at login - use it to auto-classify the library so new users don't have to manually set every game.

**Auto-classification on first login:**
- 0 hours → `Unplayed` (already the default)
- >0 hours → auto-set to `Playing`
- 100% Steam achievements → auto-set to `Completed`

**"Quick Sweep" mode:**
A dedicated onboarding flow showing only games with >0 playtime. Big status buttons, keyboard-driven (1–5 for status, arrow to skip). Goal: triage 50 games in 2 minutes.

---

## Phase 1 - Onboarding & Core Polish
*Make the first 5 minutes good*

- [ ] Auto-classify library on first login (playtime + Steam achievements)
- [ ] Quick Sweep mode for initial setup
- [ ] Mobile responsiveness pass
- [ ] Proper landing page with feature showcase
- [ ] Public profile pages polished (already partially there via `/{steamId}`)

---

## Phase 2 - Features People Actually Want
*Give them a reason to come back and tell friends*

- [ ] **Achievement tracking** - show % completion per game, rarest achievements, showcase/trophy case on profile
- [ ] **Friends / compare** - link Steam friends, side-by-side library comparison ("you both own X, they finished it, you haven't")
- [ ] **Stats page** - total hours, completion rate over time, genres, most played year
- [ ] **Activity feed** - "recently completed", timeline of gaming history
- [ ] **Game discovery** - games in your library similar to ones you loved
- [ ] **Wishlist tracking** - pull from Steam wishlist, track anticipated games
- [ ] **Custom lists** - beyond status: "couch co-op picks", "to replay", etc.

---

## Phase 3 - Monetization
*Gate the right things*

- [ ] Free tier: basic library, status, public profile, ads
- [ ] Premium (~€2–4/month or one-time): stats page, compare, custom lists, activity feed, no ads
- [ ] Ads on free tier (display only, non-intrusive)
- [ ] Impressum page
- [ ] Gewerbe registration

---

## Phase 4 - Infrastructure
*Production-ready*

- [ ] VPS setup (Hetzner)
- [ ] Domain + SSL
- [ ] Production `.env`, logging, error tracking (Sentry free tier)
- [ ] Image/CDN caching for Steam artwork
- [ ] DB backups
- [ ] CSRF on POST endpoints
- [ ] Rate limiting on Steam/HLTB API calls
- [ ] `withSecure(true)` on cookie for production

---

## Phase 5 - Launch & Marketing

- [ ] Reddit: r/patientgamers, r/SteamDeals, r/gametracking
- [ ] Product Hunt launch
- [ ] Post in Steam-related Discord servers
- [ ] "Compare your library with a friend" as the viral sharing hook
