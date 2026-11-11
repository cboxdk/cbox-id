import { useState } from 'react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import {
    defaultPasskeyName,
    isCancellation,
    passkeysSupported,
    registerPasskey,
} from '@/lib/passkeys';
import type { PageProps } from '@/types';
import { Button, Icon } from '@/ui';

type Props = PageProps<{
    /** The operator the link was issued for. */
    email: string;
    /** The link's own address: the ceremony posts to `…/register/options` and `…/register`. */
    base: string;
    /** Where the operator signs in afterwards. */
    signIn: string;
    expiresAt: string;
}>;

/**
 * BREAK-GLASS: add a passkey from a one-time link.
 *
 * An operator who cannot open platform administration (no passkey, or the only one lost)
 * is sent this link by somebody who holds the deployment's shell. It adds one passkey to
 * their account and signs nobody in. Operator-only and English-only on purpose: it is
 * read by staff, from a link issued at a command line.
 */
export default function OperatorPasskeyLink({ email, base, signIn }: Props) {
    const { t } = useTranslator();
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [done, setDone] = useState(false);
    const [supported] = useState(passkeysSupported);

    const add = async (): Promise<void> => {
        setBusy(true);
        setError(null);

        try {
            await registerPasskey(defaultPasskeyName(), base);
            setDone(true);
        } catch (caught) {
            if (!isCancellation(caught)) {
                setError(
                    caught instanceof Error ? caught.message : 'That did not work. Try again.',
                );
            }
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                Add a passkey
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                This one-time link adds a passkey or security key to the platform operator{' '}
                <b>{email}</b>. It works once. Open it on the device you will sign in from.
            </p>

            {done ? (
                <>
                    <output
                        className="mt-5 rounded-lg px-3.5 py-2.5 text-sm inline-flex items-center gap-2"
                        style={{
                            background: 'var(--success-soft)',
                            color: 'var(--success-strong)',
                        }}
                    >
                        <Icon name="check" className="w-4 h-4" /> Passkey added. Sign in with it
                        now.
                    </output>
                    <a href={signIn} className="btn btn-primary btn-lg w-full mt-5">
                        {t('auth.login.title')}
                    </a>
                </>
            ) : (
                <>
                    <Button
                        variant="primary"
                        size="lg"
                        className="w-full mt-7"
                        loading={busy}
                        disabled={!supported}
                        onClick={() => void add()}
                    >
                        Add passkey
                    </Button>
                    {!supported && (
                        <p className="field-error mt-4" role="alert">
                            This browser cannot use passkeys. Open the link in one that can.
                        </p>
                    )}
                    {error !== null && (
                        <p className="field-error mt-4" role="alert">
                            {error}
                        </p>
                    )}
                </>
            )}
        </>
    );
}

OperatorPasskeyLink.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
