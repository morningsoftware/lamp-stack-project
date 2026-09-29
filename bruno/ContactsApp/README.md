# Bruno API tests

HTTP tests for the collab.dev JSON API, organized by resource. Open this
folder as a Bruno collection (`opencollection.yml`).

## Setup

1. Select an environment (Local / prod) so `{{urlBase}}` resolves.
2. Create a `.env` file in this folder (it is gitignored) with the variables
   below, or set them in Bruno's environment. Variables are referenced as
   `{{process.env.*}}`.

## Environment variables

| Variable | Used by |
|---|---|
| `TEST_LOGIN` | Log in (User), Create conversation |
| `TEST_PASSWORD` | Log in (User), Update account |
| `TEST_EMAIL` | Update account |
| `ADMIN_LOGIN` / `ADMIN_PASSWORD` | Log in (Admin) |
| `NEW_LOGIN` / `NEW_EMAIL` / `NEW_PASSWORD` | Create new user, Create admin, Reset password |
| `NEW_GITHUB` | Create new user, Sync GitHub |
| `TEST_RESET_TOKEN` | Reset password (validate/perform) |
| `TEST_USERID` | profile / skills / github / follow / admin actions |
| `TEST_USERID2` | Compare, Follow/Unfollow, Add/Remove participant |
| `TEST_LINK_ID` | social link update/delete |
| `TEST_CONTACT_ID` | contact get/update/delete |
| `TEST_CONVO_ETAG` | conditional conversation list (304 check) |
| `TEST_CONVERSATION_ID` | conversation/message endpoints |
| `TEST_SINCE_MESSAGE_ID` | delta message fetch (`?since=`) |
| `TEST_MESSAGE_ID` | delete message |
| `TEST_ORG_SLUG` / `TEST_ORG_ID` | organization endpoints |
| `TEST_MEMBER_LOGIN` / `TEST_MEMBER_ID` | add/remove organization member |
| `TEST_INVITATION_ID` | accept/decline invitation |
| `TEST_ROLE_ID` | role endpoints |
| `TEST_APPLICANT_ID` | decide application |

## Suggested run order

1. **Log in (User)** (or register) to authenticate; admin tests need
   **Log in (Admin)**.
2. Create resources first (contact, organization, role, conversation), then
   copy the returned id/slug into the matching `TEST_*` variable and run the
   read/update/delete requests.
3. Run destructive requests last (delete organization/role/contact/message).

Many endpoints mutate state, so individual requests assume their dependencies
already exist (e.g. a role must exist before applying). Assertions check the
response status code only.
