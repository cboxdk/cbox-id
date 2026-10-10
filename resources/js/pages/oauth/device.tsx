import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import { formatDeviceCode } from '@/lib/deviceCode';
import type { PageProps } from '@/types';
import { Button, Field, Icon, Input, Pill } from '@/ui';

interface ScopeRow {
    scope: string;
    label: string;
    /** A management-plane scope: what the device may DO as this person. */
    management?: boolean;
    /** Some critical action needs it. */
    critical?: boolean;
}

type Props = PageProps<{
    client: { name: string; scopes: ScopeRow[] } | null;
    /** The code being approved — read back from the session, shown to compare with the TV. */
    userCode: string | null;
    me: { name: string; email: string | null; initial: string };
    urls: { lookup: string; approve: string; deny: string; start: string };
}>;

/**
 * THE DEVICE SIGN-IN PAGE (RFC 8628) — opened on a phone, usually by scanning the QR code
 * a TV is showing, by somebody who has never seen this product's console.
 *
 * So it is a hosted door, not a console page: the brand of whoever's app is on the TV, the
 * visitor's language, one column, big targets, and three steps that each say exactly one
 * thing — the code, what is asking, and "you can go back to your TV now".
 */
export default function Device({ client, userCode, me, urls }: Props) {
    const { deviceOutcome, deviceError } = usePage().flash;
    const { t } = useTranslator();

    if (deviceOutcome === 'approved') {
        return (
            <Outcome
                tone="success"
                title={t('oauth.device.approved_heading')}
                body={t('oauth.device.approved_body')}
            />
        );
    }

    if (deviceOutcome === 'denied') {
        return (
            <Outcome
                tone="neutral"
                title={t('oauth.device.denied_heading')}
                body={t('oauth.device.denied_body')}
                again={urls.start}
            />
        );
    }

    if (client !== null) {
        return <Consent client={client} userCode={userCode} me={me} urls={urls} />;
    }

    return <CodeForm href={urls.lookup} error={deviceError} />;
}

/** The device's own picture, on every step, so the page reads as "the TV thing" at a glance. */
function DeviceMark() {
    return (
        <span
            aria-hidden="true"
            className="grid place-items-center rounded-2xl"
            style={{
                width: '3rem',
                height: '3rem',
                background: 'var(--accent-soft)',
                color: 'var(--accent-strong)',
            }}
        >
            <Icon name="device" className="w-6 h-6" />
        </span>
    );
}

/**
 * The end of the flow. The success screen says the ONE thing the person needs next — go
 * back to the TV — and offers nothing else to click: there is nowhere else they meant to go.
 */
function Outcome({
    tone,
    title,
    body,
    again,
}: {
    tone: 'success' | 'neutral';
    title: string;
    body: string;
    again?: string;
}) {
    const { t } = useTranslator();

    return (
        <output className="block text-center" data-testid="device-outcome">
            <span
                aria-hidden="true"
                className="mx-auto grid place-items-center rounded-full"
                style={{
                    width: '3.5rem',
                    height: '3.5rem',
                    background: tone === 'success' ? 'var(--success-soft)' : 'var(--secondary)',
                    color: tone === 'success' ? 'var(--success-strong)' : 'var(--muted-foreground)',
                }}
            >
                <Icon name={tone === 'success' ? 'check' : 'close'} className="w-7 h-7" />
            </span>
            <h1 className="mt-5 font-semibold tracking-tight" style={{ fontSize: '1.6rem' }}>
                {title}
            </h1>
            <p className="mt-2 text-[15px]" style={{ color: 'var(--muted-foreground)' }}>
                {body}
            </p>
            {again !== undefined && (
                <Link href={again} className="btn btn-ghost btn-lg w-full mt-7">
                    {t('oauth.device.enter_another')}
                </Link>
            )}
        </output>
    );
}

/** Step 2: what is being authorized, before it is authorized. */
function Consent({
    client,
    userCode,
    me,
    urls,
}: {
    client: NonNullable<Props['client']>;
    userCode: string | null;
    me: Props['me'];
    urls: Props['urls'];
}) {
    const approve = useForm({});
    const deny = useForm({});
    const { errors } = usePage().props;
    const { t } = useTranslator();
    const error = typeof errors.userCode === 'string' ? errors.userCode : null;
    const actsAsYou = client.scopes.some((row) => row.management === true);
    const anyCritical = client.scopes.some((row) => row.critical === true);

    return (
        <div>
            <DeviceMark />

            <h1 className="mt-5 font-semibold tracking-tight" style={{ fontSize: '1.6rem' }}>
                {t('oauth.device.consent_heading', { client: client.name })}
            </h1>
            <p className="mt-2 text-[15px]" style={{ color: 'var(--muted-foreground)' }}>
                {t('oauth.device.consent_lead', { client: client.name })}
            </p>

            {/*
                THE CODE, TO HOLD AGAINST THE TV. Device-code phishing works by getting a
                victim to approve a code the ATTACKER's device is showing; the one defence a
                person has is noticing that this code is not the one on their own screen.
            */}
            {userCode !== null && (
                <div
                    className="mt-6 rounded-xl px-4 py-3.5 text-center"
                    style={{ background: 'var(--secondary)', border: '1px solid var(--border)' }}
                >
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {t('oauth.device.code_check')}
                    </p>
                    <p
                        className="mono mt-1 font-semibold"
                        style={{ fontSize: '1.6rem', letterSpacing: '0.18em' }}
                        data-testid="device-code"
                    >
                        {userCode}
                    </p>
                </div>
            )}

            {/*
                WHICH ACCOUNT. A person may hold several, and the one being connected is
                whichever this browser is signed in as — not necessarily the one they were
                thinking of when they picked up the remote.
            */}
            <div
                className="mt-4 flex items-center gap-3 rounded-xl px-3.5 py-3"
                style={{ border: '1px solid var(--border)' }}
            >
                <span
                    aria-hidden="true"
                    className="grid place-items-center rounded-full text-sm font-semibold shrink-0"
                    style={{
                        width: '2.25rem',
                        height: '2.25rem',
                        background: 'var(--accent-soft)',
                        color: 'var(--accent-strong)',
                    }}
                >
                    {me.initial}
                </span>
                <div className="min-w-0">
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {t('oauth.device.signing_in_as')}
                    </p>
                    <p className="text-sm font-medium truncate">{me.name}</p>
                    {me.email !== null && me.email !== me.name && (
                        <p
                            className="text-xs truncate"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {me.email}
                        </p>
                    )}
                </div>
            </div>

            {client.scopes.length > 0 && (
                <>
                    <h2 className="mt-6 text-sm font-semibold">
                        {t('oauth.consent.will_allow', { client: client.name })}
                    </h2>
                    <ul className="mt-2.5 space-y-2.5">
                        {client.scopes.map((row) => (
                            <li key={row.scope} className="flex items-center gap-2.5 text-[15px]">
                                <Icon
                                    name={row.critical === true ? 'warning' : 'check'}
                                    className="w-4 h-4 shrink-0"
                                    style={{
                                        color:
                                            row.critical === true
                                                ? 'var(--destructive-strong)'
                                                : 'var(--success-strong)',
                                    }}
                                />
                                <span>{row.label}</span>
                                {row.critical === true && (
                                    <Pill tone="destructive" dot={false}>
                                        {t('oauth.consent.critical')}
                                    </Pill>
                                )}
                            </li>
                        ))}
                    </ul>
                    {/*
                        A CLI signed in with management scopes acts as this person on the
                        management plane — say what bounds it, and that critical actions will
                        still come back here to be approved.
                    */}
                    {actsAsYou && (
                        <p className="mt-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            {t('oauth.consent.acts_as_you')}
                            {anyCritical && <> {t('oauth.consent.critical_notice')}</>}
                        </p>
                    )}
                </>
            )}

            {error !== null && (
                <p className="field-error mt-4" role="alert">
                    {error}
                </p>
            )}

            {/*
                DENY FIRST, and not for symmetry: somebody who does not recognise this
                request is the person this screen most has to serve, and the safe answer
                should not be the one they have to look for. Stacked full-width on a phone,
                side by side where there is room.
            */}
            <div className="mt-7 grid gap-2.5 sm:grid-cols-2">
                <Button
                    size="lg"
                    className="w-full"
                    loading={deny.processing}
                    disabled={approve.processing}
                    onClick={() => deny.post(urls.deny)}
                >
                    {t('oauth.device.deny')}
                </Button>
                <Button
                    variant="primary"
                    size="lg"
                    className="w-full"
                    loading={approve.processing}
                    disabled={deny.processing}
                    onClick={() => approve.post(urls.approve)}
                >
                    {t('oauth.device.approve')}
                </Button>
            </div>

            <p
                className="mt-5 text-xs leading-relaxed"
                style={{ color: 'var(--muted-foreground)' }}
            >
                {t('oauth.device.warning')}
            </p>
        </div>
    );
}

/**
 * Step 1: the code shown on the device.
 *
 * Forgiving on purpose (RFC 8628 §6.1): the code is read off a screen across a room and
 * typed on a phone keyboard that lower-cases, autocorrects and swaps a dash for a space.
 * The field upper-cases as it goes and puts the dash in itself, and the server accepts the
 * code with or without either.
 */
function CodeForm({ href, error }: { href: string; error?: string }) {
    const form = useForm({ userCode: '' });
    const { t } = useTranslator();
    const [typed, setTyped] = useState('');

    return (
        <div>
            <DeviceMark />

            <h1 className="mt-5 font-semibold tracking-tight" style={{ fontSize: '1.6rem' }}>
                {t('oauth.device.heading')}
            </h1>
            <p className="mt-2 text-[15px]" style={{ color: 'var(--muted-foreground)' }}>
                {t('oauth.device.lead')}
            </p>

            <form
                className="mt-7 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(href);
                }}
            >
                {error !== undefined && (
                    <div
                        role="alert"
                        className="rounded-lg px-3.5 py-2.5 text-sm"
                        style={{
                            background: 'var(--destructive-soft)',
                            color: 'var(--destructive-strong)',
                        }}
                    >
                        {error}
                    </div>
                )}

                <Field
                    id="userCode"
                    label={t('oauth.device.code_label')}
                    hint={t('oauth.device.code_hint')}
                    error={form.errors.userCode}
                >
                    {/*
                        autocomplete="off", NOT "one-time-code".

                        `one-time-code` means "a code delivered out of band TO THIS DEVICE" —
                        an SMS or authenticator OTP — and it is the signal Safari, iOS and
                        every password manager use to offer the last such code they saw. A
                        device-authorization user_code travels the OTHER way: shown on another
                        device's screen and typed in here. Asking for OTP autofill silently
                        REPLACED the code with an unrelated six-digit one.

                        `inputMode="text"` with `autoCapitalize="characters"`: the codes are
                        letters, so a phone opens its letter keyboard with caps on. Autofocus,
                        because typing this code is the only reason the page was opened.
                    */}
                    <Input
                        name="userCode"
                        autoFocus
                        autoComplete="off"
                        autoCorrect="off"
                        data-1p-ignore
                        data-lpignore="true"
                        data-form-type="other"
                        inputMode="text"
                        enterKeyHint="go"
                        autoCapitalize="characters"
                        spellCheck={false}
                        maxLength={9}
                        scale="lg"
                        className="mono text-center"
                        style={{ fontSize: '1.6rem', letterSpacing: '0.18em', height: '3.5rem' }}
                        placeholder="XXXX-XXXX"
                        value={typed}
                        onChange={(event) => {
                            const next = formatDeviceCode(event.target.value);
                            setTyped(next);
                            form.setData('userCode', next);
                        }}
                    />
                </Field>

                <Button
                    type="submit"
                    variant="primary"
                    size="lg"
                    className="w-full"
                    loading={form.processing}
                >
                    {t('oauth.device.continue')}
                </Button>
                <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {t('oauth.device.next_note')}
                </p>
            </form>
        </div>
    );
}

Device.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
