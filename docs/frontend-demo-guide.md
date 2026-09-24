# Frontend lab and project demo guide

## Fifth Week checkoff

The required lab actions are: attend, show source in an editor, show the frontend, register on the server, and log in to the server.

1. Open `index.html`, `assets/css/app.css`, `assets/js/api.js`, and `assets/js/app.js` in your editor.
2. Start `./serve.sh` and open `http://127.0.0.1:8000`, or use the deployed site once the new frontend is published.
3. Explain: “The HTML provides the page shell, CSS styles the responsive interface, `api.js` sends JSON requests to PHP, and `app.js` handles forms, views, and rendering.”
4. Click **Register**. Use a unique username/email and a real GitHub account you own that is not already registered. Enter matching passwords of at least eight characters. Keep the credentials available privately.
5. Submit. Confirm the success message and the welcome page. If the server returns a duplicate/GitHub error, resolve the supplied account details; do not claim registration succeeded.
6. Sign out from the account menu. Click **Sign in**, use the account just created, and show its contacts page.
7. In `api.js`, show JSON serialization, the content-type header, the bearer token, and the register/login methods. In `app.js`, show validation, waiting/error handling, and the successful session refresh.

Prepare a valid unused GitHub account before lab. Rehearsing signup uses up that account's availability; choose the actual demo plan with your teammates beforehand. Do not use arbitrary other people's GitHub usernames. The old quick-fill shortcuts were removed because they contained working credentials and duplicate registration data.

## Full-project frontend demonstration

This sequence requires the real backend to implement the contract in `frontend-handoff.md`. A fixture demonstration does not meet the live-server requirement.

- Register and log in as an ordinary user.
- Add a designated demo contact; search by its name/email; edit each visible field and point out the fixed ID; delete that demo contact after confirming.
- Log in as an admin. Search users and show the separate admin contact list and owner labels.
- Disable a designated demo user; show that user's rejected login. Re-enable when appropriate.
- Change the demo user's password; show a successful login with the new password.
- Create another designated admin account and show its management controls, if demonstrating all functional requirements.
- Show the Bruno login endpoint as the team's separate API demonstration.

Use only designated demo records for destructive or account-management actions. Do not suspend teammates or change their credentials for the presentation.

## Rehearse the frontend safely

Run `node tests/serve-fixture.mjs` and visit `http://127.0.0.1:8010`. Fixture-only accounts are `fixture-user` and `fixture-admin`, each with password `FixturePass123!`. The fixture resets when stopped and never calls the live backend.

This mode is for rehearsal and frontend development. Use `./serve.sh` with the live API or the deployed site for actual GTA checkoff.
