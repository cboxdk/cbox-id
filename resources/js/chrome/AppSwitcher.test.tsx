import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { setPageProps } from '@/test/page';
import { AppSwitcher, CatalogAppIcon } from './AppSwitcher';

const app = {
    key: 'billing',
    name: 'Cbox · Billing',
    description: 'Plans and invoices',
    launch_url: 'https://billing.example.com/start',
    icon_url: null,
    position: 1,
    version: 1,
};

describe('AppSwitcher', () => {
    it('opens by keyboard, searches real app links, and restores focus on Escape', async () => {
        setPageProps({ appCatalog: [app] });
        const user = userEvent.setup();
        render(<AppSwitcher compact />);
        const trigger = screen.getByRole('button', { name: 'Switch app' });
        trigger.focus();
        await user.keyboard('{Enter}');
        expect(screen.getByRole('textbox', { name: 'Search apps' })).toHaveFocus();
        expect(screen.getByRole('link', { name: /Cbox · Billing/ })).toHaveAttribute(
            'href',
            app.launch_url,
        );
        await user.type(screen.getByRole('textbox'), 'missing');
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByText('No apps match your search.')).toBeVisible();
        await user.keyboard('{Escape}');
        expect(trigger).toHaveFocus();
    });
    it('shows an honest empty state on mobile too', async () => {
        const user = userEvent.setup();
        render(<AppSwitcher />);
        await user.click(screen.getByRole('button', { name: 'Switch app' }));
        expect(screen.getByText('No apps have been published for you yet.')).toBeVisible();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
    it('falls back locally when an uploaded icon fails', () => {
        const { container } = render(
            <CatalogAppIcon app={{ ...app, icon_url: 'https://id.example.com/app-icons/hash' }} />,
        );
        fireEvent.error(container.querySelector('img')!);
        expect(container.querySelector('img')).toBeNull();
        expect(screen.getByText('CB')).toBeInTheDocument();
    });
});
