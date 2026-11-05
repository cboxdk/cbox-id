import { render } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Toaster } from './Toaster';

const state = vi.hoisted(() => ({ flash: {} as Record<string, unknown> }));
const notifications = vi.hoisted(() => ({
    success: vi.fn<(message: string) => void>(),
    error: vi.fn<(message: string, options: { duration: number }) => void>(),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        flash: state.flash,
        props: { flash: { status: 'An old success from history', error: null } },
    }),
}));
vi.mock('sonner', () => ({ Toaster: () => null, toast: notifications }));

beforeEach(() => {
    state.flash = {};
    vi.clearAllMocks();
});

describe('Toaster', () => {
    it('does not replay historical shared props when native flash is empty', () => {
        render(<Toaster />);
        expect(notifications.success).not.toHaveBeenCalled();
        expect(notifications.error).not.toHaveBeenCalled();
    });

    it('announces each fresh save, including identical confirmations, once', () => {
        state.flash = { status: 'Profile updated.', error: null };
        const { rerender } = render(<Toaster />);
        rerender(<Toaster />);
        expect(notifications.success).toHaveBeenCalledTimes(1);

        state.flash = { status: 'Profile updated.', error: null };
        rerender(<Toaster />);
        expect(notifications.success).toHaveBeenCalledTimes(2);

        state.flash = {};
        rerender(<Toaster />);
        expect(notifications.success).toHaveBeenCalledTimes(2);
    });

    it('prioritizes an error and leaves enough time to read it', () => {
        state.flash = { status: 'Saved', error: 'Could not save' };
        render(<Toaster />);
        expect(notifications.error).toHaveBeenCalledWith('Could not save', { duration: 8000 });
        expect(notifications.success).not.toHaveBeenCalled();
    });
});
