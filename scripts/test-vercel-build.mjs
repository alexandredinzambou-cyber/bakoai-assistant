import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFile, readdir, unlink } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import { Script } from 'node:vm';

const root = fileURLToPath(new URL('../', import.meta.url));
const build = (origin) => {
    const env = { ...process.env };
    if (origin === undefined) delete env.BACKEND_ORIGIN;
    else env.BACKEND_ORIGIN = origin;
    return spawnSync(process.execPath, ['scripts/build-vercel.mjs'], { cwd: root, env, encoding: 'utf8' });
};
for (const invalid of [undefined, '', 'http://backend.example', 'https://user:secret@backend.example',
    'https://backend.example/api', 'https://backend.example/?key=secret', 'https://backend.example/#fragment']) {
    assert.notEqual(build(invalid).status, 0, `Origine invalide acceptée : ${invalid}`);
}

const result = build('https://backend-build-check.example');
assert.equal(result.status, 0, result.stderr);
const staticDir = path.join(root, '.vercel/output/static');
const html = await readFile(path.join(staticDir, 'index.html'), 'utf8');
assert(!html.includes('{{'));
assert(html.includes('const API_BASE = \'\''));
assert(html.includes("const AVATAR_URL = '/bako1.png'"));
for (const match of html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)) {
    new Script(match[1]);
}
assert.deepEqual((await readdir(staticDir)).sort(), ['bako1.png', 'favicon.ico', 'index.html', 'robots.txt']);
const configPath = path.join(root, '.vercel/output/config.json');
const config = JSON.parse(await readFile(configPath, 'utf8'));
assert.equal(config.version, 3);
const apiRoute = config.routes[0];
const pattern = new RegExp(apiRoute.src);
for (const endpoint of ['mistral-raw/ask', 'ask', 'ticket', 'status']) {
    const url = `/api/${endpoint}`;
    assert(pattern.test(url));
    assert.equal(url.replace(pattern, apiRoute.dest), `https://backend-build-check.example/api/${endpoint}`);
}
assert(!pattern.test('/api/knowledge/sources'));
assert(!pattern.test('/api/status/extra'));
assert.equal(apiRoute.headers['Cache-Control'], 'no-store');
// A test destination must never remain deployable through --prebuilt.
await unlink(configPath);
console.log('Export Vercel vérifié : URL, JavaScript, assets publics et routage API.');
