import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { setPageProps } from '@/test/page';
import { Checkbox } from './Checkbox';
import { Combobox } from './Combobox';
import { ConfirmDelete } from './ConfirmDelete';
import { Field } from './Field';
import { RadioGroup } from './RadioGroup';
import { Select } from './Select';
import { Switch } from './Switch';

describe('Select', () => {
    function Harness() {
        const [value, setValue] = useState<'oidc' | 'saml'>('oidc');

        return (
            <Field label="Protocol">
                <Select
                    value={value}
                    onValueChange={setValue}
                    options={[
                        { value: 'oidc', label: 'OpenID Connect' },
                        { value: 'saml', label: 'SAML 2.0' },
                    ]}
                />
            </Field>
        );
    }

    it('is labelled by its Field and announces the current value', () => {
        render(<Harness />);

        expect(screen.getByRole('combobox', { name: 'Protocol' })).toHaveTextContent(
            'OpenID Connect',
        );
    });

    it('opens and picks with the keyboard alone', async () => {
        render(<Harness />);

        await userEvent.tab();
        expect(screen.getByRole('combobox', { name: 'Protocol' })).toHaveFocus();

        await userEvent.keyboard('{Enter}');
        await userEvent.keyboard('{ArrowDown}{Enter}');

        expect(screen.getByRole('combobox', { name: 'Protocol' })).toHaveTextContent('SAML 2.0');
    });
});

describe('Combobox', () => {
    const organizations = Array.from({ length: 40 }, (_, i) => ({
        value: `org_${i}` as const,
        label: `Organization ${i}`,
        keywords: [`slug-${i}`],
    }));

    it('narrows a long list by typing, including on a keyword the label does not show', async () => {
        function Harness() {
            const [value, setValue] = useState<string>();

            return (
                <Combobox
                    value={value}
                    onValueChange={setValue}
                    options={organizations}
                    aria-label="Organization"
                />
            );
        }

        render(<Harness />);
        await userEvent.click(screen.getByRole('button', { name: /Organization/ }));

        await userEvent.type(screen.getByPlaceholderText('Search…'), 'slug-37');

        expect(screen.getByText('Organization 37')).toBeInTheDocument();
        expect(screen.queryByText('Organization 12')).not.toBeInTheDocument();
    });

    it('says so when nothing matches, rather than showing an empty box', async () => {
        render(
            <Combobox
                value={undefined}
                onValueChange={vi.fn<(v: string) => void>()}
                options={organizations}
                aria-label="Organization"
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: /Organization/ }));
        await userEvent.type(screen.getByPlaceholderText('Search…'), 'zzzz');

        expect(screen.getByText('Nothing matches that.')).toBeInTheDocument();
    });
});

describe('Switch', () => {
    it('is operable with the keyboard and reports its state', async () => {
        function Harness() {
            const [on, setOn] = useState(false);

            return (
                <Field label="Require MFA">
                    <Switch checked={on} onCheckedChange={setOn} />
                </Field>
            );
        }

        render(<Harness />);

        const control = screen.getByRole('switch', { name: 'Require MFA' });
        expect(control).not.toBeChecked();

        control.focus();
        await userEvent.keyboard(' ');

        expect(control).toBeChecked();
    });
});

describe('Checkbox', () => {
    it('is toggled by its label, not only by the 16px box', async () => {
        function Harness() {
            const [on, setOn] = useState(false);

            return (
                <Checkbox
                    checked={on}
                    onCheckedChange={setOn}
                    label="Send an invitation email"
                    hint="They can also be given the link directly."
                />
            );
        }

        render(<Harness />);

        await userEvent.click(screen.getByText('Send an invitation email'));

        const box = screen.getByRole('checkbox', { name: 'Send an invitation email' });
        expect(box).toBeChecked();
        expect(box).toHaveAccessibleDescription('They can also be given the link directly.');
    });
});

describe('RadioGroup', () => {
    /*
     * Arrow keys move FOCUS here, and in a real browser Radix also moves the SELECTION
     * with it — the roving-tabindex behaviour a radio group is supposed to have. That
     * second half cannot be asserted in jsdom: it is driven by a `focus` event firing
     * synchronously inside the keydown handler, and jsdom's focus timing does not
     * reproduce it. Verified against bare Radix in isolation, so it is the environment
     * and not this component. The selection half is covered in the browser suite.
     */
    it('moves focus between options with the arrow keys and leaves the group with Tab', async () => {
        function Harness() {
            const [value, setValue] = useState<'read' | 'write'>('read');

            return (
                <>
                    <RadioGroup
                        label="Access"
                        value={value}
                        onValueChange={setValue}
                        options={[
                            { value: 'read', label: 'Read only' },
                            { value: 'write', label: 'Read and write' },
                        ]}
                    />
                    <button type="button">After</button>
                </>
            );
        }

        render(<Harness />);

        await userEvent.tab();
        expect(screen.getByRole('radio', { name: 'Read only' })).toHaveFocus();

        await userEvent.keyboard('{ArrowDown}');
        expect(screen.getByRole('radio', { name: 'Read and write' })).toHaveFocus();

        // Tab leaves the GROUP rather than stepping to the next radio — which is the
        // whole difference between a radio group and a row of styled buttons.
        await userEvent.tab();
        expect(screen.getByRole('button', { name: 'After' })).toHaveFocus();
    });

    it('selects with Space on the focused option', async () => {
        function Harness() {
            const [value, setValue] = useState<'read' | 'write'>('read');

            return (
                <RadioGroup
                    label="Access"
                    value={value}
                    onValueChange={setValue}
                    options={[
                        { value: 'read', label: 'Read only' },
                        { value: 'write', label: 'Read and write' },
                    ]}
                />
            );
        }

        render(<Harness />);

        screen.getByRole('radio', { name: 'Read and write' }).focus();
        await userEvent.keyboard(' ');

        expect(screen.getByRole('radio', { name: 'Read and write' })).toBeChecked();
        expect(screen.getByRole('radio', { name: 'Read only' })).not.toBeChecked();
    });

    it('selects when the label beside the option is clicked, not only the 16px dot', async () => {
        function Harness() {
            const [value, setValue] = useState<'read' | 'write'>('read');

            return (
                <RadioGroup
                    label="Access"
                    value={value}
                    onValueChange={setValue}
                    options={[
                        { value: 'read', label: 'Read only' },
                        { value: 'write', label: 'Read and write' },
                    ]}
                />
            );
        }

        render(<Harness />);
        await userEvent.click(screen.getByText('Read and write'));

        expect(screen.getByRole('radio', { name: 'Read and write' })).toBeChecked();
    });

    it('names the group, so the options are not announced as a bare list', () => {
        render(
            <RadioGroup
                label="Access"
                value="read"
                onValueChange={vi.fn<(v: 'read') => void>()}
                options={[{ value: 'read', label: 'Read only' }]}
            />,
        );

        expect(screen.getByRole('radiogroup')).toHaveAccessibleName('Access');
    });
});

describe('ConfirmDelete', () => {
    function Harness({ onConfirm }: { onConfirm: () => void }) {
        const [open, setOpen] = useState(true);

        return (
            <ConfirmDelete
                open={open}
                onOpenChange={setOpen}
                name="prod-webhook"
                onConfirm={onConfirm}
            />
        );
    }

    it('will not confirm until the name is typed exactly', async () => {
        const onConfirm = vi.fn<() => void>();
        render(<Harness onConfirm={onConfirm} />);

        const button = screen.getByRole('button', { name: 'Delete' });
        expect(button).toBeDisabled();

        await userEvent.type(screen.getByRole('textbox'), 'prod-webhoo');
        expect(button).toBeDisabled();

        await userEvent.type(screen.getByRole('textbox'), 'k');
        expect(button).toBeEnabled();

        await userEvent.click(button);
        expect(onConfirm).toHaveBeenCalledOnce();
    });

    it('refuses a bare Enter on an unconfirmed name, which is how the wrong tab deletes', async () => {
        const onConfirm = vi.fn<() => void>();
        render(<Harness onConfirm={onConfirm} />);

        await userEvent.type(screen.getByRole('textbox'), 'something else{Enter}');

        expect(onConfirm).not.toHaveBeenCalled();
    });

    it('names the environment from the shared props, not from a per-page argument', () => {
        // The failure being designed against is staging and production open in two
        // visually identical tabs. The line comes from the page every request already
        // carries, so no page can forget to pass it.
        setPageProps({ environment: { name: 'staging', type: 'sandbox', sandbox: true } });

        render(<Harness onConfirm={vi.fn<() => void>()} />);

        expect(screen.getByText('staging')).toBeInTheDocument();
    });

    it('has no accessibility violations', async () => {
        const { baseElement } = render(<Harness onConfirm={vi.fn<() => void>()} />);

        await expect(baseElement).toHaveNoAxeViolations();
    });
});
