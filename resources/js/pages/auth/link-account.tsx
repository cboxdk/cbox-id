import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, Field, Icon, Input, PasswordField, ProviderMark } from '@/ui';
import { cancel, code, verify } from '@routes/login/link';

type Method = 'totp' | 'recovery_code' | 'sms' | 'password' | 'email_code';

type Props = PageProps<{
    /** The held link this page answers for. Posted back; it names nothing by itself. */
    hold: string;
    /** The provider the person just signed in with: its catalogue key (the mark) and name. */
    provider: { key: string; label: string };
    /** The existing account's address — the one the provider vouched for. */
    email: string;
    /** Which proofs the account accepts. A second factor, when there is one, is the only one. */
    factors: { totp: boolean; sms: boolean; password: boolean; email: boolean };
    /** A code is already on its way to the account's inbox. */
    codeSent: boolean;
    /** Radar asked for more than a password on this attempt. */
    challenged: boolean;
}>;

/** The answer the page opens on: the strongest the account has. */
function firstMethod(factors: Props['factors']): Method {
    if (factors.totp) return 'totp';
    if (factors.sms) return 'sms';
    if (factors.password) return 'password';

    return 'email_code';
}

/**
 * "LINK YOUR GOOGLE ACCOUNT TO sn@cbox.dk?" — the step between a social sign-in whose
 * address already has an account here and being signed in to it.
 *
 * One question, one answer field, one primary button: the person has just come back from
 * the provider and should not have to work out what is being asked. The alternatives — a
 * recovery code, a texted code, a mailed code instead of the password — are quiet links
 * under the form, because most people will never need them. "Don't link" is a real button
 * of its own, not a footnote: somebody who did NOT just sign in with that provider is
 * looking at another person's attempt to use their address, and leaving must be easy.
 *
 * Mobile first: everything is one column and full width, the code field asks for the
 * numeric keypad and offers the code from the message (`one-time-code`), and nothing here
 * depends on hover.
 */
export default function LinkAccount({
    hold,
    provider,
    email,
    factors,
    codeSent,
    challenged,
}: Props) {
    const { t, rich } = useTranslator();
    const sent = usePage().flash.linkCodeSent;

    const [method, setMethod] = useState<Method>(() => firstMethod(factors));
    const [codeOnItsWay, setCodeOnItsWay] = useState(codeSent);

    const form = useForm({ hold, method: method as string, answer: '' });

    const choose = (next: Method) => {
        setMethod(next);
        form.setData({ hold, method: next, answer: '' });
        form.clearErrors();
    };

    const send = (channel: 'email' | 'sms') =>
        router.post(
            code.url(),
            { hold, channel },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (channel === 'email') setCodeOnItsWay(true);
                    choose(channel === 'email' ? 'email_code' : 'sms');
                },
            },
        );

    const label: Record<Method, string> = {
        totp: t('auth.link_account.totp_label'),
        recovery_code: t('auth.link_account.recovery_label'),
        sms: t('auth.link_account.sms_label'),
        password: t('auth.link_account.password_label'),
        email_code: t('auth.link_account.email_label'),
    };

    const isCode = method === 'totp' || method === 'sms' || method === 'email_code';

    return (
        <>
            <div className="flex items-center gap-2.5" aria-hidden="true">
                <span
                    className="inline-flex items-center justify-center rounded-lg"
                    style={{
                        width: '2.5rem',
                        height: '2.5rem',
                        border: '1px solid var(--border)',
                        background: 'var(--card)',
                    }}
                >
                    <ProviderMark provider={provider.key} size={22} />
                </span>
                <Icon name="switch" className="w-4 h-4" style={{ color: 'var(--faint)' }} />
                <span
                    className="inline-flex items-center justify-center rounded-lg"
                    style={{
                        width: '2.5rem',
                        height: '2.5rem',
                        border: '1px solid var(--border)',
                        background: 'var(--card)',
                    }}
                >
                    <Icon name="user" className="w-5 h-5" />
                </span>
            </div>

            <h1
                className="mt-5 font-semibold tracking-tight"
                style={{ fontSize: '1.55rem', overflowWrap: 'anywhere' }}
            >
                {rich('auth.link_account.heading', {
                    provider: provider.label,
                    email: <span>{email}</span>,
                })}
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {t('auth.link_account.lead', { provider: provider.label })}
            </p>

            {challenged && (
                <p
                    className="mt-4 rounded-lg px-3.5 py-2.5 text-sm"
                    style={{ background: 'var(--secondary)', border: '1px solid var(--border)' }}
                >
                    {t('auth.link_account.challenged')}
                </p>
            )}

            {(sent !== undefined || (method === 'email_code' && codeOnItsWay)) && (
                <output
                    className="mt-4 rounded-lg px-3.5 py-2.5 text-sm flex items-start gap-2"
                    style={{ background: 'var(--success-soft)', color: 'var(--success-strong)' }}
                >
                    <Icon name="mail" className="w-4 h-4 shrink-0 mt-0.5" />
                    <span>{sent ?? t('auth.link_account.email_sent', { email })}</span>
                </output>
            )}

            <form
                className="mt-6 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(verify.url(), { preserveScroll: true });
                }}
            >
                {method === 'password' ? (
                    <PasswordField
                        id="answer"
                        name="answer"
                        label={label.password}
                        showLabel={t('auth.password_field.show')}
                        hideLabel={t('auth.password_field.hide')}
                        autoComplete="current-password"
                        autoFocus
                        error={form.errors.answer}
                        value={form.data.answer}
                        onChange={(event) => form.setData('answer', event.target.value)}
                    />
                ) : (
                    <Field id="answer" label={label[method]} error={form.errors.answer}>
                        <Input
                            name="answer"
                            scale="lg"
                            className={isCode ? 'mono' : undefined}
                            style={
                                isCode ? { letterSpacing: '0.4em', textAlign: 'center' } : undefined
                            }
                            inputMode={isCode ? 'numeric' : 'text'}
                            autoComplete={isCode ? 'one-time-code' : 'off'}
                            autoCapitalize="none"
                            spellCheck={false}
                            autoFocus
                            maxLength={isCode ? 6 : 64}
                            placeholder={isCode ? '000000' : undefined}
                            value={form.data.answer}
                            onChange={(event) => form.setData('answer', event.target.value)}
                        />
                    </Field>
                )}

                <Button
                    type="submit"
                    variant="primary"
                    size="lg"
                    className="w-full"
                    loading={form.processing}
                >
                    {t('auth.link_account.submit')}
                </Button>
            </form>

            <div className="mt-4 flex flex-col items-start gap-2.5 text-sm">
                {method === 'totp' && (
                    <AltLink onClick={() => choose('recovery_code')}>
                        {t('auth.link_account.use_recovery')}
                    </AltLink>
                )}
                {method === 'recovery_code' && (
                    <AltLink onClick={() => choose('totp')}>
                        {t('auth.link_account.use_totp')}
                    </AltLink>
                )}
                {factors.sms && (
                    <AltLink onClick={() => send('sms')}>
                        {method === 'sms'
                            ? t('auth.link_account.send_sms')
                            : t('auth.link_account.use_sms')}
                    </AltLink>
                )}
                {method === 'password' && factors.email && (
                    <AltLink onClick={() => send('email')}>
                        {t('auth.link_account.send_email')}
                    </AltLink>
                )}
                {method === 'email_code' && (
                    <AltLink onClick={() => send('email')}>
                        {t('auth.link_account.resend_email')}
                    </AltLink>
                )}
                {method === 'email_code' && factors.password && (
                    <AltLink onClick={() => choose('password')}>
                        {t('auth.link_account.use_password')}
                    </AltLink>
                )}
            </div>

            <div className="mt-7 pt-5" style={{ borderTop: '1px solid var(--border)' }}>
                <Button
                    size="lg"
                    className="w-full"
                    onClick={() => router.post(cancel.url(), { hold })}
                >
                    {t('auth.link_account.cancel')}
                </Button>
                <p className="mt-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {t('auth.link_account.not_you', { provider: provider.label })}
                </p>
            </div>
        </>
    );
}

function AltLink({ onClick, children }: { onClick: () => void; children: React.ReactNode }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="underline underline-offset-2 text-left"
            style={{ color: 'var(--accent-strong)' }}
        >
            {children}
        </button>
    );
}

LinkAccount.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
