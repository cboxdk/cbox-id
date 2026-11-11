import { MobileApproval } from '@/components/auth/MobileApproval';
import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { type FormEvent, useEffect, useRef, useState } from 'react';
import {
    confirmStepUp,
    type StepUpPrompt,
    stepUpCancelled,
    stepUpConfirmed,
    stepUpContinued,
    useStepUpPrompt,
} from '@/lib/stepUp';
import { confirmWithPasskey, isCancellation } from '@/lib/passkeys';
import { Button, Dialog, Icon, PasswordField } from '@/ui';

/**
 * "CONFIRM IT'S YOU" — the step-up, asked on the page the person is on.
 *
 * Mounted inside the console's Inertia context, and shown whenever something asks for a step-up
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
    if (prompt === null) {
        return null;
    }

    // The operator plane is confirmed with a passkey. Its last step (a "Continue" button
    // for a waiting ceremony) is the same as any plane's, so that stays with the form below.
    if (typeof prompt.challenge.passkeyOptions === 'string' && prompt.continueLabel === null) {
        return <PasskeyStepUp key={prompt.id} prompt={prompt} />;
    }

    return <StepUpForm key={prompt.id} prompt={prompt} />;
}

/**
 * The operator plane's ask: one button that runs a WebAuthn ceremony.
 *
 * No password field at all, deliberately. A password typed into this page proves nothing
 * a script injected into the page could not replay; the assertion is made by the
 * authenticator, for this origin, over a challenge the server issued a moment ago. The
 * ceremony runs inside the button's own click, which is the gesture WebAuthn needs.
 */
function PasskeyStepUp({ prompt }: { prompt: StepUpPrompt }) {
    const { t } = useConsoleTranslator();
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const button = useRef<HTMLButtonElement>(null);
    const title = t('console.confirm_it_s_you_6176eec2');

    const confirm = async (): Promise<void> => {
        const options = prompt.challenge.passkeyOptions;

        if (busy || typeof options !== 'string') {
            return;
        }

        setBusy(true);
        setError(null);

        try {
            await confirmWithPasskey(options, prompt.challenge.confirm);
        } catch (caught) {
            setBusy(false);

            // A dismissed system prompt is not a failure: the dialog simply stays open.
            if (!isCancellation(caught)) {
                setError(
                    caught instanceof Error
                        ? caught.message
                        : t('console.that_did_not_work_try_again_d38eba7d'),
                );
            }

            return;
        }

        stepUpConfirmed();
    };

    return (
        <Dialog
            open
            size="sm"
            initialFocus={button}
            onOpenChange={(next) => {
                if (!next) {
                    stepUpCancelled();
                }
            }}
            title={title}
            description={prompt.challenge.reason ?? title}
            footer={
                <>
                    <Button onClick={stepUpCancelled}>{t('console.cancel_19766ed6')}</Button>
                    <Button
                        ref={button}
                        variant="primary"
                        data-step-up-passkey
                        loading={busy}
                        onClick={() => void confirm()}
                    >
                        {t('console.confirm_eebdd24a')}
                    </Button>
                </>
            }
        >
            {error !== null && (
                <p className="field-error" role="alert">
                    {error}
                </p>
            )}
        </Dialog>
    );
}

function StepUpForm({ prompt }: { prompt: StepUpPrompt }) {
    const { t: tc } = usePublicTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
            setError(tc('console.enter_your_password_f0d4a579'));
            field.current?.focus();

            return;
        }

        setBusy(true);
        setError(null);

        const outcome = await confirmStepUp(prompt.challenge, password, {
            offline: tc('console.you_appear_to_be_offline_check_your_connecti_0d700f8e'),
            expired: tc('console.this_page_has_expired_reload_it_and_try_agai_70dbb1a7'),
            failed: tc('console.that_did_not_work_try_again_d38eba7d'),
            reload: tc('console.that_did_not_work_reload_the_page_and_try_ag_d90cc6b4'),
        });

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
            title={consoleT('console.confirm_it_s_you_6176eec2')}
            description={
                continuing
                    ? consoleText('console.you_are_confirmed_for_the_next_few_minutes_7b76614c')
                    : (prompt.challenge.reason ?? consoleT('console.confirm_it_s_you_6176eec2'))
            }
            footer={
                continuing ? (
                    <>
                        <Button onClick={stepUpCancelled}>
                            {consoleT('console.cancel_19766ed6')}
                        </Button>
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
                        <Button onClick={stepUpCancelled}>
                            {consoleT('console.cancel_19766ed6')}
                        </Button>
                        <Button type="submit" form="cbx-step-up" variant="primary" loading={busy}>
                            {consoleT('console.confirm_eebdd24a')}
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
                    {consoleT('console.you_are_confirmed_for_the_next_few_minutes_7b76614c')}
                </output>
            ) : (
                <form id="cbx-step-up" noValidate onSubmit={(event) => void submit(event)}>
                    <PasswordField
                        ref={field}
                        label={consoleT('console.password_e7cf3ef4')}
                        name="password"
                        autoComplete="current-password"
                        error={error}
                        value={password}
                        onChange={(event) => setPassword(event.target.value)}
                    />
                    <MobileApproval
                        endpoint={prompt.challenge.confirm + '/mobile'}
                        onConfirmed={() => stepUpConfirmed()}
                    />
                </form>
            )}
        </Dialog>
    );
}
