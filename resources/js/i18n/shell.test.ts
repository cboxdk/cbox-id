import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { SHELL_MESSAGES } from './shell';

/**
 * cbox-ui's `CboxShell` asks for every line in English through `t("…")`. A line with no
 * entry in SHELL_MESSAGES stays English on every page, so a cbox-ui bump that brings new
 * words ("Continue the conversation" in 0.15.3) must bring them to lang/{locale}/shell.php.
 */
describe('the shell catalogue', () => {
    it('has a line for every word the installed cbox-ui asks for', () => {
        const dist = join(process.cwd(), 'node_modules/@cboxdk/cbox-ui/dist');
        const bundle = readdirSync(dist)
            .filter((file) => file.endsWith('.js'))
            .map((file) => readFileSync(join(dist, file), 'utf8'))
            .join('\n');
        const asked = new Set<string>();

        // The bundle writes non-ASCII as \xB7 and \u2026, which JSON does not read.
        const unescape = (literal: string) =>
            literal.replace(/\\(?:x([0-9a-f]{2})|u([0-9a-f]{4})|(.))/gi, (_, x, u, c) =>
                x || u ? String.fromCharCode(parseInt(x ?? u, 16)) : c,
            );

        for (const [, literal] of bundle.matchAll(/\bt\(\s*"((?:[^"\\]|\\.)*)"/g)) {
            asked.add(unescape(literal ?? ''));
        }

        expect(asked.size).toBeGreaterThan(100);
        expect([...asked].filter((line) => !(line in SHELL_MESSAGES))).toEqual([]);
    });
});
