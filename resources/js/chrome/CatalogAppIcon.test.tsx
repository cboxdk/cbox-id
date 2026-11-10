import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { CatalogAppIcon } from './CatalogAppIcon';

const app = {
    key: 'billing',
    name: 'Cbox · Billing',
    description: 'Plans and invoices',
    launch_url: 'https://billing.example.com/start',
    icon_url: null,
    icon_dark_url: null,
    position: 1,
    version: 1,
};

describe('CatalogAppIcon', () => {
    it('falls back locally when an uploaded icon fails', () => {
        const { container } = render(
            <CatalogAppIcon app={{ ...app, icon_url: 'https://id.example.com/app-icons/hash' }} />,
        );
        fireEvent.error(container.querySelector('img')!);
        expect(container.querySelector('img')).toBeNull();
        expect(screen.getByText('CB')).toBeInTheDocument();
    });
    it('carries both theme icons and lets CSS pick the painted one', () => {
        const { container } = render(
            <CatalogAppIcon
                app={{
                    ...app,
                    icon_url: 'https://id.example.com/app-icons/light',
                    icon_dark_url: 'https://id.example.com/app-icons/dark',
                }}
            />,
        );
        const images = container.querySelectorAll('img');
        expect([...images].map((image) => [image.getAttribute('src'), image.className])).toEqual([
            [
                'https://id.example.com/app-icons/light',
                expect.stringContaining('cbx-app-icon-light'),
            ],
            ['https://id.example.com/app-icons/dark', expect.stringContaining('cbx-app-icon-dark')],
        ]);
        fireEvent.error(images[1]!);
        expect(container.querySelector('img')).toBeNull();
        expect(screen.getByText('CB')).toBeInTheDocument();
    });
});
