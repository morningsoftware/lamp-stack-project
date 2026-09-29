# AGENTS.md

Guidance for AI coding agents (and humans) working in this repository. Read this before making changes.

## 1. Overview

**collab.dev** is a developer directory, contacts manager, and direct-messaging app. Users create accounts, link a GitHub identity, browse/follow other developers, keep a personal address book, message each other, and join organizations that post job roles.

It is a **LAMP stack** project:

- **L**inux (or macOS for local dev) / **A**pache / **M**ySQL / **P**HP, plus a **vanilla JavaScript single-page app** (no framework, no build step, no bundler, no `.htaccess` rewrite rules).
- The front end is a hash-routed SPA; the back end is a small hand-rolled PHP front controller with a JSON API.
- The database schema lives in `resetdb.sql` (fresh install) and `migrate.sql` (upgrade an existing database). These two must stay in lockstep (see §7).

The product name/brand is **collab.dev** (always lowercase in code and copy).

## 2. Architecture

### Request lifecycle

1. The browser loads the static `index.html`, which pulls `assets/js/api.js` and `assets/js/app.js`.
2. `app.js` runs a hash router (`#/browse`, `#/contacts`, `#/messages/4`, `#/settings?tab=github`, …) via `parseHash()` and `render()`. There is no server-side routing of the SPA.
3. API calls go to `api/index.php`, which is the single **front controller**. It reads the request path from `PATH_INFO` (via `pathSegments()`), then `require`s the matching handler in `api/handlers/`.
4. Handlers read shared helpers from `api/config/` and write JSON responses with `respond()`.

Clean URLs (`/api/profiles/3` without `index.php`) are intentionally **not** implemented — this project avoids `.htaccess`/`mod_rewrite` and relies on Apache's native `PATH_INFO` support, which keeps deployment portable.

### The API is under `api/index.php`

Every endpoint is of the form:

```
/api/index.php/<resource>[/<id>[/<subresource>]]
```

`index.php` switches on the first path segment:

| Resource | Handler |
|---|---|
| `auth` | `handlers/auth.php` |
| `profiles` | `handlers/profiles.php` |
| `compare` | `handlers/compare.php` |
| `contacts` | `handlers/contacts.php` |
| `admin` | `handlers/admin.php` |
| `skills` | `handlers/skills.php` |
| `github` | `handlers/github.php` |
| `oauth` | `handlers/oauth.php` |
| `conversations` / `messages` | `handlers/conversations.php` |
| `organizations` | `handlers/organizations.php` |
| `roles` | `handlers/roles.php` |
| `ping` | inline health check in `index.php` |

### Shared configuration (`api/config/`)

- `db.php` — `getDB()` returns a singleton PDO connection from `.env`.
- `helpers.php` — the API toolkit: `respond()`, `requireAuth()`, `requireAdmin()`, `bearerToken()`, `hashToken()`, `issueToken()`, `setCORSHeaders()`, `pathSegments()`, `isMaintenanceMode()`, `appBaseUrl()`, and input helpers (`clean()`, `requireEmail()`, `requireFields()`).
- `github.php` — GitHub REST helpers (`githubFetch()`, `githubRequest()`, `fetchCommitActivity()`, `syncGithubForUser()`).
- `oauth.php` — GitHub OAuth helpers (`githubOAuthExchangeCode()`, `githubOAuthFetchUser()`, `findOrCreateOAuthUser()`, `linkGithubAccount()`).
- `oauth-state.php` — OAuth CSRF/state handling (`oauthSession()`, `oauthRemember()`, `oauthConsume()`); uses a short-lived server-side PHP session, not cookies.

## 3. Directory map

```
.
├── index.html              # SPA shell + top nav (served statically)
├── assets/
│   ├── js/api.js           # API client; exposes window.API
│   ├── js/app.js           # the SPA: router, views, state, UI helpers
│   ├── css/app.css         # all styles (CSS variables for theming)
│   └── icons.svg           # inline SVG sprite (referenced via <use>)
├── api/
│   ├── index.php           # front controller / router
│   ├── config/             # db, helpers, github, oauth, oauth-state
│   ├── handlers/           # one file per API resource
│   └── cron/               # refresh_github.php + refresh_github.sh
├── bruno/                  # Bruno API tests (see §9)
├── docs/                   # OpenAPI spec + presentation notes (see §9)
│   ├── openapi.yaml        # SwaggerHub demo spec (subset of endpoints)
│   └── presentation-readiness.md  # demo/deployment checklist (time-boxed)
├── tests/                  # standalone PHP test scripts (see §9)
├── .github/workflows/      # deploy.yml (deploys on push to main)
├── resetdb.sql             # full schema + seed data (fresh install)
├── migrate.sql             # idempotent migration (upgrade existing DB)
├── .env / .env.example     # config (real .env is gitignored)
└── README.md               # quick start (macOS + Linux)
```

`cgi-bin/` and `.DS_Store` are ignored cruft, not part of the app.

## 4. Design language

The UI has a deliberate, minimal, hard-edged aesthetic. Preserve it.

### Casing

- **Buttons, labels, headings, section titles, toasts, nav links: lowercase** (or sentence-case for short phrases). Examples: `new message`, `save profile`, `manage users`, `weekly activity`.
- **Proper nouns keep their capitalization**: `GitHub`, and acronyms like `ID` (`contact ID`).
- **Full sentences** (form hints, error messages, empty-state descriptions) use normal sentence case with punctuation: `The contact list could not be loaded.`

### Typography

Two font families, defined in `:root`:

- `--mono` — for UI chrome: buttons, inputs, labels, nav, chips, handles/usernames, stat numbers, timestamps, badges.
- `--sans` — for readable prose: bios, descriptions, message bodies, table cell content (usually just inherited, no explicit declaration).

Rules:

- Font weights are limited to **400, 500, 600, 700**. Never use `550`/`650` or other non-standard weights.
- Body/UI sizes use the **11 / 12 / 13 / 14px** scale (plus larger heading sizes like 24px page titles). Never use fractional sizes like `10.5px` or `13.5px`.
- Use `font-family: var(--mono)` (or `var(--sans)`) — never a raw font stack.

### Theming

- All colors come from CSS custom properties in `:root` (light) with overrides in `[data-theme="dark"]` and a `@media (prefers-color-scheme: dark)` fallback.
- Always reference `var(--surface)`, `var(--muted)`, `var(--accent)`, `var(--line)`, `var(--danger)`, `var(--ring)`, `var(--radius)`, etc. **Never hardcode a color or radius.**

### Layout primitives

Reuse these classes rather than inventing new ones:

- `.container` — max-width 900px, centered (most pages are wrapped in this).
- `.spread` — a flex row that puts a `.page-title` on the left and an optional action button on the right (standard page header).
- `.section` — vertical rhythm between blocks (36px top margin).
- `.row`, `.stack`, `.card-grid`, `.panel`, `.chips`, `.chip`, `.badge`, `.empty`, `.stat-cell`, `.field`, `.form-grid`.
- Buttons: `.btn` + a variant — `.btn-primary`, `.btn-ghost`, `.btn-danger`, `.btn-sm`, `.btn-icon`.

Every authenticated page follows the same shape: `.container` → `.spread` header (`page-title` + optional action) → content sections.

### JavaScript conventions

- No framework. Everything lives in one IIFE in `assets/js/app.js`.
- `$` / `$$` are `querySelector` / `querySelectorAll` helpers.
- `state` is a module-level object (`user`, `profiles`, `convos`, `facets`, `maintenance`).
- Views are `viewX(root, ...)` functions called by `render()`; they write `root.innerHTML`.
- Feedback via `toast(msg, type)`; modals via `formDialog(...)` / `messageDialog(...)`.
- All network access goes through `window.API` (defined in `api.js`). Don't call `fetch` directly in views.

### CSS class reference

Prefer these existing classes over ad-hoc inline styles. Don't introduce a new utility class when one of these already covers it.

**Layout**
- `.container` — wrap each page's content (900px, centered).
- `.spread` — the standard page header: `.page-title` on the left, action button(s) on the right.
- `.section` — vertical rhythm between page blocks (36px top margin).
- `.stack` — vertical list with equal gaps.
- `.row` — horizontal flex row of buttons/controls (wraps).
- `.panel` — bordered, rounded surface block.
- `.card-grid` — responsive grid of cards (auto-fill, min 300px).

**Text**
- `.page-title` — the page `<h1>` (24px mono).
- `.section-title` — small uppercase section label.
- `.sub` — muted subtitle under a title.
- `.mono` / `.muted` / `.faint` — font-family / muted / faint color utilities.
- `.ellipsis` — single-line truncation.

**Buttons** — always `.btn` plus exactly one variant
- `.btn-primary` — the primary action.
- `.btn-ghost` — a neutral/secondary action.
- `.btn-danger` — a destructive action.
- `.btn-sm` — compact size; `.btn-icon` — square, icon-only.

**Forms**
- `.field` — label + control group (use the `field()` / `textArea()` JS helpers).
- `.input`, `.select`, `.textarea` — the standard controls.
- `.form-grid` — stacked fields with 14px gaps; `.form-columns` — two-column field row.
- `.form-hint` / `.form-error` — help text / inline error.
- `.dialog-actions` — modal button row (right-aligned; a lone primary button goes full-width).

**Chips & badges**
- `.chip` — a skill/tag pill; add `.static` (non-interactive), `.toggle`/`.on` (filter toggle), or `.dot` (color swatch).
- `.badge` — small uppercase status label; `.accent` for a highlighted one.

**Feedback / states**
- `.empty` — empty-state block (icon + title + text); `.empty-inline` for a one-liner.
- `.skeleton` — loading placeholder.
- `.toast` — toast message (with `.error` / `.success`).

**Utilities**
- `.hidden`, `.center`, `.flex1`, `.mt`, `.wrap`, `.sr-only`.

## 5. Configuration

Configuration is read from `.env` (gitignored); `.env.example` documents every key and is the source of truth. The PHP `loadEnv()` re-reads `.env` per request, so no restart is needed after edits (Apache restart is only needed for PHP ini changes).

Keys:

| Key | Purpose |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_PORT`, `DB_CHARSET` | MySQL connection |
| `SESSION_TTL_DAYS` | bearer-token lifetime |
| `MAINTENANCE_MODE` | `true`/`1`/`yes`/`on` serves a 503 (see §11) |
| `CORS_ALLOWED_ORIGIN` | front-end origin (defaults to `*`) |
| `APP_BASE_URL` | public front-end URL (used for redirects) |
| `GITHUB_REFRESH_HOURS` | staleness window for cached GitHub data |
| `GITHUB_TOKEN` | optional PAT to raise GitHub API rate limits |
| `GITHUB_OAUTH_CLIENT_ID`, `GITHUB_OAUTH_CLIENT_SECRET`, `GITHUB_OAUTH_REDIRECT_URI` | GitHub OAuth app |

## 6. Auth model

Two complementary paths:

1. **Email + password** — `auth/register` and `auth/login`. Passwords are bcrypt-hashed.
2. **GitHub OAuth** — two distinct flows sharing the same endpoints:
   - **Sign in** (`GET /oauth/github`): creates/finds an account by GitHub id and returns a session token.
   - **Connect** (`POST /oauth/github/connect`): links GitHub to an already-logged-in account to *verify ownership*.

Key facts:

- Sessions are opaque bearer tokens. Only the **SHA-256 hash** is stored (`sessions.token_hash`); the plaintext token is returned once to the client.
- The verified GitHub identity is `github_profiles.github_id` (GitHub's numeric id), not the renameable username.
- GitHub profile data (repos, stars, commits) is only shown when `github_id IS NOT NULL` — an account that hasn't completed OAuth linking shows no GitHub section.
- OAuth CSRF protection uses a **separate short-lived server-side PHP session** (`oauth-state.php`), distinct from the bearer-token session table. `oauthRemember()` stashes the `state` (plus the linking user and a hash of their bearer token); `oauthConsume()` is single-use, `hash_equals`-checked, and 10-minute expiring. Do not fall back to cookies for this.

## 7. Invariants (must uphold)

These are hard rules. Do not break them.

1. **`resetdb.sql` and `migrate.sql` must stay in parity.** Any schema change (new table, column, index, constraint) is added to **both**: `resetdb.sql` for fresh installs, and `migrate.sql` for existing databases. If they drift, fresh installs and upgraded installs diverge.
2. **`migrate.sql` must be idempotent.** Every additive step is gated on an `information_schema` check (column/constraint existence), so it can be re-run safely. Do not add a bare `ALTER TABLE ... ADD COLUMN` without a guard.
3. **GitHub data is gated on `github_id IS NOT NULL`.** Unverified accounts must not surface GitHub repos/stars/commits (ownership verification via OAuth). Keep the `github_id` condition in the relevant SQL joins.
4. **No secrets in the repo.** `.env` is gitignored; never commit `GITHUB_OAUTH_CLIENT_SECRET`, `GITHUB_TOKEN`, or the DB password. `client_secret` is used server-side only (in `api/config/oauth.php`), never shipped to the front end.
5. **Tokens are hashed at rest.** Never store or log a plaintext bearer token.
6. **Schema changes require MySQL `root`.** The application user `ContactManagerUser` only has `SELECT/INSERT/UPDATE/DELETE`, so it cannot run DDL. Run `migrate.sql` as root.

## 8. Background jobs

- `api/cron/refresh_github.php` — refreshes stale GitHub profiles (CLI only).
- `api/cron/refresh_github.sh` — wrapper with two modes:
  - `install 20` — registers a cron entry (defaults to every 6 hours) that calls the script.
  - `run 20` — performs a single refresh pass (what cron executes).

Staleness is governed by `GITHUB_REFRESH_HOURS` (default 24). GitHub commit activity is fetched only for the top ~10 repos per user to stay within rate limits.

## 9. Testing

- **Bruno** (in `bruno/`) holds API tests. Request URLs reference `/api/index.php/...`; environments live in `bruno/ContactsApp/environments/` (Local, Prod).
- **Local sanity checks before committing:**
  - `php -l <file>` for every changed PHP file.
  - `node --check <file>` for changed JS files.
- **Standalone PHP test scripts** live in `tests/` (e.g. `tests/oauth-state.php`). They are run directly (`php tests/<file>.php`) and print `PASS`/throw on failure; they stub any `helpers.php` functions they need. Keep them self-contained and side-effect-free.

### SwaggerHub / OpenAPI (demo only)

`docs/openapi.yaml` is an OpenAPI 3.0 spec describing a **subset** of endpoints (`/ping`, `/auth/login`, `/contacts`). It is imported into SwaggerHub for the live API *demonstration*, not for testing — Bruno covers the full endpoint surface for testing. SwaggerHub and Bruno are not interchangeable: if the course rubric requires a live "try it out" demo, the OpenAPI spec must be imported into SwaggerHub; the repo file alone does not satisfy that. Keep the spec's server URL and demo accounts in sync with the deployed environment.

## 10. Contribution guidelines

- **Keep `AGENTS.md` up to date.** Any change that alters architecture, adds/renames/removes a file or directory, introduces a new convention or helper, or modifies the schema or a workflow **must** update this file in the same change. If `AGENTS.md` drifts from the codebase, it misleads both humans and agents.
- **Branch workflow**: feature branches off `main`; open a PR to merge. **Pushing to `main` triggers an automatic deploy** to the production droplet (`.github/workflows/deploy.yml`), so never commit directly to `main`.
- **Commits**: small, focused, one logical change each. Use a short imperative subject in lowercase (matching existing history, e.g. `add maintenance mode`, `add member button alignment`), with an optional blank-line-separated body explaining *why* when non-obvious. When adding a feature, begin the commit with `feat:`, when commiting a fix, start with `fix:`. Always make commits for your changes when in build mode. 
- **Comments**: the codebase is intentionally sparse on *inline* comments. Don't add explanatory comments to code; an inline comment is only warranted for a non-obvious invariant or a "why" that isn't clear from the code. Functions are documented per the **Function doc comments** scheme below.
- **Style**: follow the design language in §4 and the casing rules; don't introduce new utility classes when an existing primitive fits.

### Function doc comments

Give every function in the API surface — `api/handlers/`, `api/config/`, and the view/helper functions in `assets/js/` — a short doc comment describing what it does, its parameters, and its return value. The description is one or two sentences; the tags carry the details.

**PHP** (PHPDoc):

```
/**
 * Lists the current user's conversations with last message and unread count.
 *
 * @param PDO $db
 * @param int $userid
 * @return array
 */
```

- Description: imperative or third-person, sentence case, ending with a period.
- `@param Type $name` — one per parameter, in that order, with a brief description.
- `@return Type` — the type and what it represents; omit for `void` functions.
- Types: native PHP types (`int`, `string`, `bool`, `array`, `mixed`), `PDO`, or a class name.

**JavaScript** (JSDoc):

```
/**
 * Renders the developer cards for the browse grid.
 *
 * @param {Array<Object>} developers The profiles to render.
 * @returns {string} The card HTML.
 */
```

- Description: same rules as PHP.
- `@param {Type} name` — type in braces (`{string}`, `{number}`, `{HTMLElement}`, `{Array<Object>}`).
- `@returns {Type}` — the type and what it represents; omit for `void`.
- Trivial one-liners (getters, passthroughs) may skip the doc comment.

## 11. Gotchas

- **No `.htaccess`** — routing is `PATH_INFO` (API) + `#/` hash (SPA). Don't add rewrite rules; keep the app deployable on a stock Apache/PHP host.
- **MySQL `CHECK` constraints can't reference FK columns with a referential action.** This is why `chk_messages_one_share` (a CHECK on `roleid`/`organizationid`) was removed — the "share a role *or* an organization, not both" rule is enforced in the API (`messageShareTarget()` in `conversations.php`) instead.
- **Messaging is client-side polling (5s), not real-time/websockets.** A self-scheduling timer re-fetches only while the messages view is active and the tab is visible; the conversation list is fetched conditionally (`ETag`/`If-None-Match` → 304) and the open thread fetches only new messages (`?since=<id>`). `cache: 'no-store'` is set on API fetches to avoid stale responses.
- **Maintenance mode**: dropping a `MAINTENANCE_MODE=true` value in `.env` makes `api/index.php` return a 503 with `{"maintenance":true}`; the SPA shows a maintenance screen. Toggle via `.env`, not a flag file.
