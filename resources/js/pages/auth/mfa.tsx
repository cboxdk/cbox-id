import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, Field, Icon, Input } from '@/ui';
import { logout } from '@routes';
import { recover, verify } from '@routes/mfa';
import { send as sendSms, verify as verifySms } from '@routes/mfa/sms';

type Method = 'totp' | 'sms' | 'recovery';

type Props = PageProps<{
    /** Which second factors this person can answer with. Recovery codes are always offered. */
    factors: { totp: boolean; sms: boolean };
}>;

/**
 * THE SECOND FACTOR.
 *
 * One door at a time: the authenticator app, a texted code, or a recovery code. The first
 * one shown is the strongest the person has — the app before the text message — and the
 * others are links rather than more forms on screen, because two one-time-code fields on
 * one page is two things for a password manager to autofill into. The recovery path is a
 * link for the same reason it always was: somebody reaching for a recovery code has usually
 * lost their phone and does not need the working door in front of them.
 *
 * THE TEXT IS SENT WHEN THE PERSON ASKS. Rendering this page is a GET that a reload or a
 * prefetch repeats; a text costs money and starts a cooldown, so it is a button.
 */
export default function Mfa({ factors }: Props) {
    const initial: Method = factors.totp ? 'totp' : factors.sms ? 'sms' : 'recovery';
    const [method, setMethod] = useState<Method>(initial);
    const { t, rich } = useTranslator();

    const code = useForm({ code: '' });
    const recovery = useForm({ recoveryCode: '' });
    const sms = useForm({ smsCode: '' });

    // The masked number a code went to, kept across a wrong guess: the flash that carried
    // it lives for one render, and the code field must not vanish because of a typo.
    const flashed = usePage().flash.smsSentTo;
    const [remembered, setRemembered] = useState<string | undefined>(flashed);
    const sentTo = flashed ?? remembered;
    const [sending, setSending] = useState(false);

    const requestSms = () => {
        setSending(true);
        router.post(
            sendSms.url(),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: (page) => {
                    if (page.flash.smsSentTo !== undefined) {
                        setRemembered(page.flash.smsSentTo);
                    }
                },
                onFinish: () => setSending(false),
            },
        );
    };

    const sendError = usePage().props.errors.smsCode;

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                {t('auth.mfa.title')}
            </h1>

            {method === 'recovery' && (
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

                    {initial !== 'recovery' && (
                        <SwitchLink onClick={() => setMethod(initial)}>
                            {initial === 'totp'
                                ? t('auth.mfa.recovery.switch')
                                : t('auth.mfa.recovery.back')}
                        </SwitchLink>
                    )}
                </>
            )}

            {method === 'totp' && (
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

                    {factors.sms && (
                        <SwitchLink onClick={() => setMethod('sms')}>
                            {t('auth.mfa.code.sms_switch')}
                        </SwitchLink>
                    )}
                    <SwitchLink onClick={() => setMethod('recovery')}>
                        {t('auth.mfa.code.switch')}
                    </SwitchLink>
                </>
            )}

            {method === 'sms' && (
                <>
                    <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {t('auth.mfa.sms.lead')}
                    </p>

                    {sentTo !== undefined && (
                        <output
                            className="mt-5 rounded-lg px-3.5 py-2.5 text-sm inline-flex items-center gap-2"
                            style={{
                                background: 'var(--success-soft)',
                                color: 'var(--success-strong)',
                            }}
                        >
                            <Icon name="check" className="w-4 h-4" />{' '}
                            {rich('auth.mfa.sms.sent', { number: <b>{sentTo}</b> })}
                        </output>
                    )}

                    {sendError !== undefined && sentTo === undefined && (
                        <p className="mt-5 field-error" role="alert">
                            {sendError}
                        </p>
                    )}

                    {sentTo === undefined ? (
                        <div className="mt-7">
                            <Button
                                variant="primary"
                                size="lg"
                                className="w-full"
                                loading={sending}
                                onClick={requestSms}
                            >
                                {t('auth.mfa.sms.send')}
                            </Button>
                        </div>
                    ) : (
                        <>
                            <form
                                className="mt-7 space-y-4"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    sms.post(verifySms.url(), {
                                        preserveState: true,
                                        preserveScroll: true,
                                    });
                                }}
                            >
                                <Field label={t('auth.mfa.sms.label')} error={sms.errors.smsCode}>
                                    <Input
                                        name="smsCode"
                                        scale="lg"
                                        className="mono"
                                        style={{ letterSpacing: '0.5em', textAlign: 'center' }}
                                        inputMode="numeric"
                                        autoComplete="one-time-code"
                                        maxLength={10}
                                        placeholder="000000"
                                        value={sms.data.smsCode}
                                        onChange={(event) =>
                                            sms.setData('smsCode', event.target.value)
                                        }
                                    />
                                </Field>

                                <Button
                                    type="submit"
                                    variant="primary"
                                    size="lg"
                                    className="w-full"
                                    loading={sms.processing}
                                >
                                    {t('auth.common.verify')}
                                </Button>
                            </form>

                            <SwitchLink onClick={requestSms} disabled={sending}>
                                {t('auth.mfa.sms.resend')}
                            </SwitchLink>
                        </>
                    )}

                    {factors.totp && (
                        <SwitchLink onClick={() => setMethod('totp')}>
                            {t('auth.mfa.sms.switch')}
                        </SwitchLink>
                    )}
                    <SwitchLink onClick={() => setMethod('recovery')}>
                        {t('auth.mfa.code.switch')}
                    </SwitchLink>
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

/** A way to another door, drawn as a link: one form on screen at a time. */
function SwitchLink({
    onClick,
    disabled = false,
    children,
}: {
    onClick: () => void;
    disabled?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div className="mt-4">
            <button
                type="button"
                onClick={onClick}
                disabled={disabled}
                className="text-sm underline underline-offset-2"
                style={{ color: 'var(--accent-strong)' }}
            >
                {children}
            </button>
        </div>
    );
}
