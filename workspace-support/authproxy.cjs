const http = require('http');
const AS = process.env.AS_EMAIL || 'rezaei@pars.example';
const UP = 'http://127.0.0.1:8000';
let cookie = null;
function req(method, path, body, hdrs = {}) {
  return new Promise((resolve, reject) => {
    const r = http.request(UP + path, { method, headers: hdrs }, (res) => {
      let d = []; res.on('data', c => d.push(c));
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(d) }));
    });
    r.on('error', reject); if (body) r.write(body); r.end();
  });
}
const jar = (res, prev) => {
  const set = res.headers['set-cookie'] || [];
  const m = Object.fromEntries((prev || '').split('; ').filter(Boolean).map(s => { const i = s.indexOf('='); return [s.slice(0, i), s.slice(i + 1)]; }));
  for (const c of set) { const kv = c.split(';')[0]; const i = kv.indexOf('='); m[kv.slice(0, i)] = kv.slice(i + 1); }
  return Object.entries(m).map(([k, v]) => `${k}=${v}`).join('; ');
};
async function login() {
  const g = await req('GET', '/login'); cookie = jar(g);
  const tok = (g.body.toString().match(/name="_token" value="([^"]+)"/) || [])[1];
  const form = `_token=${encodeURIComponent(tok)}&email=${encodeURIComponent(AS)}&password=password`;
  const p = await req('POST', '/login', form, { 'Content-Type': 'application/x-www-form-urlencoded', 'Content-Length': Buffer.byteLength(form), Cookie: cookie });
  cookie = jar(p, cookie); console.log('login ->', p.status, 'as', AS);
}
http.createServer(async (creq, cres) => {
  if (!cookie) await login();
  const r = await req(creq.method, creq.url, null, { Cookie: cookie, Accept: creq.headers.accept || '*/*' });
  const h = { ...r.headers }; delete h['content-encoding']; delete h['transfer-encoding'];
  cres.writeHead(r.status, h); cres.end(r.body);
}).listen(8099, '0.0.0.0', () => console.log('proxy on 8099'));
