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
});
