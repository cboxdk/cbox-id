import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { I18n } from '@/types';
import { AppErrorBoundary } from './AppErrorBoundary';

vi.mock('@inertiajs/react', () => ({ router: { on: vi.fn<() => () => void>(() => () => {}) } }));

function BrokenPage(): never {
    throw new Error('Render failed');
}

describe('root error boundary localization', () => {
    afterEach(() => vi.restoreAllMocks());

    it('renders translated recovery actions outside the Inertia page context', () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        const i18n: I18n = {
            locale: 'da',
            locales: [],
            preference: null,
            messages: {
                'errors.layout.default_heading': 'Noget gik galt',
                'errors.layout.default_message': 'Der opstod en uventet fejl. Prøv igen.',
                'errors.layout.reload': 'Genindlæs',
                'errors.layout.home': 'Tilbage til oversigten',
            },
        };
        render(
            <AppErrorBoundary i18n={i18n}>
                <BrokenPage />
            </AppErrorBoundary>,
        );
        expect(screen.getByRole('heading', { name: 'Noget gik galt' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Genindlæs' })).toBeVisible();
        expect(screen.getByRole('link', { name: 'Tilbage til oversigten' })).toHaveAttribute(
            'href',
            '/',
        );
    });
});
