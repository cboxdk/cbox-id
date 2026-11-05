import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Disclosure } from './Disclosure';

describe('Disclosure', () => {
    it('keeps a draft when optional settings are closed and reopened', async () => {
        render(
            <Disclosure title="Advanced">
                <label>
                    Callback
                    <input />
                </label>
            </Disclosure>,
        );
        await userEvent.click(screen.getByText('Advanced'));
        await userEvent.type(screen.getByLabelText('Callback'), 'https://app.test');
        await userEvent.click(screen.getByText('Advanced'));
        await userEvent.click(screen.getByText('Advanced'));
        expect(screen.getByLabelText('Callback')).toHaveValue('https://app.test');
    });

    it('reveals a rejected setting after server validation', () => {
        const { rerender } = render(
            <Disclosure title="Advanced">
                <input aria-label="Callback" />
            </Disclosure>,
        );
        const details = screen.getByText('Advanced').closest('details');
        expect(details).not.toHaveAttribute('open');
        rerender(
            <Disclosure title="Advanced" hasError>
                <input aria-label="Callback" />
                <p role="alert">Use HTTPS</p>
            </Disclosure>,
        );
        expect(details).toHaveAttribute('open');
        expect(screen.getByRole('alert')).toBeVisible();
    });

    it('reveals and focuses an invalid field even if the section was closed', async () => {
        render(
            <Disclosure title="Advanced">
                <input aria-label="Callback" type="url" defaultValue="invalid" />
            </Disclosure>,
        );
        const input = screen.getByLabelText('Callback');
        fireEvent.invalid(input);
        expect(input.closest('details')).toHaveAttribute('open');
        await waitFor(() => expect(input).toHaveFocus());
    });
});
