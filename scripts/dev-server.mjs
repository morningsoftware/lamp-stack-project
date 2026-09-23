// Local-only static frontend + same-origin API proxy. No npm dependencies.
import http from 'node:http';
import https from 'node:https';
import { readFile } from 'node:fs/promises';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
const types = {'.html':'text/html; charset=utf-8','.js':'text/javascript; charset=utf-8','.css':'text/css; charset=utf-8','.png':'image/png','.svg':'image/svg+xml','.jpg':'image/jpeg','.webp':'image/webp','.pdf':'application/pdf'};

export function createFrontendServer({ apiTarget = 'http://lamp.morning.codes/api/index.php', fixture } = {}) {
  const target = new URL(apiTarget);
  if (!['http:', 'https:'].includes(target.protocol) || target.username || target.password) throw new Error('API target must be an HTTP(S) URL without credentials.');
  return http.createServer(async (req,res) => {
    res.setHeader('Cache-Control','no-store');
    res.setHeader('X-Content-Type-Options','nosniff');
    const url = new URL(req.url,'http://localhost');
    const apiPath = '/api/index.php';
    if (url.pathname === apiPath || url.pathname.startsWith(apiPath + '/')) {
      if (fixture) { await fixture(req,res,url); return; }
      const upstream = new URL(target);
      upstream.pathname = target.pathname.replace(/\/$/,'') + url.pathname.slice(apiPath.length);
      upstream.search = url.search;
      const headers = {};
      // The current PHP helper's apache_request_headers fallback is case-sensitive.
      for (const [key,name] of [['content-type','Content-Type'],['authorization','Authorization'],['accept','Accept']]) {
        if (req.headers[key]) headers[name] = req.headers[key];
      }
      const proxy = (target.protocol === 'https:' ? https : http).request(upstream,{method:req.method,headers},response => {
        res.writeHead(response.statusCode,{'Content-Type':response.headers['content-type'] || 'application/json'});
        response.pipe(res);
      });
      proxy.setTimeout(29000,() => proxy.destroy(new Error('API timeout')));
      proxy.on('error',() => {
        if (!res.headersSent) { res.writeHead(502,{'Content-Type':'application/json'}); res.end(JSON.stringify({error:'The API server is unavailable. Please try again.'})); }
        else res.destroy();
      });
      req.pipe(proxy);
      return;
    }
    if (!['GET','HEAD'].includes(req.method)) { res.writeHead(405); res.end(); return; }
    let pathname;
    try { pathname = decodeURIComponent(url.pathname); } catch (_) { res.writeHead(400); res.end(); return; }
    const relative = pathname === '/' ? 'index.html' : pathname.slice(1);
    // Never expose PHP source, configuration, Git metadata, tests, or credentials.
    if ((relative !== 'index.html' && !relative.startsWith('assets/')) || relative.split('/').some(part => part.startsWith('.')) || !types[path.extname(relative)]) {
      res.writeHead(404); res.end('Not found'); return;
    }
    try {
      const bytes = await readFile(path.join(root,relative));
      res.writeHead(200,{'Content-Type':types[path.extname(relative)]});
      res.end(req.method === 'HEAD' ? undefined : bytes);
    } catch (_) { res.writeHead(404); res.end('Not found'); }
  });
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const port = Number(process.env.PORT || 8000);
  const apiTarget = process.env.FRONTEND_API_URL || 'http://lamp.morning.codes/api/index.php';
  const server = createFrontendServer({apiTarget});
  server.on('error',err => { console.error(err.message); process.exitCode = 1; });
  server.listen(port,'127.0.0.1',() => {
    console.log(`Frontend: http://127.0.0.1:${port}`);
    console.log(`API proxy: ${apiTarget}`);
    console.log('This connects to the configured backend and uses its real data. Ctrl+C stops the server.');
  });
}
