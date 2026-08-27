import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import { Button } from './Button';

describe('Button', () => {
    it('defaults to type=button, so a Cancel beside a Save does not submit the form', async () => {
        const submit = vi.fn<(e: React.FormEvent) => void>((e) => e.preventDefault());

        render(
            <form onSubmit={submit}>
                <Button>Cancel</Button>
            </form>,
        );

        await userEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(submit).not.toHaveBeenCalled();
    });

    it('still submits when a caller asks it to', async () => {
        const submit = vi.fn<(e: React.FormEvent) => void>((e) => e.preventDefault());

        render(
            <form onSubmit={submit}>
                <Button type="submit">Save</Button>
            </form>,
        );

        await userEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(submit).toHaveBeenCalledOnce();
    });

    it('cannot be clicked twice while the first press is in flight', async () => {
        const onClick = vi.fn<() => void>();

        render(
            <Button loading onClick={onClick}>
                Save
            </Button>,
        );

        const button = screen.getByRole('button', { name: 'Save' });

        await userEvent.click(button);

        expect(onClick).not.toHaveBeenCalled();
        expect(button).toBeDisabled();
        expect(button).toHaveAttribute('aria-busy', 'true');
    });

    it('keeps its label while loading, so the target does not move under the cursor', () => {
        const { rerender } = render(<Button>Save changes</Button>);
        rerender(<Button loading>Save changes</Button>);

        expect(screen.getByRole('button', { name: 'Save changes' })).toBeInTheDocument();
    });

    it('renders a real anchor when it navigates, so it can be opened in a new tab', () => {
        render(
            <Button asChild variant="primary">
                <a href="/dashboard">Go to the dashboard</a>
            </Button>,
        );

        const link = screen.getByRole('link', { name: 'Go to the dashboard' });

        expect(link).toHaveClass('btn', 'btn-primary');
        expect(link).not.toHaveAttribute('type');
    });

    it('marks a disabled anchor aria-disabled, since an anchor has no disabled state', () => {
        render(
            <Button asChild disabled>
                <a href="/dashboard">Unavailable</a>
            </Button>,
        );

        expect(screen.getByRole('link')).toHaveAttribute('aria-disabled', 'true');
    });

    it('takes its accessible name from the label, never from the icon', () => {
        render(<Button icon="plus">Add member</Button>);

        expect(screen.getByRole('button', { name: 'Add member' })).toBeInTheDocument();
    });

    it('has no accessibility violations across every variant', async () => {
        const { container } = render(
            <>
                <Button variant="primary">Primary</Button>
                <Button variant="secondary">Secondary</Button>
                <Button variant="ghost" icon="refresh">
                    Ghost
                </Button>
                <Button variant="danger" loading>
                    Danger
                </Button>
            </>,
        );

        await expect(container).toHaveNoAxeViolations();
    });
});
