import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.tsx',
                // TRANSITIONAL. The Volt layouts still @vite this one, and they still
                // serve most of the console until the last page is ported. It goes when
                // they do — see the Livewire removal phase; nothing in app.tsx imports it.
                'resources/js/app.js',
            ],
            refresh: [
                // The Inertia root view and the few blade surfaces that survive it
                // (mail, errors, first-paint shells). Page markup lives in .tsx and is
                // handled by React Fast Refresh, not by a full reload.
                'resources/views/**',
                'routes/**',
                'app/Http/Middleware/HandleInertiaRequests.php',
            ],
        }),

        react(),

        tailwindcss(),

        // Typed route helpers and form actions generated from routes/web.php. The point
        // is that a renamed or removed route becomes a TypeScript error rather than a
        // 404 somebody finds in production: nothing in resources/js spells a URL.
        wayfinder({
            formVariants: true,
        }),
    ],

    // Mirrors `paths` in tsconfig.json. Both are needed and neither implies the other:
    // TypeScript resolves the import for the checker, Vite resolves it for the bundle.
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            // Wayfinder's generated trees. `@routes` is the undotted top-level index;
            // `@routes/x` is the tree for the `x.*` names.
            '@routes': fileURLToPath(new URL('./resources/js/routes/index.ts', import.meta.url)),
        },
    },

    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },

    // No manualChunks. Every page is a dynamic import (`import.meta.glob` in app.tsx), so
    // Rollup already emits one chunk per page plus a shared vendor chunk — which is the
    // split that matters. Naming vendor chunks by hand produced an EMPTY `react` chunk,
    // because React is reached through @inertiajs/react and had already been placed.
});
