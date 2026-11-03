import { mkdir, readFile, writeFile, copyFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = fileURLToPath(new URL('../', import.meta.url));
const backendValue = process.env.BACKEND_ORIGIN;
if (!backendValue) throw new Error('BACKEND_ORIGIN doit contenir l’origine HTTPS du backend.');
const backend = new URL(backendValue);
if (backend.protocol !== 'https:' || backend.username || backend.password
    || backend.pathname !== '/' || backend.search || backend.hash
    || backend.hostname === 'localhost' || backend.hostname === '127.0.0.1') {
    throw new Error('BACKEND_ORIGIN doit être une origine HTTPS publique, sans chemin ni identifiants.');
}

// Use the maintained Laravel view so that the two hosting targets share one UI.
const source = await readFile(path.join(root, 'resources/views/mistral-raw.blade.php'), 'utf8');
const html = source.replace(/\{\{\s*asset\('bako1\.png'\)\s*\}\}/g, '/bako1.png');
if (/\{\{|\{!!|<\?php|@(if|foreach|include|vite|php)\b/.test(html)) {
    throw new Error('La vue contient une instruction serveur non prise en charge par l’export statique.');
}

const output = path.join(root, '.vercel/output');
const staticDir = path.join(output, 'static');
await mkdir(staticDir, { recursive: true });
await writeFile(path.join(staticDir, 'index.html'), html);
for (const asset of ['bako1.png', 'favicon.ico', 'robots.txt']) {
    await copyFile(path.join(root, 'public', asset), path.join(staticDir, asset));
}
await writeFile(path.join(output, 'config.json'), JSON.stringify({
    version: 3,
    routes: [
        {
            src: '^/api/(mistral-raw/ask|ask|ticket|status)$',
            dest: `${backend.origin}/api/$1`,
            headers: { 'Cache-Control': 'no-store' },
        },
        { handle: 'filesystem' },
        { src: '^/(assistant|mistral-raw)/?$', dest: '/index.html' },
    ],
}, null, 2) + '\n');
console.log('Site statique et routage API générés dans .vercel/output.');
