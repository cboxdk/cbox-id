import { type FormEvent, useEffect, useRef, useState } from 'react';
import {
    confirmStepUp,
    type StepUpPrompt,
    stepUpCancelled,
    stepUpConfirmed,
    stepUpContinued,
    useStepUpPrompt,
} from '@/lib/stepUp';
import { Button, Dialog, Icon, PasswordField } from '@/ui';

/**
 * "CONFIRM IT'S YOU" — the step-up, asked on the page the person is on.
 *
 * Mounted once, beside the app, and shown whenever something asks for a step-up
 * (see `lib/stepUp`): a refused Inertia visit, or the passkey ceremony. It confirms
 * against the endpoint the server named — the organization plane's or the environment
 * plane's, never one for the other — and then lets whatever was waiting carry on, so the
 * click that raised it is the click that completes.
 *
 * The REASON is the sentence the action gave. A password prompt with no explanation is
 * one people learn to type through; this one says what is waiting on the other side.
 *
 * Radix does the parts that are easy to get wrong (see `ui/Dialog`): focus moves in and
 * back, Tab is trapped, Escape dismisses, and the page behind is inert.
 */
export function StepUpDialog() {
    const prompt = useStepUpPrompt();

    // Keyed by the ask, so a new one starts with an empty field and no old refusal in it.
    return prompt === null ? null : <StepUpForm key={prompt.id} prompt={prompt} />;
}

function StepUpForm({ prompt }: { prompt: StepUpPrompt }) {
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const field = useRef<HTMLInputElement>(null);
    const carryOn = useRef<HTMLButtonElement>(null);

    const continuing = prompt.continueLabel !== null;

    /*
     * The password field goes away when it has done its job, and focus would go with it —
     * to the top of the document, behind a dialog that is still open. The "Continue" button
     * is the one thing left to do, so it takes focus.
     */
    useEffect(() => {
        if (continuing) {
            carryOn.current?.focus();
        }
    }, [continuing]);

    const submit = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
        event.preventDefault();

        if (busy) {
            return;
        }

        if (password === '') {
            setError('Enter your password.');
            field.current?.focus();

            return;
        }

        setBusy(true);
        setError(null);

        const outcome = await confirmStepUp(prompt.challenge, password);

        setBusy(false);

        if (!outcome.ok) {
            setError(outcome.error);
            field.current?.select();

            return;
        }

        setPassword('');
        stepUpConfirmed();
    };

    return (
        <Dialog
            open
            size="sm"
            initialFocus={field}
            onOpenChange={(next) => {
                if (!next) {
                    stepUpCancelled();
                }
            }}
            title="Confirm it's you"
            description={
                continuing
                    ? 'You are confirmed for the next few minutes.'
                    : `${prompt.challenge.reason ?? 'This is a protected action.'} Re-enter your password to continue.`
            }
            footer={
                continuing ? (
                    <>
                        <Button onClick={stepUpCancelled}>Cancel</Button>
                        <Button
                            ref={carryOn}
                            variant="primary"
                            data-step-up-continue
                            // What follows runs inside THIS click — see `continueLabel` in
                            // lib/stepUp for why it cannot run on its own.
                            onClick={stepUpContinued}
                        >
                            {prompt.continueLabel}
                        </Button>
                    </>
                ) : (
                    <>
                        <Button onClick={stepUpCancelled}>Cancel</Button>
                        <Button type="submit" form="cbx-step-up" variant="primary" loading={busy}>
                            Confirm
                        </Button>
                    </>
                )
            }
        >
            {continuing ? (
                <output className="flex items-center gap-2 text-sm">
                    <Icon
                        name="check"
                        className="w-4 h-4 shrink-0"
                        style={{ color: 'var(--success-strong)' }}
                    />
                    Password confirmed. Continue when you are ready.
                </output>
            ) : (
                <form id="cbx-step-up" noValidate onSubmit={(event) => void submit(event)}>
                    <PasswordField
                        ref={field}
                        label="Password"
                        name="password"
                        autoComplete="current-password"
                        error={error}
                        value={password}
                        onChange={(event) => setPassword(event.target.value)}
                    />
                </form>
            )}
        </Dialog>
    );
}
