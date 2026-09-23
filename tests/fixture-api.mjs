// Disposable, in-memory API contract fixture. Never connects to the real backend.
export function createFixtureApi({missingAdmin = false} = {}) {
  const users = [
    {userid:1,login:'fixture-admin',email:'admin@example.invalid',firstName:'Fixture',lastName:'Admin',displayName:'Fixture Admin',isAdmin:true,isActive:true},
    {userid:2,login:'fixture-user',email:'user@example.invalid',firstName:'Fixture',lastName:'User',displayName:'Fixture User',isAdmin:false,isActive:true},
    {userid:3,login:'fixture-other-admin',email:'other@example.invalid',displayName:'Other Admin',isAdmin:true,isActive:true},
  ];
  const passwords = new Map(users.map(user => [user.userid,'FixturePass123!']));
  const sessions = new Map();
  const resets = new Map();
  let nextUser = 4, nextContact = 26, nextToken = 1;
  const contacts = Array.from({length:25},(_,i) => ({contactid:i+1,ownerId:2,userid:null,firstName:'Contact',lastName:String(i+1).padStart(2,'0'),email:`contact${i+1}@example.invalid`,phone:'5551234567',description:'Fixture contact',displayName:`Contact ${i+1}`}));
  const requests = [];
  const handler = async (req,res,url) => {
    const path = url.pathname.replace('/api/index.php','');
    const method = req.method;
    requests.push({method,path,query:Object.fromEntries(url.searchParams)});
    let raw = ''; for await (const chunk of req) raw += chunk;
    let body = {}; try { body = raw ? JSON.parse(raw) : {}; } catch (_) { /* tested invalid body */ }
    const send = (status,data,meta) => { res.writeHead(status,{'Content-Type':'application/json'}); res.end(JSON.stringify(status >= 400 ? {error:data} : {data,...(meta ? {meta} : {})})); };
    const token = req.headers.authorization?.replace(/^Bearer /,'');
    const user = users.find(user => user.userid === sessions.get(token) && user.isActive);
    const paginate = rows => {
      const page = Math.max(1,Number(url.searchParams.get('page') || 1));
      const limit = Math.max(1,Math.min(100,Number(url.searchParams.get('limit') || 20)));
      return send(200,rows.slice((page-1)*limit,page*limit),{total:rows.length,page,limit});
    };
    const match = value => String(value).toLowerCase().includes((url.searchParams.get('q') || '').toLowerCase());
    const signIn = person => { const token = 'fixture-token-' + nextToken++; sessions.set(token,person.userid); return {...person,token}; };
    if (path === '/auth/register' && method === 'POST') {
      if (users.some(u => u.login === body.login || u.email === body.email || (body.githubUsername && u.githubUsername === body.githubUsername))) return send(409,'Login, email, or GitHub account is already registered');
      if (!body.githubUsername || !body.email || !body.login || body.password?.length < 8) return send(400,'Complete all required fields');
      const person = {...body,userid:nextUser++,isAdmin:false,isActive:true,displayName:body.firstName || body.login};
      delete person.password; users.push(person); passwords.set(person.userid,body.password); return send(201,signIn(person));
    }
    if (path === '/auth/login') {
      const person = users.find(u => u.login === body.login || u.email === body.login);
      if (!person || passwords.get(person.userid) !== body.password) return send(401,'Invalid login or password');
      if (!person.isActive) return send(403,'This account has been disabled');
      return send(200,signIn(person));
    }
    if (path === '/auth/reset') {
      const reset = resets.get(body.token || url.searchParams.get('token'));
      if (!reset) return send(400,'Invalid or expired reset link');
      if (method === 'GET') return send(200,{valid:true});
      passwords.set(reset,body.newPassword); resets.delete(body.token);
      for (const [key,id] of sessions) if (id === reset) sessions.delete(key);
      return send(200,{message:'Password updated'});
    }
    if (!user) return send(401,'Unauthorized');
    if (path === '/auth/session') return send(200,user);
    if (path === '/auth/logout') { sessions.delete(token); return send(200,{message:'Logged out'}); }
    if (path.startsWith('/admin/') && !user.isAdmin) return send(403,'Admin access required');
    if (missingAdmin && (path === '/admin/contacts' || (path === '/admin/users' && method === 'POST') || path.endsWith('/password'))) return send(405,'Method not allowed');
    if (path === '/admin/users' && method === 'GET') return paginate(users.filter(u => match(u.login+' '+u.displayName+' '+u.email)).filter(u => {
      const status=url.searchParams.get('status'); return !status || (status === 'admin' ? u.isAdmin : status === 'active' ? u.isActive : !u.isActive);
    }));
    if (path === '/admin/users' && method === 'POST') {
      if (users.some(u => u.login === body.login || u.email === body.email)) return send(409,'Login or email is already registered');
      const person={...body,userid:nextUser++,isAdmin:body.isAdmin === true,isActive:true,displayName:body.firstName+' '+body.lastName};
      delete person.password; users.push(person); passwords.set(person.userid,body.password); return send(201,person);
    }
    const adminAction = path.match(/^\/admin\/users\/(\d+)\/(disable|enable|password|reset-password)$/);
    if (adminAction) {
      const id=Number(adminAction[1]),action=adminAction[2],target=users.find(u=>u.userid===id);
      if (!target) return send(404,'User not found');
      if (action === 'reset-password') { const reset='fixture-reset-'+nextToken++; resets.set(reset,id); return send(201,{resetUrl:'http://fixture.invalid/index.html#/reset?token='+reset}); }
      if (action === 'disable' && id === user.userid) return send(400,'You cannot disable your own account');
      if (action === 'password') passwords.set(id,body.newPassword);
      else target.isActive = action === 'enable';
      if (action !== 'enable') for (const [key,owner] of sessions) if (owner===id) sessions.delete(key);
      return send(200,{message:'Updated'});
    }
    if (path === '/admin/stats') return send(200,{users:{total:users.length,active:users.filter(u=>u.isActive).length,inactive:0,admins:2,new7:0,new30:0},github:{linked:0,stale:0,repos:0},messaging:{messages:0,conversations:0},skills:{top:[]},languages:[],recentSignups:[]});
    if (path === '/admin/contacts') return paginate(contacts.filter(c => !url.searchParams.get('userid') || String(c.ownerId)===url.searchParams.get('userid')).filter(c=>match([c.firstName,c.lastName,c.email,c.phone,c.description].join(' '))).map(c=>({...c,ownerLogin:users.find(u=>u.userid===c.ownerId)?.login})));
    if (path === '/contacts' && method === 'GET') return paginate(contacts.filter(c=>c.ownerId===user.userid).filter(c=>match([c.firstName,c.lastName,c.email,c.phone,c.description].join(' '))));
    if (path === '/contacts' && method === 'POST') { const contact={...body,contactid:nextContact++,ownerId:user.userid,userid:null}; contacts.unshift(contact); return send(201,contact); }
    const contactRoute=path.match(/^\/contacts\/(\d+)$/);
    if (contactRoute) {
      const index=contacts.findIndex(c=>c.contactid===Number(contactRoute[1]) && c.ownerId===user.userid);
      if (index < 0) return send(404,'Contact not found');
      if (method === 'GET') return send(200,contacts[index]);
      if (method === 'PUT') { const {contactid,ownerId,userid,...fields}=body; Object.assign(contacts[index],fields); return send(200,contacts[index]); }
      if (method === 'DELETE') { contacts.splice(index,1); return send(200,{message:'Contact removed'}); }
    }
    return send(404,'Not found');
  };
  return {handler,requests};
}
