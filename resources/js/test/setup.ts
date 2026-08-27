import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, expect, vi } from 'vitest';
import axe from 'axe-core';
import { pageProps, resetPageProps } from './page';

afterEach(() => {
    cleanup();
    resetPageProps();
});

/**
 * A PAGE, for the primitives that read one.
 *
 * `usePage` reaches for Inertia's React context, which only exists under a mounted
 * `createInertiaApp` — and mounting the whole app to assert that a checkbox toggles would
 * be testing Inertia. Everything else in the module is the real thing: only the page
 * itself is supplied. See {@see ./page.ts} for the fixture and how a test changes it.
 */
vi.mock('@inertiajs/react', async (importActual) => ({
    ...(await importActual<typeof import('@inertiajs/react')>()),
    usePage: () => ({
        props: pageProps(),
        component: 'test',
        url: '/',
        version: null,
    }),
}));

/**
 * jsdom has no layout engine, so a handful of browser APIs the Radix primitives call
 * during mount simply do not exist there. Stubbing them is not papering over a bug: the
 * behaviour these tests assert — focus order, roles, keyboard operation — does not depend
 * on measurement, and the real geometry is covered by the Playwright suite against a real
 * browser.
 */
if (!window.matchMedia) {
    Object.defineProperty(window, 'matchMedia', {
        writable: true,
        value: (query: string): MediaQueryList =>
            ({
                matches: false,
                media: query,
                onchange: null,
                addListener: vi.fn<() => void>(),
                removeListener: vi.fn<() => void>(),
                addEventListener: vi.fn<() => void>(),
                removeEventListener: vi.fn<() => void>(),
                dispatchEvent: vi.fn<() => void>(),
            }) as unknown as MediaQueryList,
    });
}

if (!window.ResizeObserver) {
    window.ResizeObserver = class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
    };
}

if (!Element.prototype.scrollIntoView) {
    Element.prototype.scrollIntoView = vi.fn<() => void>();
}

if (!Element.prototype.hasPointerCapture) {
    Element.prototype.hasPointerCapture = (): boolean => false;
    Element.prototype.setPointerCapture = vi.fn<() => void>();
    Element.prototype.releasePointerCapture = vi.fn<() => void>();
}

/**
 * `expect(container).toHaveNoAxeViolations()` — an accessibility assertion any primitive's
 * test can make in one line, so a11y is part of writing the component rather than a sweep
 * somebody runs later.
 *
 * `color-contrast` is off because jsdom cannot compute it (no layout, no cascade). It is
 * NOT thereby unchecked: contrast is a property of the token palette, and the Playwright
 * axe run exercises it in a real browser in both themes.
 */
expect.extend({
    async toHaveNoAxeViolations(received: Element) {
        const results = await axe.run(received, {
            runOnly: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'],
            rules: { 'color-contrast': { enabled: false } },
        });

        if (results.violations.length === 0) {
            return { pass: true, message: (): string => 'expected accessibility violations' };
        }

        const detail = results.violations
            .map((v) => `  ${v.id} (${v.impact}) — ${v.help}\n    ${v.nodes[0]?.html ?? ''}`)
            .join('\n');

        return {
            pass: false,
            message: (): string => `expected no accessibility violations, found:\n${detail}`,
        };
    },
});

declare module 'vitest' {
    // `T = any` rather than `unknown`, because a declaration-merged interface must repeat
    // the host's type parameters exactly — @vitest/expect declares `Matchers<T = any>`.
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    interface Matchers<T = any> {
        toHaveNoAxeViolations: () => Promise<T>;
    }
}
