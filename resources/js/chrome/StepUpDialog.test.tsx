import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { requestStepUp, resetStepUpForTests, type StepUpChallenge } from '@/lib/stepUp';
import { StepUpDialog } from './StepUpDialog';

const challenge: StepUpChallenge = {
    plane: 'organization',
    confirm: `${window.location.origin}/sudo`,
    reason: 'Adding a passkey gives you a new way to sign in.',
};

afterEach(() => {
    resetStepUpForTests();
    vi.restoreAllMocks();
});

function ask(options = {}): Promise<boolean> {
    let pending: Promise<boolean> = Promise.resolve(false);

    act(() => {
        pending = requestStepUp(challenge, options);
    });

    return pending;
}

describe('StepUpDialog', () => {
    it('says why it is asking, with the password field focused, and has no a11y issues', async () => {
        render(<StepUpDialog />);
        void ask();

        const dialog = await screen.findByRole('dialog');

        expect(dialog).toHaveAccessibleName("Confirm it's you");
        expect(dialog).toHaveAccessibleDescription(
            'Adding a passkey gives you a new way to sign in. Re-enter your password to continue.',
        );
        await waitFor(() => expect(screen.getByLabelText('Password')).toHaveFocus());
        await expect(dialog).toHaveNoAxeViolations();
    });

    it('shows a wrong password inline and keeps waiting', async () => {
        vi.spyOn(window, 'fetch').mockResolvedValue(
            new Response(
                JSON.stringify({ errors: { password: ['That password is incorrect.'] } }),
                { status: 422 },
            ),
        );
        render(<StepUpDialog />);
        const pending = ask();
        let settled = false;
        void pending.then(() => {
            settled = true;
        });

        await userEvent.type(await screen.findByLabelText('Password'), 'wrong');
        await userEvent.click(screen.getByRole('button', { name: 'Confirm' }));

        expect(await screen.findByText('That password is incorrect.')).toBeInTheDocument();
        expect(screen.getByRole('dialog')).toBeInTheDocument();
        expect(settled).toBe(false);
    });

    it('lets the waiting action run once the password is accepted', async () => {
        vi.spyOn(window, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ confirmed: true }), { status: 200 }),
        );
        render(<StepUpDialog />);
        const pending = ask();

        await userEvent.type(await screen.findByLabelText('Password'), 'right');
        await userEvent.click(screen.getByRole('button', { name: 'Confirm' }));

        await expect(pending).resolves.toBe(true);
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });

    it('ends a ceremony on a continue button, which takes focus', async () => {
        vi.spyOn(window, 'fetch').mockResolvedValue(
            new Response(JSON.stringify({ confirmed: true }), { status: 200 }),
        );
        render(<StepUpDialog />);
        const pending = ask({ continueLabel: 'Continue: create passkey' });

        await userEvent.type(await screen.findByLabelText('Password'), 'right');
        await userEvent.click(screen.getByRole('button', { name: 'Confirm' }));

        const carryOn = await screen.findByRole('button', { name: 'Continue: create passkey' });
        await waitFor(() => expect(carryOn).toHaveFocus());

        await userEvent.click(carryOn);
        await expect(pending).resolves.toBe(true);
    });

    it('runs nothing when dismissed with Escape', async () => {
        render(<StepUpDialog />);
        const pending = ask();

        await screen.findByRole('dialog');
        await userEvent.keyboard('{Escape}');

        await expect(pending).resolves.toBe(false);
    });
});
