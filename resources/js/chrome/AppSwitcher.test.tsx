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
    it('opens the inline grid by keyboard and restores focus on Escape', async () => {
        setPageProps({ appCatalog: [app] });
        const user = userEvent.setup();
        render(<AppSwitcher />);
        const trigger = screen.getByRole('button', { name: 'Switch app' });
        trigger.focus();
        await user.keyboard('{Enter}');
        const link = screen.getByRole('link', { name: 'Billing' });
        expect(link).toHaveFocus();
        expect(link).toHaveAttribute('href', app.launch_url);
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
    it('shows immediate feedback while preserving the real SSO link, and resets on Back', async () => {
        setPageProps({ appCatalog: [app] });
        render(<AppSwitcher />);
        await userEvent.click(screen.getByRole('button', { name: 'Switch app' }));
        const link = screen.getByRole('link', { name: 'Billing' });
        // Cancel jsdom navigation after the React handler, as a stalled browser navigation would.
        const cancel = (event: Event) => event.preventDefault();
        document.addEventListener('click', cancel, { once: true });
        fireEvent.click(link);
        expect(link).toHaveAttribute('aria-busy', 'true');
        expect(link).toHaveAttribute('href', app.launch_url);
        expect(screen.getByRole('status')).toHaveTextContent('Loading · Billing');
        fireEvent(window, new Event('pageshow'));
        expect(link).not.toHaveAttribute('aria-busy');
        expect(screen.getByRole('status')).toBeEmptyDOMElement();
    });
    it('does not mark the current page as navigating when opening a new tab', async () => {
        setPageProps({ appCatalog: [app] });
        render(<AppSwitcher />);
        await userEvent.click(screen.getByRole('button', { name: 'Switch app' }));
        document.addEventListener('click', (event) => event.preventDefault(), { once: true });
        fireEvent.click(screen.getByRole('link', { name: 'Billing' }), { metaKey: true });
        expect(screen.getByRole('status')).toBeEmptyDOMElement();
    });
});
