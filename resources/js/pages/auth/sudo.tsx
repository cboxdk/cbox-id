import { MobileApproval } from '@/components/auth/MobileApproval';
import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { confirmWithPasskey, isCancellation } from '@/lib/passkeys';
import AccountLayout from '@/layouts/AccountLayout';
import type { PageProps } from '@/types';
import { Button, Icon, PasswordField, Panel } from '@/ui';
import { confirm } from '@routes/sudo';
import { confirm as confirmEnvironment } from '@routes/environment/sudo';

type Props = PageProps<{
    /** Why this screen appeared, when whatever raised it said so. */
    reason: string | null;
    /**
     * Which plane's step-up this is.
     *
     * The two are SEPARATE SESSION KEYS and never satisfy each other: an environment
     * administrator acts on every organization in the environment and a tenant
     * administrator acts on one, so a step-up bought in the cheaper place must not spend
     * in the dearer. The page only needs it to know where to post.
     */
    plane: 'organization' | 'environment' | 'operator';
    /**
     * The operator plane only: it is confirmed with a passkey, never a password. `blocked`
     * when there is nothing to confirm with (no passkey enrolled); the reason then says
     * what to do, and `enrol` is where a passkey is added.
     */
    passkey?: { options: string; confirm: string; blocked: boolean; enrol: string | null };
}>;

/**
 * "CONFIRM IT'S YOU."
 *
 * The reason is shown because a password prompt with no explanation is one people learn
 * to type through. Whatever raised the step-up says what is waiting on the other side.
 */
export default function Sudo({ reason, plane, passkey }: Props) {
    const { t: tc } = usePublicTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const form = useForm({ password: '' });

    return (
        <div className="max-w-md">
            <div className="mb-6">
                <span
                    className="grid place-items-center rounded-full mb-4"
                    style={{
                        width: '2.5rem',
                        height: '2.5rem',
                        background: 'var(--accent-soft)',
                        color: 'var(--accent-strong)',
                    }}
                >
                    <Icon name="shield" className="w-5 h-5" />
                </span>

                <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                    {consoleUiText('console.confirm_it_s_you_6176eec2')}
                </h1>
                <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {reason ?? tc('console.this_is_a_protected_action_b796636f')}
                </p>
            </div>

            {passkey !== undefined ? (
                <PasskeyConfirm passkey={passkey} />
            ) : (
                <Panel>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(
                                plane === 'environment' ? confirmEnvironment.url() : confirm.url(),
                            );
                        }}
                    >
                        <PasswordField
                            label={consoleUiText('console.password_e7cf3ef4')}
                            name="password"
                            autoComplete="current-password"
                            error={form.errors.password}
                            value={form.data.password}
                            onChange={(event) => form.setData('password', event.target.value)}
                        />

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            className="w-full"
                            loading={form.processing}
                        >
                            {consoleUiText('console.confirm_eebdd24a')}
                        </Button>
                    </form>
                    <MobileApproval
                        endpoint={
                            (plane === 'environment' ? confirmEnvironment.url() : confirm.url()) +
                            '/mobile'
                        }
                        onConfirmed={(url) => window.location.assign(url)}
                    />
                </Panel>
            )}
        </div>
    );
}

/**
 * The operator plane's proof: a WebAuthn ceremony in the button's own click. On success
 * the server says where to go back to.
 */
function PasskeyConfirm({ passkey }: { passkey: NonNullable<Props['passkey']> }) {
    const { t } = useConsoleTranslator();
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    if (passkey.blocked) {
        return passkey.enrol === null ? null : (
            <Panel>
                <a href={passkey.enrol} className="btn btn-primary btn-lg w-full">
                    {t('console.security_8f6fb4eb')}
                </a>
            </Panel>
        );
    }

    const confirmNow = async (): Promise<void> => {
        setBusy(true);
        setError(null);

        try {
            const next = await confirmWithPasskey(passkey.options, passkey.confirm);

            window.location.assign(next ?? '/admin');
        } catch (caught) {
            setBusy(false);

            if (!isCancellation(caught)) {
                setError(
                    caught instanceof Error
                        ? caught.message
                        : t('console.that_did_not_work_try_again_d38eba7d'),
                );
            }
        }
    };

    return (
        <Panel>
            <Button
                variant="primary"
                size="lg"
                className="w-full"
                loading={busy}
                onClick={() => void confirmNow()}
            >
                {t('console.confirm_eebdd24a')}
            </Button>
            {error !== null && (
                <p className="field-error mt-4" role="alert">
                    {error}
                </p>
            )}
        </Panel>
    );
}

Sudo.layout = (page: React.ReactNode) => <AccountLayout>{page}</AccountLayout>;
