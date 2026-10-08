import { router } from '@inertiajs/react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { ContextEnvironment, ContextProject, ShellContext } from '@/types';
import { ContextSwitcher, counterpart, SEARCH_THRESHOLD } from './ContextSwitcher';

function environment(id: string, name: string, type: string, current = false): ContextEnvironment {
    return { id, name, type, current, href: `https://cboxid.com/open/${id}?to=%2Fadmin%2Fusers` };
}

function project(id: string, name: string, environments: ContextEnvironment[]): ContextProject {
    return { id, name, current: environments.some((candidate) => candidate.current), environments };
}

/** The environment console of Acme's Checkout, standing on production. */
function environmentConsole(overrides: Partial<ShellContext> = {}): ShellContext {
    return {
        noun: 'Workspace',
        workspaces: [
            {
                id: 'w1',
                label: 'Acme',
                caption: 'Owner',
                current: true,
                openHref: 'https://cboxid.com/projects',
            },
        ],
        switchUrl: null,
        projects: [
            project('p1', 'Checkout', [
                environment('e1', 'Production', 'production', true),
                environment('e2', 'Sandbox', 'sandbox'),
            ]),
            project('p2', 'Billing', [
                environment('e3', 'Sandbox', 'sandbox'),
                environment('e4', 'Production', 'production'),
            ]),
        ],
        ...overrides,
    };
}

describe('ContextSwitcher', () => {
    it('names where you are: workspace, project, environment and its type', () => {
        render(
            <ContextSwitcher
                context={environmentConsole()}
                onNavigate={vi.fn<(href: string) => void>()}
            />,
        );

        const nav = screen.getByRole('navigation', { name: 'Context' });

        expect(within(nav).getByRole('button', { name: /Workspace: Acme/ })).toBeInTheDocument();
        expect(within(nav).getByRole('button', { name: /Project: Checkout/ })).toBeInTheDocument();
        expect(
            within(nav).getByRole('button', { name: /Environment: Production/ }),
        ).toBeInTheDocument();
        // The word, not only the tint — colour alone is not an indicator.
        expect(within(nav).getByText('PRODUCTION')).toBeInTheDocument();
    });

    it('opens another environment of the project by following its link', async () => {
        const onNavigate = vi.fn<(href: string) => void>();
        render(<ContextSwitcher context={environmentConsole()} onNavigate={onNavigate} />);

        await userEvent.click(screen.getByRole('button', { name: /Environment: Production/ }));
        await userEvent.click(screen.getByRole('option', { name: /Sandbox/ }));

        expect(onNavigate).toHaveBeenCalledWith('https://cboxid.com/open/e2?to=%2Fadmin%2Fusers');
    });

    it('does nothing when the current environment is chosen', async () => {
        const onNavigate = vi.fn<(href: string) => void>();
        render(<ContextSwitcher context={environmentConsole()} onNavigate={onNavigate} />);

        await userEvent.click(screen.getByRole('button', { name: /Environment: Production/ }));
        await userEvent.click(screen.getByRole('option', { name: /Production/ }));

        expect(onNavigate).not.toHaveBeenCalled();
    });

    it('opens the matching environment of another project — production for production', async () => {
        const onNavigate = vi.fn<(href: string) => void>();
        render(<ContextSwitcher context={environmentConsole()} onNavigate={onNavigate} />);

        await userEvent.click(screen.getByRole('button', { name: /Project: Checkout/ }));
        await userEvent.click(screen.getByRole('option', { name: /Billing/ }));

        // Billing lists Sandbox first; the counterpart of Production is still Production.
        expect(onNavigate).toHaveBeenCalledWith('https://cboxid.com/open/e4?to=%2Fadmin%2Fusers');
    });

    it('switches workspace with a POST where the session lives, and by a link from elsewhere', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
        const workspaces = [
            { id: 'w1', label: 'Acme', caption: 'Owner', current: true, openHref: null },
            { id: 'w2', label: 'Globex', caption: 'Developer', current: false, openHref: null },
        ];

        render(
            <ContextSwitcher
                context={environmentConsole({
                    workspaces,
                    switchUrl: '/organization/switch',
                    projects: [],
                })}
                onNavigate={vi.fn<(href: string) => void>()}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: /Workspace: Acme/ }));
        await userEvent.click(screen.getByRole('option', { name: /Globex/ }));

        expect(post).toHaveBeenCalledWith('/organization/switch', { organization: 'w2' });
        post.mockRestore();
    });

    it('is a label, not a menu, when there is nowhere to go', () => {
        render(
            <ContextSwitcher
                context={{
                    noun: 'Organization',
                    workspaces: [
                        {
                            id: 'o1',
                            label: 'Plain Co',
                            caption: 'Owner',
                            current: true,
                            openHref: null,
                        },
                    ],
                    switchUrl: '/organization/switch',
                    projects: [],
                }}
                onNavigate={vi.fn<(href: string) => void>()}
            />,
        );

        expect(screen.getByText('Plain Co')).toBeInTheDocument();
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('groups every environment by project on a workspace console, where none is current', async () => {
        const context = environmentConsole();
        context.projects = context.projects.map((candidate) => ({
            ...candidate,
            current: false,
            environments: candidate.environments.map((row) => ({ ...row, current: false })),
        }));

        render(<ContextSwitcher context={context} onNavigate={vi.fn<(href: string) => void>()} />);

        await userEvent.click(screen.getByRole('button', { name: 'Open an environment' }));

        expect(screen.getByText('Checkout')).toBeInTheDocument();
        expect(screen.getByText('Billing')).toBeInTheDocument();
        expect(screen.getAllByRole('option')).toHaveLength(4);
    });

    it('offers a search only past the threshold', async () => {
        const many = Array.from({ length: SEARCH_THRESHOLD + 1 }, (_, i) => ({
            id: `w${i}`,
            label: `Workspace ${i}`,
            caption: null,
            current: i === 0,
            openHref: null,
        }));

        render(
            <ContextSwitcher
                context={environmentConsole({
                    workspaces: many,
                    switchUrl: '/organization/switch',
                    projects: [],
                })}
                onNavigate={vi.fn<(href: string) => void>()}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: /Workspace: Workspace 0/ }));
        await userEvent.type(screen.getByRole('combobox'), 'Workspace 7');

        expect(screen.getAllByRole('option')).toHaveLength(1);
    });

    it('has no search for a short list', async () => {
        render(
            <ContextSwitcher
                context={environmentConsole()}
                onNavigate={vi.fn<(href: string) => void>()}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: /Environment: Production/ }));

        expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
    });

    it('passes an accessibility audit', async () => {
        const { container } = render(
            <ContextSwitcher
                context={environmentConsole()}
                onNavigate={vi.fn<(href: string) => void>()}
            />,
        );

        await expect(container).toHaveNoAxeViolations();
    });
});

describe('counterpart', () => {
    it('prefers the same name, then the same type, then the first', () => {
        const here = environment('a', 'Staging', 'sandbox', true);

        expect(
            counterpart(
                project('p', 'P', [
                    environment('x', 'Production', 'production'),
                    environment('y', 'Staging', 'sandbox'),
                ]),
                here,
            )?.id,
        ).toBe('y');
        expect(
            counterpart(
                project('p', 'P', [
                    environment('x', 'Production', 'production'),
                    environment('y', 'QA', 'sandbox'),
                ]),
                here,
            )?.id,
        ).toBe('y');
        expect(
            counterpart(project('p', 'P', [environment('x', 'Production', 'production')]), here)
                ?.id,
        ).toBe('x');
    });
});
