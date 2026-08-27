import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { Button } from './Button';
import { Dialog } from './Dialog';

function Harness() {
    const [open, setOpen] = useState(false);

    return (
        <>
            <button type="button" onClick={() => setOpen(true)}>
                Delete endpoint
            </button>
            <button type="button">Behind the dialog</button>

            <Dialog
                open={open}
                onOpenChange={setOpen}
                title="Delete this endpoint?"
                description="Deliveries stop immediately and the signing secret is destroyed."
                footer={
                    <>
                        <Button onClick={() => setOpen(false)}>Cancel</Button>
                        <Button variant="danger">Delete</Button>
                    </>
                }
            >
                <label htmlFor="confirm">Type the name to confirm</label>
                <input id="confirm" />
            </Dialog>
        </>
    );
}

describe('Dialog', () => {
    it('is announced by its title and its description, not by its title alone', async () => {
        render(<Harness />);
        await userEvent.click(screen.getByRole('button', { name: 'Delete endpoint' }));

        const dialog = screen.getByRole('dialog');

        expect(dialog).toHaveAccessibleName('Delete this endpoint?');
        expect(dialog).toHaveAccessibleDescription(
            'Deliveries stop immediately and the signing secret is destroyed.',
        );
    });

    it('traps Tab inside itself, which the Alpine version never did', async () => {
        render(<Harness />);
        await userEvent.click(screen.getByRole('button', { name: 'Delete endpoint' }));

        const inside = [
            screen.getByRole('button', { name: 'Close' }),
            screen.getByLabelText('Type the name to confirm'),
            screen.getByRole('button', { name: 'Cancel' }),
            screen.getByRole('button', { name: 'Delete' }),
        ];

        // Walk one full cycle and a step beyond it: focus must never land on the page.
        for (let i = 0; i < inside.length + 2; i += 1) {
            // Sequential by definition: each Tab depends on where the last one landed.
            // eslint-disable-next-line no-await-in-loop
            await userEvent.tab();
            expect(inside).toContain(document.activeElement);
        }
    });

    it('closes on Escape and gives focus back to what opened it', async () => {
        render(<Harness />);

        const trigger = screen.getByRole('button', { name: 'Delete endpoint' });
        await userEvent.click(trigger);
        expect(screen.getByRole('dialog')).toBeInTheDocument();

        await userEvent.keyboard('{Escape}');

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        // Radix restores focus after the unmount commit, not during it.
        await waitFor(() => expect(trigger).toHaveFocus());
    });

    it('hides the page behind it from assistive technology while it is open', async () => {
        render(<Harness />);
        await userEvent.click(screen.getByRole('button', { name: 'Delete endpoint' }));

        expect(
            screen.queryByRole('button', { name: 'Behind the dialog' }),
        ).not.toBeInTheDocument();
    });

    it('has no accessibility violations', async () => {
        const { baseElement } = render(<Harness />);
        await userEvent.click(screen.getByRole('button', { name: 'Delete endpoint' }));

        await expect(baseElement).toHaveNoAxeViolations();
    });
});
