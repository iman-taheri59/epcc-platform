#!/usr/bin/env node
// ---------------------------------------------------------------------------
// EPCC Platform — live preview server
//
// Serves the self-contained, air-gap-safe HTML prototype over plain HTTP so it
// can be opened as a real preview (reverse-proxied or direct). Zero npm
// dependencies on purpose: this script must run on any machine that has a
// Node runtime, mirroring the air-gap constraint of the product itself —
// no CDN, no network fetch, no build step.
//
//   node scripts/preview-server.mjs          # 0.0.0.0:8080
//   PORT=9000 node scripts/preview-server.mjs
//
// Endpoints:
//   /                      prototype/index.html (Persian-first, IPS pack)
//   /piping-workspace.html piping workspace preview
//   /<file>                any file under prototype/
//   /healthz               JSON liveness probe
// ---------------------------------------------------------------------------
import http from 'node:http';
import { readFile, stat } from 'node:fs/promises';
import { extname, join, normalize, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = resolve(fileURLToPath(new URL('..', import.meta.url)));
const root = join(repoRoot, 'prototype');
const port = Number(process.env.PORT || 8080);
const host = process.env.HOST || '0.0.0.0';

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.ico': 'image/x-icon',
  '.txt': 'text/plain; charset=utf-8',
};

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  if (url.pathname === '/healthz') {
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(JSON.stringify({ ok: true, root: 'prototype/', at: new Date().toISOString() }));
    return;
  }

  let rel = decodeURIComponent(url.pathname);
  if (rel === '/' || rel === '') rel = '/index.html';

  // Never escape the prototype/ directory.
  const abs = resolve(join(root, normalize(rel)));
  if (!abs.startsWith(root)) {
    res.writeHead(403, { 'content-type': 'text/plain' });
    res.end('Forbidden');
    return;
  }

  try {
    const st = await stat(abs);
    const file = st.isDirectory() ? join(abs, 'index.html') : abs;
    const body = await readFile(file);
    res.writeHead(200, {
      'content-type': MIME[extname(file).toLowerCase()] || 'application/octet-stream',
      'cache-control': 'no-cache',
    });
    res.end(body);
  } catch {
    res.writeHead(404, { 'content-type': 'text/plain; charset=utf-8' });
    res.end('Not found — the prototype screens are index.html and piping-workspace.html');
  }
});

server.listen(port, host, () => {
  console.log(`EPCC prototype preview listening on http://${host}:${port}`);
  console.log('Screens: / (main prototype) · /piping-workspace.html · /healthz');
});
