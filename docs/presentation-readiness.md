# Presentation readiness — September 29

## Submit today

Use the team's existing deck. Submit the final unzipped PowerPoint before the stated deadline; nominate one person to submit and retain the submission receipt. Confirm the exact time in Webcourses. The earlier supplied instructions say September 29. Do not spend slide time on features that have not passed a rehearsal.

## Suggested 10-minute running order

| Time | Content | Suggested owner |
|---|---|---|
| 0:00–0:40 | collab.dev: a personal contacts manager with developer collaboration; URLs and team roles | Opening speaker |
| 0:40–1:20 | Architecture and technology: browser → PHP JSON API → MySQL on Apache/Linux; GitHub integration and deployment | Infrastructure/API owner |
| 1:20–2:20 | Gantt, use case, activity/sequence and ERD; emphasize the added relationships | Relevant team members |
| 2:20–3:00 | SwaggerHub endpoint demo | API owner |
| 3:00–6:30 | Registration, login, partial contact search, add/edit/delete; admin suspend and password change | Frontend + backend owners |
| 6:30–7:20 | One reliable extra feature: GitHub verified profile or organizations/roles | Feature owner |
| 7:20–8:00 | Lighthouse desktop evidence and CI/CD | Frontend/infrastructure owner |
| 8:00–9:15 | Good, Bad, Ugly; AI disclosure and lessons | Each member contributes |
| 9:15–10:00 | Closing and buffer | Team |

Replace owner placeholders with actual agreed names. All members should explain meaningful work. Practice transitions and the full timed demonstration.

## Required diagrams

- Use case: User registration/login/contact CRUD; Admin search, suspend users, change passwords, create administrators; optional GitHub and organizations.
- Sequence or activity: contact search request from browser → API → authenticated SQL query → JSON response → rendered contacts. A sequence diagram satisfies this item.
- ERD: derive from current resetdb.sql. Include users, contacts, sessions, GitHub tables, organizations, members, roles, skills, applications, invitations, conversations, participants and messages. Use an overview plus a readable focus diagram if needed.
- Gantt: use actual team dates/assignments from the team and commit history. Do not invent historical milestones.

## SwaggerHub

Import docs/openapi.yaml into SwaggerHub. The repository file alone does not earn the live demonstration criterion. Rehearse GET /ping and POST /auth/login. Browser Try it out requires the server's CORS allowlist to permit the actual SwaggerHub origin; check it before presentation. Do not expose real account passwords or bearer tokens in slides. Bruno is useful for testing but is not a substitute unless the instructor permits it.

## Good, Bad, Ugly — verify with the team

Suggested talking points, not invented experiences:
- Good: separate frontend/API/database work; reusable JSON API; automated deployment; optional GitHub ownership verification.
- Bad: coordinated schema upgrades and API contracts; device-specific performance; keeping a demo within ten minutes.
- Ugly: legacy GitHub usernames collided with the OAuth migration; browser-supplied account IDs needed server-side binding; passing the login-page audit did not prove every authenticated page accessible.
- Lesson: define interfaces early, make migrations repeatable, test account upgrades and failures, and rehearse on the actual deployment.
- AI disclosure: identify which tools helped with code, review, debugging or slides and describe human verification accurately.

## Release and demo gates

- Merge/deploy the OAuth/rubric patch after team review. It is not live merely because a PR exists.
- Existing accounts: password sign-in → Settings → GitHub → Connect → authorize → sign out → GitHub sign-in.
- Confirm ordinary new-account registration, correct login, wrong-password page, contact CRUD/search and user isolation.
- Confirm admin suspend/re-enable, changed-password login and creating a second admin.
- The seed now names root / Application Administrator for fresh installs. Existing production administrators are deliberately not renamed or reset automatically. Verify the required root account exists on production with the DB owner; never run resetdb.sql against the live database to fix a seed.
- Run Lighthouse on authenticated views in both themes; search icon labels are now present.
- Keep a dedicated demo account and harmless contact ready. Keep HTTPS URL visible. Check campus network reachability.

## Improvements ranked by value

Before presenting: prioritize reliable sign-in, contact CRUD and admin demo, accessible controls, completed diagrams and rehearsal over adding features.

Next iteration: add integration tests for OAuth account upgrades and ownership, a staging deployment with migration checks, a safe way for OAuth-only users to add a password, and robust message polling for renames/deletions. Move all typeahead searches to server-backed queries if the instructor interprets 'all searches' broadly; some optional pickers still filter loaded catalogs.

Later: cursor-based message pagination, cross-tab session UX (GitHub issue #16 is open), and narrower performance investigations based on repeated mobile/tablet runs. Keep contact management prominent so the additional collaboration features do not obscure the assignment's core.
