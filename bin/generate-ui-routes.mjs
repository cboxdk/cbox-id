import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { includeQueryMethod, normalizeDictionaries } from './lib/wayfinder-compat.mjs';

// Keep the upstream generator authoritative. Repair only its two known output bugs:
// identical URI dictionary entries, and Laravel's newly supported QUERY method.
const generated = spawnSync('php', ['artisan', 'wayfinder:generate', ...process.argv.slice(2)], {
    stdio: 'inherit',
});
if (generated.error) throw generated.error;
if (generated.status !== 0) process.exit(generated.status ?? 1);
for (const relative of existsSync('resources/js/actions')
    ? readdirSync('resources/js/actions', { recursive: true })
    : []) {
    if (!relative.endsWith('.ts')) continue;
    const path = join('resources/js/actions', relative);
    const source = readFileSync(path, 'utf8');
    const normalized = normalizeDictionaries(source);
    if (normalized !== source) writeFileSync(path, normalized);
}
const helper = 'resources/js/wayfinder/index.ts';
const source = readFileSync(helper, 'utf8');
writeFileSync(helper, includeQueryMethod(source));
