import type { SharedProps } from '@/types';

/**
 * THE SHARED PROPS A COMPONENT TEST RUNS AGAINST.
 *
 * Several primitives read the page — `<ConfirmDelete>` names the environment, `<Brand>`
 * reads the product name, `<PageHeader>` takes its eyebrow from the nav registry — and
 * they read it rather than taking it as an argument on purpose: a value each page has to
 * remember to pass is a value some page will not pass.
 *
 * Which means a component test needs a page to exist. This is that page: a plausible
 * default, mutable per test through {@see setPageProps}, and reset between tests.
 */
export const DEFAULT_PAGE_PROPS: SharedProps = {
    app: {
        name: 'Cbox ID',
        tagline: 'One identity layer for every app you ship.',
        trustLine: '',
        year: '2026',
    },
    theme: null,
    auth: { user: null, organization: null },
    brand: null,
    environment: { name: 'production', type: 'production', sandbox: false },
    impersonation: null,
    flash: { status: null, error: null },
    shell: null,
    errors: {},
};

let current: SharedProps = { ...DEFAULT_PAGE_PROPS };

export function pageProps(): SharedProps {
    return current;
}

export function setPageProps(overrides: Partial<SharedProps>): void {
    current = { ...current, ...overrides };
}

export function resetPageProps(): void {
    current = { ...DEFAULT_PAGE_PROPS };
}
