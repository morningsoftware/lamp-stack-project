const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const s=fs.readFileSync('assets/js/app.js','utf8');
function code(start,end) { return s.slice(s.indexOf(start),s.indexOf(end,s.indexOf(start))); }
const c={URL,uniqueSharedLanguages:()=>[],ICON:{},avatarHtml:()=>'',escapeHtml:v=>String(v).replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;'),displayNameOf:d=>d.login,fmtNum:()=>0,skillChips:()=>'',langBarHtml:()=>''};
vm.createContext(c);vm.runInContext(code('  function devCardHtml(', '  function wireDevActions('),c);
const html=c.devCardHtml({login:'qa" data-audit="injected',userid:7,isSelf:false});
assert(!html.includes('href="#/dev/qa" data-audit='));
assert(html.includes('qa%22%20data-audit%3D%22injected'));
assert(html.includes('aria-label="message '));
const grid={innerHTML:'',isConnected:true,insertAdjacentHTML(_,v){this.innerHTML+=v;}};
const more={innerHTML:''},count={textContent:''},pending=[];
Object.assign(c,{$:id=>({'#browse-grid':grid,'#browse-more':more,'#browse-count':count}[id]),browseState:{filters:{type:'developers',q:'old',skills:[]},offset:0},API:{profiles:p=>new Promise(resolve=>pending.push({p,resolve}))},skeleton:()=>'',wireDevActions:()=>{},devCardHtml:d=>d.name,emptyState:()=>''});
vm.runInContext(code('  async function loadBrowse(reset)', '\n/**'),c);
(async()=>{
 const old=c.loadBrowse(true);c.browseState.filters.q='new';const next=c.loadBrowse(true);
 assert.equal(pending.length,2);
 pending[1].resolve({data:[{name:'new result'}],meta:{total:1}});await next;
 pending[0].resolve({data:[{name:'old result'}],meta:{total:1}});await old;
 assert.equal(grid.innerHTML,'new result');assert.equal(c.browseState.offset,1);
 const gone=c.loadBrowse(true);grid.isConnected=false;pending[2].resolve({data:[{name:'detached result'}],meta:{total:1}});await gone;
 assert(!grid.innerHTML.includes('detached result'));
 console.log('PASS: attribute encoding, accessible message action, out-of-order searches, navigation guard');
})();
