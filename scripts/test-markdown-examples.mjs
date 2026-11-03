import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { runInNewContext } from 'node:vm';

const view = await readFile(new URL('../resources/views/mistral-raw.blade.php', import.meta.url), 'utf8');
const start = view.indexOf('    function normalizeMarkdownExamples(');
const end = view.indexOf('    function renderMarkdown(', start);
assert(start >= 0 && end > start);
const normalize = runInNewContext(view.slice(start, end) + '\nnormalizeMarkdownExamples;');
let cases = 0;
for (const fence of ['```', '~~~']) {
    for (const indent of ['', '   ', '     ']) {
        for (const suffix of [' [4]', ' [4][5] (valeurs documentaires).']) {
            const input = `1. Recevez la notification.\n\n2. Accusé :\n\n${indent}${fence}json\n${indent}{"responseCode":200}\n${fence}${suffix}\n\n3. Suite de la procédure.`;
            const expected = `1. Recevez la notification.\n\n2. Accusé :\n\n${indent}${fence}json\n${indent}{"responseCode":200}\n${indent}${fence}\n\n${indent}${suffix.trim()}\n\n3. Suite de la procédure.`;
            const actual = normalize(input);
            assert.equal(actual, expected);
            assert.equal(normalize(actual), actual);
            cases++;
        }
    }
}
for (const text of ['Un texte sans exemple.', 'La valeur `responseCode` reste inchangée.', '```json\n{"url":"https://docs.mypvit.pro/fr/","value":"[4]"}\n```']) {
    assert.equal(normalize(text), text);
    cases++;
}
console.log(`Normalisation du rendu vérifiée sur ${cases} cas, sans modification du JSON.`);
