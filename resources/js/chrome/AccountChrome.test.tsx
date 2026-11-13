import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { accountAreas } from '@/layouts/AccountLayout';
import { AccountMenu } from './AccountMenu';
import { BackLink } from './BackLink';

const inertia = vi.hoisted(() => ({
    post: vi.fn<(url: string, data: Record<string, unknown>) => void>(),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            i18n: {
                locale: 'en',
                locales: [
                    { code: 'en', name: 'English' },
                    { code: 'da', name: 'Dansk' },
                ],
                messages: {
                    'console.account_menu_part1_18b175d9': 'Account menu for :part1',
                    'console.account_7e1b0d56': 'Account',
                    'console.language': 'Language',
                    'console.back_to_part1_dc732693': 'Back to :part1',
                },
                preference: { subjectId: 'sub_1', version: 3, updateUrl: '/interface-language' },
            },
        },
    }),
    useForm: () => ({ post: vi.fn<() => void>() }),
    router: { post: inertia.post },
    Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

beforeEach(() => {
    vi.clearAllMocks();
});

describe('BackLink', () => {
    it('names the app the person came from and leads to the address the server validated', () => {
        render(<BackLink back={{ url: 'https://billing.example/invoices', label: 'Billing' }} />);

        const link = screen.getByRole('link', { name: /Billing/ });

        expect(link).toHaveAttribute('href', 'https://billing.example/invoices');
    });
});

describe('accountAreas', () => {
    it("makes each of the person's pages a rail entry, with its own icon", () => {
        const areas = accountAreas([
            { route: 'account', label: 'Security', href: '/settings', active: true },
            {
                route: 'devices.mine',
                label: 'Trusted devices',
                href: '/settings/devices',
                active: false,
            },
            { route: 'module.page', label: 'Something new', href: '/settings/new', active: false },
        ]);

        expect(areas.map((area) => [area.key, area.icon, area.active])).toEqual([
            ['account', 'shield', true],
            ['devices.mine', 'device', false],
            ['module.page', 'user', false],
        ]);
        expect(areas.every((area) => area.pages.length === 0)).toBe(true);
    });
});

describe('AccountMenu', () => {
    const user = {
        id: 'sub_1',
        name: 'Pat Doe',
        email: 'pat@example.com',
    } as Parameters<typeof AccountMenu>[0]['user'];

    it('holds the language, unfolded in place, and saves the one chosen', async () => {
        render(
            <AccountMenu
                user={user}
                logoutUrl="/logout"
                organizationSettingsHref={null}
                accountHref={null}
                switchUserHref="/settings/accounts"
                platformHref={null}
                withLanguage
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: /Pat Doe/ }));

        expect(screen.queryByRole('menuitem', { name: /Account$/ })).toBeNull();

        await userEvent.click(screen.getByRole('menuitem', { name: /Language/ }));
        await userEvent.click(await screen.findByRole('menuitemradio', { name: 'Dansk' }));

        expect(inertia.post).toHaveBeenCalledWith(
            '/interface-language',
            { language: 'da', subject_id: 'sub_1', version: 3 },
            expect.anything(),
        );
    });

    it('leaves the language out where a picker stands beside it', async () => {
        render(
            <AccountMenu
                user={user}
                logoutUrl="/logout"
                organizationSettingsHref={null}
                accountHref="/settings"
                switchUserHref="/settings/accounts"
                platformHref={null}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: /Pat Doe/ }));

        expect(screen.queryByRole('menuitem', { name: /Language/ })).toBeNull();
    });
});
