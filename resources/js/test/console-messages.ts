import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';

// Load the real source catalog for UI tests; no second hand-maintained English dictionary.
const source = resolve(process.cwd(), 'lang/en/console.php');
const lines = JSON.parse(
    execFileSync('php', ['-r', 'echo json_encode(require $argv[1]);', source], {
        encoding: 'utf8',
    }),
) as Record<string, string>;
export const consoleMessages = Object.fromEntries(
    Object.entries(lines).map(([key, value]) => [`console.${key}`, value]),
);
