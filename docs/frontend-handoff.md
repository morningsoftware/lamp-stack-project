# Frontend handoff

Implemented on `damian/frontend`, September 23, 2026. This is a frontend change; the existing uncommitted `api/handlers/contacts.php` work is preserved unchanged. No PHP, SQL, live accounts, or deployment configuration were changed.

## What is implemented

- Sign In and Register on the landing page and navigation; labeled forms, confirmation passwords, inline errors, pending states, and clear GitHub-account guidance.
- No public demo credentials or fixed registration helper.
- Contact creation, individual-record loading, editing of first/last name, email, phone and notes, read-only contact ID, and deletion by contact ID.
- Debounced API-backed contact search, page navigation, empty/error/retry states, stale-response protection, and post-save refresh. No client-side contact filtering.
- Admin creation, user search/status filtering, other-admin disable/enable controls, direct password-change form, existing reset-link flow, and all-users/per-user contact queries.
- Current account cannot disable itself, matching the existing PHP endpoint.
- Keyboard-accessible dialogs, focus restoration, Escape dismissal, responsive contact cards, and horizontally scrollable admin tables.
- Tab-scoped sessions, unauthorized-session handling, request timeouts, malformed-response handling, and HTTPS mixed-content protection.
- Same-origin API URLs in deployed frontend. Local development uses a loopback-only API proxy and does not expose PHP, environment files, Git metadata or tests.

## Run locally

Requires Node.js 20+; there are no npm dependencies.

```sh
cd /Users/damianlubis/Downloads/lamp-stack-project
./serve.sh
```

Open `http://127.0.0.1:8000`. The default proxy connects to the team's existing **HTTP** backend at `http://lamp.morning.codes/api/index.php`. This uses real team data. The proxy does not make that upstream HTTP connection secure.

Once the teammate configuring TLS enables HTTPS:

```sh
FRONTEND_API_URL=https://lamp.morning.codes/api/index.php ./serve.sh
```

For a different local PHP backend:

```sh
FRONTEND_API_URL=http://127.0.0.1:8080/api/index.php PORT=8000 ./serve.sh
```

Run this project through `serve.sh` rather than a plain static server if you need a working API. For Apache deployment, place `index.html` and `assets/` alongside `api/`. The browser then uses the same domain and protocol automatically. The production frontend no longer reads the old `localStorage['api.baseUrl']` override. An explicit `window.API_BASE_URL` override before `api.js` loads remains available for a deliberately configured integration.

## Safe fixture and automated checks

```sh
node --test tests/frontend-api.test.mjs
node tests/serve-fixture.mjs
```

The second command serves `http://127.0.0.1:8010` with disposable in-memory data. It makes **no live backend calls**. Its credentials are fixture-only:

- `fixture-user` / `FixturePass123!`
- `fixture-admin` / `FixturePass123!`

Stopping the fixture clears its simulated users and contacts. Never deploy or present this fixture as evidence of a working PHP/MySQL backend. Use it for frontend development and UI rehearsal only.

To test unsupported admin endpoints:

```sh
FIXTURE_MISSING_ADMIN=1 PORT=8011 node tests/serve-fixture.mjs
```

## Backend integration contract

These are the interfaces used by the finished frontend. **The new admin endpoints are proposed contracts, not claims about deployed functionality.** Implement them in the PHP API or agree on another contract and update the three client methods before deployment.

All calls are under `/api/index.php`, use JSON bodies, and authenticated calls send `Authorization: Bearer <token>`. Successful responses must contain `data`; errors use an appropriate non-2xx status and `{ "error": "message" }`. Mutations must not return a successful status unless they succeeded. The frontend never substitutes mock success when a server action is unavailable.

### Contact search and CRUD

| Method and route | Request | Response |
|---|---|---|
| GET `/contacts?q=...&page=1&limit=20` | `q` optional; one-based page; bounded limit | `{data: Contact[], meta: {total, page, limit}}` |
| GET `/contacts/:contactid` | Owner authorization | `{data: Contact}` with independent first/last names |
| POST `/contacts` | `{firstName,lastName,email,phone,description}` | 201 `{data: {contactid,...}}` |
| PUT `/contacts/:contactid` | Same five editable fields; no ID/owner fields | `{data: {contactid,...}}` |
| DELETE `/contacts/:contactid` | Owner authorization | `{data: {message}}` |

Contact representation:

```json
{
  "contactid": 42,
  "userid": null,
  "firstName": "Ada",
  "lastName": "Lovelace",
  "email": "ada@example.invalid",
  "phone": "5551234567",
  "description": "Project collaborator"
}
```

`userid` is the optional linked developer ID in the existing contact response, not the owner ID. The UI always deletes by `contactid`. Legacy first/last-name lowercase keys are accepted for display/edit initialization.

Search must be performed in SQL with an owner predicate, ordering, COUNT, LIMIT and OFFSET. Do not return the entire address book and expect browser filtering. Return the filtered total in `meta`. The UI requests 20 results and rejects oversized responses; it cannot force an old backend to honor the limit. Legacy responses without metadata can display one small returned batch, but pagination and the assignment's bounded-query requirement require the backend update.

The existing uncommitted contacts handler has CRUD/search work, but it still needs pagination and integration validation. Its previous deployed version may lack create/edit/single-record operations. First and last names have maximum 50 characters each; email and description 100; phone 10, reflecting the supplied schema. Enforce validation and contact ownership server-side as well.

### Administrator creation — backend needed

POST `/admin/users`

```json
{
  "login": "new-admin",
  "email": "new-admin@example.invalid",
  "firstName": "New",
  "lastName": "Administrator",
  "password": "<entered password>",
  "isAdmin": true
}
```

Return 201 `{data: {userid,login,isAdmin:true,...}}`. Authenticate the requesting administrator; do not trust the role flag without server authorization. Hash/salt passwords, enforce uniqueness/lengths, and return 409 on duplicate login/email. This admin-only creation contract does not require GitHub linking.

### Administrator contact queries — backend needed

GET `/admin/contacts?q=...&userid=2&page=1&limit=20`

`userid` is an optional **owner filter** on this admin-only endpoint. Omit it to search all owners. Return the same paginated contact structure plus `ownerId` and `ownerLogin` (or `ownerName`) on every row. Enforce administrator authorization. Include ordinary contacts as well as contacts linked to developer accounts.

### Administrator password change — backend needed

PUT `/admin/users/:userid/password`, body `{ "newPassword": "<entered password>" }`.

Return `{data: {message}}`. Require administrator authorization, validate/hash/salt the password, and revoke the target user's sessions and outstanding reset tokens. The UI signs the current admin out if changing their own password.

The existing POST `/admin/users/:userid/reset-password` endpoint remains supported for a one-time reset link. The frontend extracts the token and makes a link to the current frontend origin, so local demos do not accidentally open a stale deployed UI. A localhost reset link only works on the same computer; use the deployed frontend to generate links intended for another person's machine.

### Existing interfaces reused

- GET `/admin/users?q=...&status=...&page=1&limit=20` for users.
- POST `/admin/users/:userid/disable` and `/enable`, including other admins. Keep the existing self-disable restriction and revoke suspended users' sessions.
- POST `/auth/register` with `login,email,password,githubUsername,firstName,lastName`.
- POST `/auth/login`, GET `/auth/session`, POST `/auth/logout`.
- GET `/auth/reset?token=...`, POST `/auth/reset` with `token,newPassword`.

The current registration API requires a real, unused GitHub username. The frontend now explains that constraint and no longer reuses `torvalds`. A real successful registration still needs verification using an account the team is authorized to link. A fixture signup does not establish live GitHub verification works.

## Team-owned release work

- Implement/deploy the missing endpoints and paginated SQL contact queries.
- Verify ownership and administrator authorization against real PHP/MySQL, including disabled-session rejection.
- Create the required `root` / `Application Administrator` seed via a non-destructive migration. Do not reset the live database.
- Rotate the previously exposed administrator password; removal from frontend source does not erase old Git history.
- Enable HTTPS/TLS and redirects, then use HTTPS for the development proxy too.
- Deploy the frontend branch and rerun the live demo. These changes have not been pushed or deployed.

## Verification completed

Nine automated checks passed: registration/login/duplicate/logout contract, bounded contact search/pagination/CRUD, admin workflows, fixture authorization boundaries, unsupported endpoint failures, proxy forwarding, session expiry, malformed/network/mixed-content responses, and restricted static serving.

Browser verification against the fixture covered registration/password mismatch, user/admin login, contact page 1/2, search, new-contact validation and save, edit/save with immutable ID, admin creation, per-user contact list, and mobile layouts. These checks establish frontend behavior against the documented contract, not real backend correctness. Fixture tests did not modify team production data. A separate live smoke test verified the updated runner, user login/session, empty contacts list, and logout. That test caught and fixed the PHP authorization-header spelling dependency; the proxy test now covers it. No live accounts or contacts were created, edited, suspended, or deleted.
