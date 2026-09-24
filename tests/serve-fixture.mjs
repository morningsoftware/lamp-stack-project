import { createFrontendServer } from '../scripts/dev-server.mjs';
import { createFixtureApi } from './fixture-api.mjs';
const {handler} = createFixtureApi({missingAdmin:process.env.FIXTURE_MISSING_ADMIN === '1'});
const port = Number(process.env.PORT || 8010);
createFrontendServer({fixture:handler}).listen(port,'127.0.0.1',() => {
  console.log(`DISPOSABLE FIXTURE ONLY: http://127.0.0.1:${port}`);
  console.log('Users: fixture-user / fixture-admin; password: FixturePass123!');
  console.log('All data is in memory and disappears when stopped. No live API connections.');
});
