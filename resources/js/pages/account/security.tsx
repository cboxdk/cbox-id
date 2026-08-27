import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { isCancellation, PasskeyError, passkeysSupported, registerPasskey } from '@/lib/passkeys';
import type { HelpContent, PageProps } from '@/types';
import {
    Button,
    CopyButton,
    Dialog,
    Field,
    Icon,
    Input,
    Kv,
    KvList,
    PageHeader,
    Panel,
    PasswordField,
    PasswordManagerIdentity,
    Pill,
} from '@/ui';

interface PasskeyRow {
    id: string;
    name: string;
    added: string | null;
    signCount: number;
    removeHref: string;
}

interface SocialProvider {
    key: string;
    label: string;
    linked: boolean;
    connectHref: string;
    disconnectHref: string;
}

type Props = PageProps<{
    help: HelpContent;
    returnTo: { url: string; host: string } | null;
    profile: {
        name: string;
        email: string | null;
        initial: string;
        organization: { name: string; role: string } | null;
    };
    hasPassword: boolean;
    twoFactor: {
        enabled: boolean;
        offered: boolean;
        recoveryRemaining: number;
    };
    passkeys: PasskeyRow[];
    socialProviders: SocialProvider[];
    session: { id: string; methods: string[]; signedIn: string | null } | null;
    otherSessions: number;
    urls: {
        profile: string;
        password: string;
        enrolMfa: string;
        confirmMfa: string;
        recoveryCodes: string;
        signOutOthers: string;
        logout: string;
        activity: string;
    };
}>;

export default function Security({
    help,
    returnTo,
    profile,
    hasPassword,
    twoFactor,
    passkeys,
    socialProviders,
    session,
    otherSessions,
    urls,
}: Props) {
    return (
        <div className="space-y-6">
            {returnTo !== null && (
                /*
                    A LINK, never an automatic redirect, and only to a host this environment
                    already redirects to — the server decides both. An IdP page that follows
                    `?return_to=` on its own is an open redirect with a sign-in form on it.
                */
                <a
                    href={returnTo.url}
                    className="inline-flex items-center gap-2 text-sm font-medium"
                    style={{ color: 'var(--accent-strong)' }}
                >
                    <Icon
                        name="chevron"
                        className="w-4 h-4"
                        style={{ transform: 'rotate(90deg)' }}
                    />
                    Return to {returnTo.host}
                </a>
            )}

            <PageHeader
                help={help}
                description="Your profile, how you sign in, and the sessions currently signed in as you."
            />

            <ProfilePanel profile={profile} href={urls.profile} />

            <PasswordPanel
                hasPassword={hasPassword}
                email={profile.email}
                href={urls.password}
            />

            <TwoFactorPanel twoFactor={twoFactor} urls={urls} />

            <PasskeyPanel passkeys={passkeys} name={profile.name} />

            {socialProviders.length > 0 && <SocialPanel providers={socialProviders} />}

            <SessionPanel
                session={session}
                otherSessions={otherSessions}
                urls={urls}
            />
        </div>
    );
}

function ProfilePanel({ profile, href }: { profile: Props['profile']; href: string }) {
    const form = useForm({ displayName: profile.name });

    return (
        <Panel title="Profile" description="How you appear across Cbox ID.">
            <form
                className="flex items-start gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, { preserveScroll: true });
                }}
            >
                <span
                    className="cbx-avatar shrink-0"
                    aria-hidden="true"
                    style={{ width: '3rem', height: '3rem', fontSize: '1.1rem' }}
                >
                    {profile.initial}
                </span>

                <div className="min-w-0 flex-1 space-y-4">
                    <Field label="Name" error={form.errors.displayName}>
                        <Input
                            name="displayName"
                            maxLength={120}
                            autoComplete="name"
                            value={form.data.displayName}
                            onChange={(event) => form.setData('displayName', event.target.value)}
                        />
                    </Field>

                    <KvList>
                        {/*
                            Read-only on purpose. The email is the sign-in identifier, and
                            changing it without re-proving control of the new address is how
                            an account gets taken over — so it moves through a verification
                            flow, not a text field on this page.
                        */}
                        <Kv label="Email">{profile.email ?? '—'}</Kv>
                        {profile.organization !== null && (
                            <Kv label="Organization" prose>
                                {profile.organization.name}{' '}
                                <Pill dot={false}>{profile.organization.role}</Pill>
                            </Kv>
                        )}
                    </KvList>

                    <Button type="submit" variant="primary" loading={form.processing}>
                        Save name
                    </Button>
                </div>
            </form>
        </Panel>
    );
}

function PasswordPanel({
    hasPassword,
    email,
    href,
}: {
    hasPassword: boolean;
    email: string | null;
    href: string;
}) {
    const form = useForm({
        currentPassword: '',
        newPassword: '',
        newPasswordConfirmation: '',
    });

    return (
        <Panel
            title="Password"
            description={
                hasPassword
                    ? 'Change the password you use to sign in.'
                    : 'Set a password to sign in without a social account or passkey.'
            }
        >
            <form
                className="grid gap-4"
                style={{ maxWidth: '28rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(href, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                {/*
                    So a password manager UPDATES the saved credential rather than storing a
                    second entry — a page that saves a duplicate is a page that has locked
                    somebody out slowly.
                */}
                <PasswordManagerIdentity username={email ?? undefined} />

                {hasPassword && (
                    <PasswordField
                        label="Current password"
                        name="currentPassword"
                        autoComplete="current-password"
                        error={form.errors.currentPassword}
                        value={form.data.currentPassword}
                        onChange={(event) => form.setData('currentPassword', event.target.value)}
                    />
                )}

                <PasswordField
                    label="New password"
                    name="newPassword"
                    autoComplete="new-password"
                    policy
                    error={form.errors.newPassword}
                    value={form.data.newPassword}
                    onChange={(event) => form.setData('newPassword', event.target.value)}
                />

                <PasswordField
                    label="Confirm new password"
                    name="newPasswordConfirmation"
                    autoComplete="new-password"
                    error={form.errors.newPasswordConfirmation}
                    value={form.data.newPasswordConfirmation}
                    onChange={(event) =>
                        form.setData('newPasswordConfirmation', event.target.value)
                    }
                />

                <div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {hasPassword ? 'Update password' : 'Set password'}
                    </Button>
                </div>
            </form>
        </Panel>
    );
}

function TwoFactorPanel({
    twoFactor,
    urls,
}: {
    twoFactor: Props['twoFactor'];
    urls: Props['urls'];
}) {
    /*
        THE SECRET AND THE RECOVERY CODES ARRIVE ON THE FLASH CHANNEL, which Inertia never
        writes into the history entry — so a back button, a restored tab or a shared session
        snapshot cannot resurrect a credential that was meant to be shown once.
    */
    const { mfaSecret, mfaQrCode, recoveryCodes } = usePage().flash;

    const form = useForm({ code: '' });
    const [regenerating, setRegenerating] = useState(false);

    const enrolling = typeof mfaSecret === 'string' && typeof mfaQrCode === 'string';
    const codes = Array.isArray(recoveryCodes) ? (recoveryCodes as string[]) : null;

    return (
        <Panel
            title="Two-factor authentication"
            description="An authenticator app adds a second step when you sign in."
            action={twoFactor.enabled && <Pill tone="success">Enabled</Pill>}
        >
            {twoFactor.enabled ? (
                <>
                    <p className="text-sm" style={{ color: 'var(--muted)' }}>
                        Your account is protected with an authenticator app.
                    </p>

                    <div
                        className="mt-4 pt-4"
                        style={{ borderTop: '1px solid var(--border)' }}
                    >
                        <div className="flex items-center gap-2 flex-wrap mb-1">
                            <h3 className="font-medium text-sm">Recovery codes</h3>
                            <Pill dot={false}>{twoFactor.recoveryRemaining} left</Pill>
                        </div>
                        <p className="text-sm" style={{ color: 'var(--muted)' }}>
                            Single-use codes to sign in if you lose your authenticator.
                        </p>

                        {codes !== null && <RecoveryCodes codes={codes} />}

                        <Button className="mt-3" onClick={() => setRegenerating(true)}>
                            <Icon name="refresh" className="w-4 h-4" />
                            {twoFactor.recoveryRemaining > 0
                                ? 'Regenerate codes'
                                : 'Generate codes'}
                        </Button>
                    </div>

                    <Dialog
                        open={regenerating}
                        onOpenChange={setRegenerating}
                        title="Generate new recovery codes?"
                        description="Your existing codes stop working immediately. The new ones are shown once."
                        footer={
                            <>
                                <Button onClick={() => setRegenerating(false)}>Cancel</Button>
                                <Button
                                    variant="primary"
                                    onClick={() => {
                                        setRegenerating(false);
                                        router.post(
                                            urls.recoveryCodes,
                                            {},
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    Generate
                                </Button>
                            </>
                        }
                    />
                </>
            ) : !twoFactor.offered ? (
                /*
                    The administrator has turned second factors off for this deployment.
                    Said out loud rather than by an absent button, because somebody looking
                    for this panel deserves to know it was a decision and not a missing
                    feature.
                */
                <p className="text-sm" style={{ color: 'var(--muted)' }}>
                    Your administrator has turned off two-factor authentication for this
                    environment.
                </p>
            ) : !enrolling ? (
                <Button
                    variant="primary"
                    onClick={() => router.post(urls.enrolMfa, {}, { preserveScroll: true })}
                >
                    <Icon name="key" className="w-4 h-4" /> Enable 2FA
                </Button>
            ) : (
                <div className="space-y-4">
                    <ol className="text-sm space-y-1" style={{ color: 'var(--muted)' }}>
                        <li>
                            1. Scan the QR code with your authenticator app or password
                            manager — or add the key manually.
                        </li>
                        <li>2. Enter the 6-digit code it shows.</li>
                    </ol>

                    <div className="flex flex-col sm:flex-row gap-4 items-start">
                        <div
                            className="shrink-0 rounded-xl p-3"
                            style={{ background: '#fff', lineHeight: 0 }}
                        >
                            {/*
                                An <img> over a data: URI, not injected SVG markup. The
                                server generates it from the provisioning URI, so nothing
                                untrusted is involved either way — but an <img> cannot
                                execute what it is handed, and that stays true if the code
                                ever moves. On a white plate because a QR must be
                                dark-on-light to scan.
                            */}
                            <img src={mfaQrCode} alt="Authenticator setup QR code" width={220} height={220} />
                        </div>

                        <div className="min-w-0 flex-1 w-full">
                            <span className="label">Setup key (manual entry)</span>
                            <div className="flex items-stretch gap-2">
                                <p
                                    className="mono text-sm p-3 rounded-lg select-all break-all flex-1 min-w-0"
                                    style={{
                                        background: 'var(--surface-2)',
                                        border: '1px solid var(--border)',
                                    }}
                                >
                                    {mfaSecret}
                                </p>
                                <CopyButton
                                    value={mfaSecret}
                                    className="shrink-0"
                                    aria-label="Copy setup key"
                                />
                            </div>
                        </div>
                    </div>

                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(urls.confirmMfa, {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <div style={{ minWidth: '10rem' }}>
                            <Field label="6-digit code" error={form.errors.code}>
                                <Input
                                    name="code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    className="mono"
                                    placeholder="000000"
                                    value={form.data.code}
                                    onChange={(event) => form.setData('code', event.target.value)}
                                />
                            </Field>
                        </div>
                        <Button type="submit" variant="primary" loading={form.processing}>
                            Confirm
                        </Button>
                        {/*
                            A reload rather than local state: the secret lives on the flash
                            channel, so leaving the flow means dropping it, and the honest way
                            to drop it is to ask the server for the page again.
                        */}
                        <Button type="button" onClick={() => router.reload()}>
                            Cancel
                        </Button>
                    </form>
                </div>
            )}
        </Panel>
    );
}

function RecoveryCodes({ codes }: { codes: string[] }) {
    return (
        <>
            <div
                className="mt-3 p-3 rounded-lg grid grid-cols-2 gap-x-6 gap-y-1 mono text-sm select-all"
                style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
            >
                {codes.map((code) => (
                    <span key={code}>{code}</span>
                ))}
            </div>
            <div className="mt-2 flex items-center gap-3 flex-wrap">
                <CopyButton
                    value={codes.join('\n')}
                    label="Copy all codes"
                    aria-label="Copy all recovery codes"
                />
                <p className="text-xs" style={{ color: 'var(--destructive-strong)' }}>
                    Shown only once — save them now.
                </p>
            </div>
        </>
    );
}

function PasskeyPanel({ passkeys, name }: { passkeys: PasskeyRow[]; name: string }) {
    /*
     * READ ONCE, LAZILY, rather than in an effect.
     *
     * `window` does not exist while the markup is produced, so this cannot be a plain
     * initialiser — but a lazy one runs on the client's first render, which is the first
     * moment the question has an answer. An effect would set state a render later and
     * flash the enrolment button in where it cannot work.
     */
    const [supported] = useState(() => passkeysSupported());
    const [message, setMessage] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const [removing, setRemoving] = useState<PasskeyRow | null>(null);

    const add = useCallback(async () => {
        setBusy(true);
        setMessage(null);

        try {
            await registerPasskey(`${name}'s device`);
            router.reload({ only: ['passkeys'] });
        } catch (error) {
            // A person who dismissed the platform's own prompt did not fail at anything,
            // and telling them they did is how a page teaches somebody it is broken.
            setMessage(
                isCancellation(error)
                    ? null
                    : error instanceof PasskeyError
                      ? error.message
                      : 'That passkey could not be added.',
            );
        } finally {
            setBusy(false);
        }
    }, [name]);

    /*
     * THE PANEL STAYS EVEN WHERE PASSKEYS DO NOT WORK — only the enrolment button goes.
     *
     * The old page hid the whole section on a browser without WebAuthn, which meant
     * somebody who enrolled a passkey on their phone could not SEE it, let alone remove
     * it, from a work desktop whose browser lacks the API. Revoking a credential is
     * exactly what you want to do from the machine that cannot use it. So the list is
     * always here, and what is withheld is the affordance that would fail: adding one.
     */
    return (
        <Panel
            title="Passkeys"
            description="Sign in with Face ID, Touch ID, Windows Hello, or a security key — no password."
            action={
                supported && (
                    <Button variant="primary" className="shrink-0" loading={busy} onClick={add}>
                        <Icon name="plus" className="w-4 h-4" /> Add passkey
                    </Button>
                )
            }
        >
            <output className="text-xs block mb-2" style={{ minHeight: '1rem' }}>
                {message}
            </output>

            {!supported && (
                <p className="text-sm mb-3" style={{ color: 'var(--muted-foreground)' }}>
                    This browser cannot create a passkey, so there is nothing to add here —
                    but any you already have are listed below, and you can remove them.
                </p>
            )}

            {passkeys.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--faint)' }}>
                    No passkeys registered yet.
                </p>
            ) : (
                <ul className="divide-y" style={{ borderColor: 'var(--border)' }}>
                    {passkeys.map((passkey) => (
                        <li
                            key={passkey.id}
                            className="flex items-center justify-between gap-4 py-3"
                        >
                            <div className="flex items-center gap-3 min-w-0">
                                <Icon
                                    name="shield"
                                    className="w-4 h-4 shrink-0"
                                    style={{ color: 'var(--success-strong)' }}
                                />
                                <div className="min-w-0">
                                    <p className="text-sm font-medium truncate">
                                        {passkey.name}
                                    </p>
                                    <p
                                        className="text-xs"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        Added {passkey.added ?? 'unknown'} · sign-count{' '}
                                        {passkey.signCount}
                                    </p>
                                </div>
                            </div>
                            <Button
                                size="sm"
                                variant="danger"
                                aria-label={`Remove ${passkey.name}`}
                                onClick={() => setRemoving(passkey)}
                            >
                                Remove
                            </Button>
                        </li>
                    ))}
                </ul>
            )}

            {/*
                An ordinary confirm rather than type-to-confirm: this is the reader's own
                account, where the two-identical-tabs hazard that dialog exists for does not
                apply — and a passkey can be added again from this very panel.
            */}
            <Dialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={removing === null ? '' : `Remove ${removing.name}?`}
                description="That device stops being able to sign you in. You can add it again from this page."
                footer={
                    <>
                        <Button onClick={() => setRemoving(null)}>Cancel</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                const passkey = removing;
                                setRemoving(null);

                                if (passkey !== null) {
                                    router.delete(passkey.removeHref, { preserveScroll: true });
                                }
                            }}
                        >
                            Remove
                        </Button>
                    </>
                }
            />
        </Panel>
    );
}

function SocialPanel({ providers }: { providers: SocialProvider[] }) {
    const { errors } = usePage().props;
    const [disconnecting, setDisconnecting] = useState<SocialProvider | null>(null);
    const unlinkError = typeof errors.unlink === 'string' ? errors.unlink : null;

    return (
        <Panel
            title="Connected accounts"
            description="Link a social account to sign in with it. Linking is deliberate — we never merge accounts by email automatically."
        >
            <ul className="divide-y" style={{ borderColor: 'var(--border)' }}>
                {providers.map((provider) => (
                    <li
                        key={provider.key}
                        className="flex items-center justify-between gap-4 py-3"
                    >
                        <div className="flex items-center gap-3">
                            <span className="font-medium">{provider.label}</span>
                            {provider.linked && <Pill tone="success">Connected</Pill>}
                        </div>
                        {provider.linked ? (
                            <Button
                                size="sm"
                                variant="danger"
                                aria-label={`Disconnect ${provider.label}`}
                                onClick={() => setDisconnecting(provider)}
                            >
                                Disconnect
                            </Button>
                        ) : (
                            <Button asChild size="sm">
                                <a href={provider.connectHref}>Connect</a>
                            </Button>
                        )}
                    </li>
                ))}
            </ul>

            {unlinkError !== null && (
                <p className="field-error mt-2" role="alert">
                    {unlinkError}
                </p>
            )}

            <Dialog
                open={disconnecting !== null}
                onOpenChange={(open) => !open && setDisconnecting(null)}
                title={disconnecting === null ? '' : `Disconnect ${disconnecting.label}?`}
                description="You stop being able to sign in with it. If it is your only way in, the change is refused."
                footer={
                    <>
                        <Button onClick={() => setDisconnecting(null)}>Cancel</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                const provider = disconnecting;
                                setDisconnecting(null);

                                if (provider !== null) {
                                    router.delete(provider.disconnectHref, {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            Disconnect
                        </Button>
                    </>
                }
            />
        </Panel>
    );
}

function SessionPanel({
    session,
    otherSessions,
    urls,
}: {
    session: Props['session'];
    otherSessions: number;
    urls: Props['urls'];
}) {
    const [signingOut, setSigningOut] = useState(false);

    return (
        <Panel
            title="Current session"
            description="The session you are signed in with right now."
        >
            {session === null ? (
                <p className="text-sm" style={{ color: 'var(--faint)' }}>
                    No active session details are available.
                </p>
            ) : (
                <KvList>
                    <Kv label="Authentication methods" prose>
                        <span className="flex flex-wrap gap-1.5">
                            {session.methods.length === 0 ? (
                                <span className="text-sm" style={{ color: 'var(--faint)' }}>
                                    —
                                </span>
                            ) : (
                                session.methods.map((method) => (
                                    <Pill key={method} dot={false}>
                                        {method}
                                    </Pill>
                                ))
                            )}
                        </span>
                    </Kv>
                    <Kv label="Signed in">{session.signedIn ?? '—'}</Kv>
                    <Kv label="Session ID">{session.id}</Kv>
                </KvList>
            )}

            <div
                className="mt-5 pt-4 flex flex-wrap items-center gap-3"
                style={{ borderTop: '1px solid var(--border)' }}
            >
                <form method="POST" action={urls.logout}>
                    <Button type="submit" variant="danger">
                        <Icon name="logout" className="w-4 h-4" /> Sign out
                    </Button>
                </form>

                {otherSessions > 0 && (
                    <Button onClick={() => setSigningOut(true)}>
                        <Icon name="logout" className="w-4 h-4" /> Sign out other sessions (
                        {otherSessions})
                    </Button>
                )}

                {/*
                    The fuller view — every session named by device, and what can act as you
                    — is its own page. Linked from here so somebody who came looking for it
                    on the security page is not left thinking the count is all there is.
                */}
                <Link
                    href={urls.activity}
                    className="text-sm underline"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    See where you are signed in
                </Link>
            </div>

            <Dialog
                open={signingOut}
                onOpenChange={setSigningOut}
                title={`Sign out of your ${otherSessions} other ${otherSessions === 1 ? 'session' : 'sessions'}?`}
                description="Every other device holding a session as you stops working on its next request. This one stays signed in."
                footer={
                    <>
                        <Button onClick={() => setSigningOut(false)}>Cancel</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                setSigningOut(false);
                                router.post(urls.signOutOthers, {}, { preserveScroll: true });
                            }}
                        >
                            Sign out
                        </Button>
                    </>
                }
            />
        </Panel>
    );
}

Security.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
