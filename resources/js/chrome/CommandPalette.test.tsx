import { router } from '@inertiajs/react';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { SearchResponse } from '@/lib/consoleSearch';
import { setPageProps } from '@/test/page';
import type { NavArea, Shell } from '@/types';
import { CommandPalette } from './CommandPalette';

const AREAS: NavArea[] = [
    {
        key: 'developers',
        label: 'Developers',
        icon: 'code',
        href: '/admin/apps',
        active: false,
        current: false,
        pages: [
            {
                route: 'environment.clients',
                href: '/admin/apps',
                label: 'Applications',
                active: false,
            },
        ],
    },
] as NavArea[];

function answer(query: string): SearchResponse {
    const ada = {
        kind: 'user' as const,
        id: 'usr1',
        title: 'Ada Lovelace',
        subtitle: 'ada@acme.test',
        href: '/admin/users/usr1',
    };

    return {
        query,
        jump: query === 'usr1' ? ada : null,
        groups: query === '' ? [] : [{ key: 'users', label: 'Users', items: [ada] }],
        actions: [{ name: 'apps.create', label: 'Create app', href: '/admin/apps/new' }],
    };
}

describe('CommandPalette', () => {
    let fetchMock: ReturnType<typeof vi.fn<(url: string) => Promise<Response>>>;

    beforeEach(() => {
        setPageProps({
            shell: {
                altitude: 'environment',
                platformMode: false,
                areas: AREAS,
            } as unknown as Shell,
        });
        fetchMock = vi.fn<(url: string) => Promise<Response>>(async (url: string) => {
            const query = new URL(url, 'https://acme.cboxid.com').searchParams.get('q') ?? '';

            return new Response(JSON.stringify(answer(query)), { status: 200 });
        });
        vi.stubGlobal('fetch', fetchMock);
        window.localStorage.clear();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it("opens on ⌘K as a combobox, with Go to and the registry's actions", async () => {
        render(<CommandPalette areas={AREAS} />);

        await userEvent.keyboard('{Meta>}k{/Meta}');

        const input = await screen.findByRole('combobox');
        expect(input).toHaveFocus();
        expect(screen.getByRole('listbox')).toBeInTheDocument();
        expect(screen.getByText('Applications')).toBeInTheDocument();
        expect(await screen.findByText('Create app')).toBeInTheDocument();
        expect(fetchMock).toHaveBeenCalledWith('/admin/search?q=', expect.anything());
    });

    it('waits for typing to pause, then shows what the server found', async () => {
        render(<CommandPalette areas={AREAS} />);
        await userEvent.click(screen.getByRole('button', { name: 'Search the console' }));

        await userEvent.type(await screen.findByRole('combobox'), 'ada');

        expect(await screen.findByText('Ada Lovelace')).toBeVisible();
        // One request for the actions on open, one for the pause — not one per keystroke.
        expect(fetchMock.mock.calls.map(([url]) => url)).toEqual([
            '/admin/search?q=',
            '/admin/search?q=ada',
        ]);
    });

    it('jumps to the record a pasted id names on Enter, and remembers it', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);

        render(<CommandPalette areas={AREAS} />);
        await userEvent.click(screen.getByRole('button', { name: 'Search the console' }));
        await userEvent.type(await screen.findByRole('combobox'), 'usr1');

        expect(await screen.findByText('Open Ada Lovelace', { exact: false })).toBeInTheDocument();

        await act(async () => {
            await userEvent.keyboard('{Enter}');
        });

        await waitFor(() => expect(visit.mock.calls).toEqual([['/admin/users/usr1']]));
        expect(window.localStorage.getItem(`cbx.palette.recent:${window.location.host}`)).toContain(
            'usr1',
        );
    });

    it('never sends a pasted key whole', async () => {
        render(<CommandPalette areas={AREAS} />);
        await userEvent.click(screen.getByRole('button', { name: 'Search the console' }));
        await userEvent.type(await screen.findByRole('combobox'), 'cbid_env_ab12SECRETSECRET');

        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(String(fetchMock.mock.calls[1]?.[0])).toBe('/admin/search?q=cbid_env_ab12');
    });

    it('stays navigation-only where the console has no search', async () => {
        setPageProps({
            shell: {
                altitude: 'environment',
                platformMode: true,
                areas: AREAS,
            } as unknown as Shell,
        });
        render(<CommandPalette areas={AREAS} />);

        await userEvent.click(screen.getByRole('button', { name: 'Search the console' }));

        expect(await screen.findByText('Applications')).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    /*
     * The words a person arriving from another platform types. "SAML" used to match only
     * "SAML apps" — the OUTBOUND direction — so the reader setting up a customer's Okta was
     * offered the one page that is not it; "tenant", "SCIM" and "Google login" matched
     * nothing at all.
     */
    it('finds a page by the words people type for it, not only by its label', async () => {
        const areas = [
            {
                key: 'Authentication',
                label: 'Authentication',
                icon: 'fingerprint',
                href: '/admin/sign-in-methods',
                active: false,
                current: false,
                pages: [
                    {
                        route: 'environment.connections',
                        href: '/admin/single-sign-on',
                        label: 'Enterprise SSO',
                        active: false,
                        keywords: ['SSO', 'SAML', 'OIDC', 'Okta'],
                    },
                    {
                        route: 'environment.directories',
                        href: '/admin/sync-in',
                        label: 'Directory Sync',
                        active: false,
                        keywords: ['SCIM', 'HR system', 'Workday'],
                    },
                    {
                        route: 'environment.social-providers',
                        href: '/admin/social-sign-in',
                        label: 'Social login',
                        active: false,
                        keywords: ['Google login', 'GitHub login'],
                    },
                ],
            },
            {
                key: 'Users & orgs',
                label: 'Users & orgs',
                icon: 'members',
                href: '/admin/users',
                active: false,
                current: false,
                pages: [
                    {
                        route: 'environment.organizations',
                        href: '/admin/organizations',
                        label: 'Organizations',
                        active: false,
                        keywords: ['tenants', 'tenant', 'customers'],
                    },
                ],
            },
            {
                key: 'Advanced',
                label: 'Advanced',
                icon: 'sliders',
                href: '/admin/staff',
                active: false,
                current: false,
                pages: [
                    {
                        route: 'environment.sso-providers',
                        href: '/admin/saml-apps',
                        label: 'SAML apps',
                        active: false,
                        keywords: ['SAML IdP', 'outbound SAML'],
                    },
                ],
            },
        ] as NavArea[];

        // Navigation only, so the server's results cannot be what matched.
        setPageProps({
            shell: { altitude: 'environment', platformMode: true, areas } as unknown as Shell,
        });

        render(<CommandPalette areas={areas} />);
        await userEvent.keyboard('{Meta>}k{/Meta}');
        const input = await screen.findByRole('combobox');

        const options = (): string[] =>
            screen.getAllByRole('option').map((option) => option.textContent ?? '');

        await userEvent.type(input, 'tenant');
        await waitFor(() => expect(options()[0]).toContain('Organizations'));

        await userEvent.clear(input);
        await userEvent.type(input, 'SCIM');
        await waitFor(() => expect(options()[0]).toContain('Directory Sync'));

        await userEvent.clear(input);
        await userEvent.type(input, 'Google login');
        await waitFor(() => expect(options()[0]).toContain('Social login'));

        // Both match "SAML"; the inbound direction — the one nine readers in ten mean — first.
        await userEvent.clear(input);
        await userEvent.type(input, 'SAML');
        await waitFor(() => expect(options()[0]).toContain('Enterprise SSO'));
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
