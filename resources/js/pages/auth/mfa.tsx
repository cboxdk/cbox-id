import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, Field, Input } from '@/ui';
import { logout } from '@routes';
import { recover, verify } from '@routes/mfa';

type Props = PageProps<Record<string, never>>;

/**
 * THE SECOND FACTOR.
 *
 * Two doors, one at a time. The recovery path is a link rather than a second form on
 * screen, because somebody reaching for a recovery code has usually lost their phone and
 * does not need the working door in front of them — and because two one-time-code fields
 * on one page is two things for a password manager to autofill into.
 */
export default function Mfa(_props: Props) {
    const [useRecovery, setUseRecovery] = useState(false);
    const { t } = useTranslator();

    const code = useForm({ code: '' });
    const recovery = useForm({ recoveryCode: '' });

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                {t('auth.mfa.title')}
            </h1>

            {useRecovery ? (
                <>
                    <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {t('auth.mfa.recovery.lead')}
                    </p>

                    <form
                        className="mt-7 space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            recovery.post(recover.url());
                        }}
                    >
                        <Field
                            label={t('auth.mfa.recovery.label')}
                            error={recovery.errors.recoveryCode}
                        >
                            <Input
                                name="recoveryCode"
                                scale="lg"
                                className="mono"
                                autoComplete="one-time-code"
                                placeholder="xxxxx-xxxxx"
                                value={recovery.data.recoveryCode}
                                onChange={(event) =>
                                    recovery.setData('recoveryCode', event.target.value)
                                }
                            />
                        </Field>

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            className="w-full"
                            loading={recovery.processing}
                        >
                            {t('auth.mfa.recovery.submit')}
                        </Button>
                    </form>

                    <button
                        type="button"
                        onClick={() => setUseRecovery(false)}
                        className="mt-4 text-sm underline underline-offset-2"
                        style={{ color: 'var(--accent-strong)' }}
                    >
                        {t('auth.mfa.recovery.switch')}
                    </button>
                </>
            ) : (
                <>
                    <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {t('auth.mfa.code.lead')}
                    </p>

                    <form
                        className="mt-7 space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            code.post(verify.url());
                        }}
                    >
                        <Field label={t('auth.mfa.code.label')} error={code.errors.code}>
                            <Input
                                name="code"
                                scale="lg"
                                className="mono"
                                style={{ letterSpacing: '0.5em', textAlign: 'center' }}
                                inputMode="numeric"
                                // The one autocomplete value that makes iOS and Android
                                // offer the code straight from the notification, and
                                // password managers offer their own TOTP.
                                autoComplete="one-time-code"
                                maxLength={6}
                                placeholder="000000"
                                value={code.data.code}
                                onChange={(event) => code.setData('code', event.target.value)}
                            />
                        </Field>

                        <Button
                            type="submit"
                            variant="primary"
                            size="lg"
                            className="w-full"
                            loading={code.processing}
                        >
                            {t('auth.common.verify')}
                        </Button>
                    </form>

                    <button
                        type="button"
                        onClick={() => setUseRecovery(true)}
                        className="mt-4 text-sm underline underline-offset-2"
                        style={{ color: 'var(--accent-strong)' }}
                    >
                        {t('auth.mfa.code.switch')}
                    </button>
                </>
            )}

            <div className="mt-6">
                <button
                    type="button"
                    onClick={() => router.post(logout.url())}
                    className="text-sm underline underline-offset-2"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    {t('auth.common.cancel_and_sign_out')}
                </button>
            </div>
        </>
    );
}

Mfa.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
