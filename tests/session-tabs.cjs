const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const channels = [];
let nextId = 0;
function tab(base = '/') {
  const values = new Map();
  const events = [];
  class Channel {
    constructor(name) { this.name = name; channels.push(this); }
    postMessage(data) {
      for (const peer of channels) if (peer !== this && peer.name === this.name)
        queueMicrotask(() => peer.onmessage?.({data}));
    }
  }
  const window = {dispatchEvent: e => events.push(e.type)};
  const context = {window, location: {pathname: base, protocol: 'https:'},
    sessionStorage: {getItem: k => values.get(k), setItem: (k,v) => values.set(k,v), removeItem: k => values.delete(k)},
    localStorage: {removeItem() {}}, BroadcastChannel: Channel, crypto: {randomUUID: () => String(++nextId)},
    Event, URLSearchParams, setTimeout, clearTimeout};
  vm.runInNewContext(fs.readFileSync('assets/js/api.js','utf8'), context);
  return {api: window.API, events, values};
}
(async () => {
  const a = tab(); await a.api.ready;
  a.api.setToken('synthetic-session-a');
  const b = tab(); await b.api.ready;
  assert.equal(b.api.token, a.api.token, 'new tab inherits session');
  const isolated = tab('/different/'); await isolated.api.ready;
  assert.equal(isolated.api.token, null, 'different API cannot inherit token');
  a.api.setToken('synthetic-session-b'); await new Promise(setImmediate);
  assert.equal(b.api.token, a.api.token, 'account switch propagates');
  b.api.setToken(null); await new Promise(setImmediate);
  assert.equal(a.api.token, null, 'logout propagates');
  assert.equal(a.values.size, 0, 'logout removes saved session');
  console.log('PASS: new tab, API isolation, account switch, logout');
})();
