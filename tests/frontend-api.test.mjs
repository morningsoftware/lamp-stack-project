import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import http from 'node:http';
import {readFile} from 'node:fs/promises';
import {createFrontendServer} from '../scripts/dev-server.mjs';
import {createFixtureApi} from './fixture-api.mjs';

const source = await readFile(new URL('../assets/js/api.js',import.meta.url),'utf8');
function client(fetchImpl, options = {}) {
  const values = new Map();
  const events = [];
  const window = {dispatchEvent:event=>events.push(event.type),...options.window};
  const sessionStorage = {getItem:key=>values.get(key),setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};
  const context={window,sessionStorage,localStorage:{removeItem:()=>{}},location:{pathname:'/index.html',protocol:'http:',...options.location},URLSearchParams,AbortController,Event,TypeError,setTimeout,clearTimeout,fetch:fetchImpl};
  vm.runInNewContext(source,context);
  return {api:window.API,values,events};
}
async function setup(t,options) {
  const fixture = createFixtureApi(options);
  const server = createFrontendServer({fixture:fixture.handler});
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  t.after(()=>new Promise(resolve=>{server.close(resolve);server.closeAllConnections();}));
  const origin=`http://127.0.0.1:${server.address().port}`;
  const c=client((url,options)=>fetch(new URL(url,origin),options));
  return {...c,...fixture,origin};
}
async function login(api,login='fixture-user',password='FixturePass123!') { const result=await api.login(login,password); api.setToken(result.token); return result; }

test('registration, fresh login, duplicate rejection and logout use the JSON API',async t=>{
  const {api,values}=await setup(t);
  const newUser=await api.register({login:'new-fixture',email:'new@example.invalid',githubUsername:'owned-fixture',password:'FixturePass123!',firstName:'New',lastName:'Person'});
  api.setToken(newUser.token);
  assert.equal((await api.session()).login,'new-fixture');
  assert.equal(values.size,1);
  await api.logout(); api.setToken(null); assert.equal(values.size,0);
  await login(api,'new-fixture');
  await assert.rejects(api.register({login:'new-fixture',email:'new@example.invalid',githubUsername:'owned-fixture',password:'FixturePass123!'}),/already registered/);
});
test('contact search and pagination send bounded queries, edits persist, ordinary contact deletes by ID',async t=>{
  const {api,requests}=await setup(t); await login(api);
  const first=await api.contacts({page:1,limit:20}); assert.equal(first.data.length,20); assert.equal(first.meta.total,25);
  const second=await api.contacts({page:2,limit:20}); assert.equal(second.data.length,5);
  const created=await api.createContact({firstName:'A & B',lastName:'Test',email:'test@example.invalid',phone:'1234567890',description:'A private note'});
  const found=await api.contacts({q:'A & B',page:1,limit:20}); assert.equal(found.data.length,1);
  await api.updateContact(created.contactid,{firstName:'Changed',lastName:'Name',email:'new@example.invalid',phone:'9876543210',description:'Updated'});
  const updated=await api.contact(created.contactid); assert.equal(updated.contactid,created.contactid); assert.equal(updated.description,'Updated');
  await api.removeContact(created.contactid);
  await assert.rejects(api.contact(created.contactid),error=>error.status===404);
  assert(requests.some(r=>r.query.q==='A & B' && r.query.limit==='20'));
  assert(requests.some(r=>r.method==='DELETE' && r.path===`/contacts/${created.contactid}`));
  assert(!requests.some(r=>r.path.includes('/null/')));
});
test('admin creation, contacts query, other-admin suspension and password replacement contract',async t=>{
  const {api}=await setup(t); await login(api,'fixture-admin');
  const created=await api.adminCreateUser({login:'created-admin',email:'created@example.invalid',firstName:'Created',lastName:'Admin',password:'FixturePass123!'});
  assert.equal(created.isAdmin,true);
  const contacts=await api.adminContacts({userid:2,q:'Contact 01',page:1,limit:20}); assert.equal(contacts.data.length,1); assert.equal(contacts.data[0].ownerLogin,'fixture-user');
  await api.adminDisable(created.userid);
  await assert.rejects(api.login('created-admin','FixturePass123!'),/disabled/);
  await api.adminEnable(created.userid);
  await api.adminPassword(created.userid,'ReplacementPass123!');
  await assert.rejects(api.login('created-admin','FixturePass123!'),/Invalid/);
  assert.equal((await api.login('created-admin','ReplacementPass123!')).isAdmin,true);
});
test('regular account cannot use admin endpoints or read another owner’s contact',async t=>{
  const {api}=await setup(t); await login(api,'fixture-admin');
  const c=await api.createContact({firstName:'Private'});
  await login(api);
  await assert.rejects(api.adminContacts(),error=>error.status===403);
  await assert.rejects(api.contact(c.contactid),error=>error.status===404);
});
test('missing backend implementation reports an actionable failure, not success',async t=>{
  const {api}=await setup(t,{missingAdmin:true}); await login(api,'fixture-admin');
  await assert.rejects(api.adminCreateUser({login:'pending'}),/not available on the server yet/);
  await assert.rejects(api.adminContacts(),/not available on the server yet/);
  await assert.rejects(api.adminPassword(2,'UnusedFixturePass!'),/not available on the server yet/);
});

test('development proxy preserves API path, search, JSON and bearer authorization',async t=>{
  let observed;
  const upstream=http.createServer(async(req,res)=>{
    assert(req.rawHeaders.includes('Authorization'),'Preserve the header spelling used by the PHP fallback');
    let body='';for await(const chunk of req) body+=chunk;
    observed={path:req.url,method:req.method,authorization:req.headers.authorization,type:req.headers['content-type'],body:JSON.parse(body)};
    res.writeHead(201,{'Content-Type':'application/json'});res.end(JSON.stringify({data:{contactid:42}}));
  });
  await new Promise(resolve=>upstream.listen(0,'127.0.0.1',resolve));
  const frontend=createFrontendServer({apiTarget:`http://127.0.0.1:${upstream.address().port}/api/index.php`});
  await new Promise(resolve=>frontend.listen(0,'127.0.0.1',resolve));
  t.after(()=>{frontend.closeAllConnections();frontend.close();upstream.closeAllConnections();upstream.close();});
  const res=await fetch(`http://127.0.0.1:${frontend.address().port}/api/index.php/contacts?q=A%26B`,{method:'POST',headers:{'Content-Type':'application/json','Authorization':'Bearer fixture-only'},body:JSON.stringify({firstName:'Fixture'})});
  assert.equal(res.status,201);assert.equal((await res.json()).data.contactid,42);
  assert.deepEqual(observed,{path:'/api/index.php/contacts?q=A%26B',method:'POST',authorization:'Bearer fixture-only',type:'application/json',body:{firstName:'Fixture'}});
});
test('401 clears tab session and emits expiry; failed login does not expire a valid session',async t=>{
  const {api,events,values}=await setup(t); await login(api);
  await assert.rejects(api.login('fixture-user','wrong'));
  assert.equal(events.length,0); assert.equal(values.size,1);
  await api.logout(); await assert.rejects(api.session());
  assert.equal(api.token,null); assert.equal(values.size,0); assert.deepEqual(events,['session-expired']);
});
test('malformed success responses, network failure, and mixed content are rejected',async()=>{
  for (const text of ['<html>Error</html>','{"error":"not successful"}','']) {
    const {api}=client(async()=>({ok:true,text:async()=>text}));
    await assert.rejects(api.contacts(),/unexpected response/);
  }
  const {api}=client(async()=>{throw new TypeError('fetch failed');});
  await assert.rejects(api.contacts(),/Could not reach/);
  const secure=client(()=>{throw new Error('Should not fetch');},{location:{protocol:'https:'},window:{API_BASE_URL:'http://example.invalid/api'}});
  await assert.rejects(secure.api.contacts(),/secure API connection/);
});
test('local server serves frontend but does not expose backend, Git or test files',async t=>{
  const {origin}=await setup(t);
  assert.equal((await fetch(origin+'/')).status,200);
  assert.equal((await fetch(origin+'/assets/js/app.js')).status,200);
  for (const file of ['/api/config/db.php','/.env','/.git/config','/tests/fixture-api.mjs','/resetdb.sql','/assets/%2e%2e%2f.env']) {
    assert.equal((await fetch(origin+file)).status,404,file);
  }
});
