import { fileURLToPath, URL } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

/**
 * The UI layer's own tests. Separate from `vite.config.ts` on purpose: that config's job
 * is to build assets for Laravel (the laravel plugin, Tailwind, Wayfinder generation),
 * none of which a component test needs, and running Wayfinder's artisan call before every
 * `vitest` would make the fast loop slow.
 *
 * WHAT BELONGS HERE. Behaviour a primitive owns and a page cannot override — that a
 * Combobox is operable from the keyboard, that a Dialog traps focus and restores it, that
 * a Pill's variant maps to the right token. What does NOT belong here is anything that
 * needs the server: pages are covered by the Pest feature suite (props in, props out) and
 * by the Playwright suite (the page as drawn). A jsdom test asserting that a page renders
 * is a test of jsdom.
 */
export default defineConfig({
    plugins: [react()],

    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },

    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./resources/js/test/setup.ts'],
        include: ['resources/js/**/*.test.{ts,tsx}'],
        css: false,
        coverage: {
            provider: 'v8',
            include: ['resources/js/ui/**', 'resources/js/lib/**', 'resources/js/chrome/**'],
            reporter: ['text', 'html'],
        },
    },
});
