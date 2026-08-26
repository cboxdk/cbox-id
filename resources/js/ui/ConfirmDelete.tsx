import { usePage } from '@inertiajs/react';
import { type ReactNode, useId, useState } from 'react';
import type { SharedProps } from '@/types';
import { Button } from './Button';
import { Dialog } from './Dialog';
import { Field } from './Field';
import { Input } from './Input';

export interface ConfirmDeleteProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The exact text the operator must type. Usually the resource's name. */
    name: string;
    /** The verb on the confirm button and in the title — "Delete", "Rotate", "Revoke". */
    verb?: string;
    consequence?: ReactNode;
    /**
     * WHICH ENVIRONMENT this is happening in, named in the dialog.
     *
     * TAKEN FROM THE SHARED PROPS BY DEFAULT, and that is the point of it being here
     * rather than a per-page argument: the failure being designed against is an
     * administrator with staging and production open in two visually identical tabs, and
     * a line that each page has to remember to pass is a line some page will not pass.
     *
     * Override it only for a dialog about something in a DIFFERENT environment than the
     * one this request is acting in.
     */
    environment?: string | null;
    confirming?: boolean;
    onConfirm: () => void;
}

/**
 * Type-to-confirm, for an action that cannot be undone from the console.
 *
 * WHEN TO USE IT. Destroying a credential, revoking somebody else's access, transferring
 * ownership — anything where the cost of the wrong tab is real.
 *
 * WHEN NOT TO. A genuinely reversible toggle (pause and resume an endpoint, disable and
 * re-enable a stream) gets an ordinary confirm: making a two-way switch cost a typed name
 * trains people to type names without reading them, which is the failure this exists to
 * prevent. Actions on your OWN account — removing your passkey, turning off your own 2FA
 * — also get an ordinary confirm; the two-identical-tabs hazard does not apply there and
 * the environment line would be meaningless.
 */
export function ConfirmDelete({ open, ...props }: ConfirmDeleteProps) {
    // Mounted only while open, which is what resets the typed name between openings.
    //
    // The alternative — holding the text in this component and clearing it in an effect —
    // clears it one render AFTER the dialog is already on screen, and if the effect is
    // ever missed a second dialog opens ALREADY CONFIRMED, one Enter away from destroying
    // something nobody re-read.
    if (!open) {
        return null;
    }

    return <Confirmation {...props} />;
}

function Confirmation({
    onOpenChange,
    name,
    verb = 'Delete',
    consequence = 'This cannot be undone.',
    environment,
    confirming = false,
    onConfirm,
}: Omit<ConfirmDeleteProps, 'open'>) {
    const shared = usePage<SharedProps>().props.environment;
    const realm = environment === undefined ? shared.name : environment;

    const [typed, setTyped] = useState('');
    const matches = typed === name;

    // The field's id, so the DISABLED CONFIRM BUTTON can point at the requirement.
    //
    // A disabled control is the other half of the mobile bug: the keyboard rewrote the
    // token, the button stayed grey, and nothing on the screen was addressed to the person
    // wondering why. `aria-describedby` puts the sentence on the button itself, where a
    // screen reader reads it when the button is reached — a disabled button is not
    // focusable, but it is still announced in browse mode, and this is what it says.
    const field = useId();

    const confirm = (): void => {
        if (matches && !confirming) {
            onConfirm();
        }
    };

    return (
        <Dialog
            open
            onOpenChange={onOpenChange}
            size="sm"
            title={`${verb} ${name}?`}
            description={consequence}
            footer={
                <>
                    <Button onClick={() => onOpenChange(false)}>Cancel</Button>
                    <Button
                        variant="danger"
                        disabled={!matches}
                        aria-describedby={matches ? undefined : `${field}-hint`}
                        loading={confirming}
                        onClick={confirm}
                    >
                        {verb}
                    </Button>
                </>
            }
        >
            {realm != null && realm !== '' && (
                <p
                    style={{
                        margin: '0 0 12px',
                        fontSize: '13px',
                        color: 'var(--muted-foreground)',
                    }}
                >
                    In environment{' '}
                    <strong style={{ color: 'var(--foreground)' }}>{realm}</strong>.
                </p>
            )}

            <Field
                id={field}
                label={
                    <>
                        Type <span className="mono">{name}</span> to confirm
                    </>
                }
                hint={`Exactly as shown — ${verb} stays disabled until it matches.`}
            >
                <Input
                    value={typed}
                    onChange={(event) => setTyped(event.target.value)}
                    autoComplete="off"
                    // THE MATCH IS EXACT, so the keyboard must not help.
                    //
                    // iOS capitalises the first letter of a text field by default and
                    // autocorrect rewrites the token as it is typed — against a resource
                    // named `acme-prod` that produces `Acme-prod`, which never matches, so
                    // the confirm button never enables and the dialog cannot be completed
                    // on a phone at all. Nothing about that failure tells the person why.
                    autoCapitalize="none"
                    autoCorrect="off"
                    spellCheck={false}
                    // The one autofocus in the design system, and it earns it: the dialog
                    // exists to make somebody type, focus is already inside it, and the
                    // rule this suppresses is about autofocus on PAGE LOAD — which moves a
                    // screen reader away from the top of a document it has not read.
                    // eslint-disable-next-line jsx-a11y/no-autofocus
                    autoFocus
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            confirm();
                        }
                    }}
                />
            </Field>
        </Dialog>
    );
}
