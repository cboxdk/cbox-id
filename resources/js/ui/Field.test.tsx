import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { Field } from './Field';
import { Input } from './Input';

describe('Field', () => {
    it('labels the control, so clicking the label focuses it', async () => {
        render(
            <Field label="Email address">
                <Input name="email" />
            </Field>,
        );

        await userEvent.click(screen.getByText('Email address'));

        expect(screen.getByLabelText('Email address')).toHaveFocus();
    });

    it('describes the control with its hint rather than hiding it in a placeholder', () => {
        render(
            <Field label="Slug" hint="Lowercase letters and hyphens only.">
                <Input name="slug" />
            </Field>,
        );

        expect(screen.getByLabelText('Slug')).toHaveAccessibleDescription(
            'Lowercase letters and hyphens only.',
        );
    });

    it('marks the control invalid and announces the message when the server refuses it', () => {
        render(
            <Field label="Email address" error="That address is already in use.">
                <Input name="email" />
            </Field>,
        );

        const input = screen.getByLabelText('Email address');

        expect(input).toBeInvalid();
        expect(input).toHaveAccessibleDescription('That address is already in use.');
        expect(screen.getByRole('alert')).toHaveTextContent('That address is already in use.');
    });

    it('keeps the live region mounted while empty, so the first error is announced', () => {
        // A region inserted carrying its text registers as a new subtree, not an update,
        // and screen readers stay silent — the exact failure the Volt toast hit twice.
        const { container, rerender } = render(
            <Field label="Email address">
                <Input name="email" />
            </Field>,
        );

        const region = container.querySelector('[role="alert"]');
        expect(region).toBeInTheDocument();
        expect(region).not.toBeVisible();

        rerender(
            <Field label="Email address" error="Required.">
                <Input name="email" />
            </Field>,
        );

        expect(container.querySelector('[role="alert"]')).toBe(region);
        expect(region).toBeVisible();
    });

    it('states "required" in words, not only as an asterisk', () => {
        render(
            <Field label="Organization name" required>
                <Input name="name" />
            </Field>,
        );

        expect(screen.getByLabelText(/Organization name.*\(required\)/)).toBeRequired();
    });

    it('has no accessibility violations', async () => {
        const { container } = render(
            <Field label="Email address" hint="Work address." error="Required." required>
                <Input name="email" />
            </Field>,
        );

        await expect(container).toHaveNoAxeViolations();
    });
});
