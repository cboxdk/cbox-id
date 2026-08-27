import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AuthLayout from '@/layouts/AuthLayout';
import { isCancellation, passkeysSupported, signInWithPasskey } from '@/lib/passkeys';
import type { PageProps } from '@/types';
import { Button, Divider, Field, Input, PasswordField, ProviderMark } from '@/ui';
import { login as loginRoute, signup } from '@routes';
import { attempt, identify, magicLink } from '@routes/login';
import { request as forgotPassword } from '@routes/password';

interface SocialProvider {
    provider: string;
    label: string;
    url: string;
}

type Props = PageProps<{
    /** Why this person is being asked to sign in, when the server knows. */
    purpose: string;
    /** What the identifier step captured. The server's old-input bag, stated as a prop. */
    email: string;
    /** A social sign-in whose address already belongs to somebody here, waiting to be confirmed. */
    pendingLink: string | null;
    signupOpen: boolean;
    providers: SocialProvider[];
}>;

export default function Login({ purpose, email, pendingLink, signupOpen, providers }: Props) {
    /*
     * ON THE FLASH CHANNEL, not in props.
     *
     * Every one of these is a step in a flow rather than state: the identifier step
     * passed, a link was sent, a mandate refused this attempt. Props are written into the
     * browser's history entry, so a person pressing Back would land on a page still
     * claiming their address had been identified — or still showing a mandate that has
     * since been spent.
     */
    const { identified, ssoOffer, ssoOfferLeads, magicSentTo, magicUrl, mandate } =
        usePage().flash;

    const form = useForm({ email, password: '' });

    const [passkeyMessage, setPasskeyMessage] = useState<{ text: string; ok: boolean } | null>(null);
    const [passkeyBusy, setPasskeyBusy] = useState(false);
    // A lazy initialiser, not an effect: reading it after mount renders one frame with
    // the passkey button ABSENT and then adds it, which moves the two buttons below it
    // under the cursor at exactly the moment somebody is reaching for one.
    //
    // Guarded on `window` inside `passkeysSupported()`, so this is the one place that
    // would still be correct under server-side rendering.
    const [canUsePasskeys] = useState(passkeysSupported);

    const passwordRef = useRef<HTMLInputElement>(null);

    // The password field is revealed by a server round trip, so nothing focused it. HTML
    // `autofocus` only fires at document parse and this element arrives after that.
    useEffect(() => {
        if (identified === true && mandate === undefined) {
            passwordRef.current?.focus();
        }
    }, [identified, mandate]);

    const signIn = async (): Promise<void> => {
        setPasskeyBusy(true);
        setPasskeyMessage(null);

        try {
            const destination = await signInWithPasskey();

            if (destination !== null) {
                window.location.assign(destination);
            }
        } catch (error) {
            // A dismissed system prompt is not a failure worth reporting: saying "passkey
            // failed" for a deliberate cancel teaches people to distrust the message that
            // appears when something really did go wrong.
            if (!isCancellation(error)) {
                setPasskeyMessage({
                    text: error instanceof Error ? error.message : 'Passkey sign-in failed.',
                    ok: false,
                });
            }
        } finally {
            setPasskeyBusy(false);
        }
    };

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                Sign in
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {purpose}
            </p>

            {pendingLink !== null && (
                <div
                    className="mt-5 rounded-lg px-3.5 py-3 text-sm"
                    style={{
                        background: 'var(--accent-soft)',
                        color: 'var(--accent-strong)',
                        border: '1px solid color-mix(in srgb, var(--accent) 30%, transparent)',
                    }}
                >
                    <b>Someone signed in with {pendingLink} using this email.</b> That email
                    already has an account here. Sign in below and we'll ask whether you want to
                    connect {pendingLink} to it.
                </div>
            )}

            {magicSentTo !== undefined && (
                <output
                    className="mt-5 rounded-lg text-sm card block"
                    style={{ padding: '0.85rem 1rem' }}
                >
                    <p className="font-medium">Check your inbox</p>
                    <p className="mt-1" style={{ color: 'var(--muted-foreground)' }}>
                        We sent a one-time sign-in link to <b>{magicSentTo}</b>.
                    </p>
                    {magicUrl != null && (
                        <>
                            <a
                                href={magicUrl}
                                className="mt-2 inline-block text-sm underline underline-offset-2 mono"
                                style={{ color: 'var(--accent-strong)', wordBreak: 'break-all' }}
                            >
                                {magicUrl}
                            </a>
                            <p className="mt-1 text-xs" style={{ color: 'var(--faint)' }}>
                                Shown because email isn't configured in this environment.
                            </p>
                        </>
                    )}
                </output>
            )}

            {mandate !== undefined ? (
                /*
                    THE MANDATE, and it REPLACES the form rather than sitting above it.

                    There is no password this page could accept now, so leaving the fields
                    on screen would invite the same attempt again — which is exactly what
                    the old wording ("those credentials do not match our records") already
                    did, to people whose credentials matched perfectly well.
                */
                <div role="alert" className="mt-7 card p-5">
                    <h2 className="text-base font-semibold">
                        {mandate.organization} requires single sign-on
                    </h2>
                    <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {mandate.reason}
                    </p>

                    {mandate.startUrl !== null ? (
                        // A full navigation, not a client visit: the destination is the
                        // identity provider's own redirect endpoint, which answers with a
                        // cross-origin 302 that a client-side navigation cannot follow.
                        <a
                            href={mandate.startUrl}
                            className="btn btn-primary btn-lg w-full mt-4"
                        >
                            Continue to {mandate.organization}
                        </a>
                    ) : (
                        <p
                            className="mt-4 rounded-lg px-3.5 py-3 text-sm"
                            style={{
                                background: 'var(--destructive-soft)',
                                color: 'var(--destructive-strong)',
                            }}
                        >
                            No identity provider is connected for {mandate.organization} yet, so
                            there is nowhere to send you. Ask an administrator to finish setting
                            up single sign-on.
                        </p>
                    )}

                    {/*
                        Back to the EMAIL step, not to the password form: the person has
                        just been told their address signs in somewhere else, so the next
                        useful thing they can do is type a different one.
                    */}
                    <Button
                        size="lg"
                        className="w-full mt-2.5"
                        onClick={() => router.get(loginRoute.url())}
                    >
                        Use a different email
                    </Button>
                </div>
            ) : (
                <>
                    {identified !== true ? (
                        <form
                            className="mt-7 space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(identify.url());
                            }}
                        >
                            <Field id="email" label="Email" error={form.errors.email}>
                                <Input
                                    name="email"
                                    scale="lg"
                                    type="email"
                                    inputMode="email"
                                    autoComplete="username"
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    placeholder="you@company.com"
                                    value={form.data.email}
                                    onChange={(event) => form.setData('email', event.target.value)}
                                />
                            </Field>

                            <Button
                                type="submit"
                                variant="primary"
                                size="lg"
                                className="w-full"
                                loading={form.processing}
                            >
                                Continue
                            </Button>
                        </form>
                    ) : (
                        <>
                            {/*
                                THE CONNECTION, OFFERED RATHER THAN FORCED.

                                `Prefer SSO` says single sign-on is presented first, so it
                                leads and the password form sits beneath. Under `Off` the
                                tenant has asked for neither, so the form leads and the same
                                button follows it — discoverable, not pushed. `Require SSO`
                                never reaches here: it redirects before the form is drawn.
                            */}
                            {ssoOffer != null && ssoOfferLeads === true && (
                                <div className="mt-7">
                                    <a href={ssoOffer} className="btn btn-primary btn-lg w-full">
                                        Continue with single sign-on
                                    </a>
                                    <Divider className="my-5">or use your password</Divider>
                                </div>
                            )}

                            <form
                                className={ssoOffer != null && ssoOfferLeads === true ? 'mt-5 space-y-4' : 'mt-7 space-y-4'}
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(attempt.url());
                                }}
                            >
                                <Field
                                    id="email"
                                    label="Email"
                                    error={form.errors.email}
                                    labelAction={
                                        <Link
                                            href={loginRoute.url()}
                                            className="text-xs font-medium underline underline-offset-2"
                                            style={{ color: 'var(--accent-strong)' }}
                                        >
                                            Use a different email
                                        </Link>
                                    }
                                >
                                    <Input
                                        name="email"
                                        scale="lg"
                                        type="email"
                                        inputMode="email"
                                        autoComplete="username"
                                        autoCapitalize="none"
                                        spellCheck={false}
                                        placeholder="you@company.com"
                                        value={form.data.email}
                                        onChange={(event) => form.setData('email', event.target.value)}
                                    />
                                </Field>

                                <PasswordField
                                    ref={passwordRef}
                                    id="password"
                                    label="Password"
                                    name="password"
                                    labelAction={
                                        <Link
                                            href={forgotPassword.url()}
                                            className="text-xs font-medium underline underline-offset-2"
                                            style={{ color: 'var(--accent-strong)' }}
                                        >
                                            Forgot password?
                                        </Link>
                                    }
                                    autoComplete="current-password"
                                    placeholder="••••••••••••"
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
                                    Sign in
                                </Button>
                            </form>

                            {ssoOffer != null && ssoOfferLeads !== true && (
                                <a href={ssoOffer} className="btn btn-ghost btn-lg w-full mt-3">
                                    Continue with single sign-on instead
                                </a>
                            )}
                        </>
                    )}

                    <Divider>OR</Divider>

                    {providers.length > 0 && (
                        <div className="space-y-2.5 mb-2.5">
                            {providers.map((provider) => (
                                <a
                                    key={provider.provider}
                                    href={provider.url}
                                    className="btn btn-ghost btn-lg w-full"
                                >
                                    <ProviderMark provider={provider.provider} />
                                    <span>Continue with {provider.label}</span>
                                </a>
                            ))}
                        </div>
                    )}

                    <div className="space-y-2.5">
                        <Button
                            size="lg"
                            icon="magic"
                            className="w-full"
                            onClick={() => form.post(magicLink.url())}
                        >
                            Email me a magic link
                        </Button>

                        {/*
                            Hidden entirely where the browser cannot do WebAuthn. An
                            affordance that always fails is worse than one that is absent.
                        */}
                        {canUsePasskeys && (
                            <Button
                                size="lg"
                                icon="key"
                                className="w-full"
                                loading={passkeyBusy}
                                onClick={() => void signIn()}
                            >
                                Sign in with a passkey
                            </Button>
                        )}

                        <output
                            className="text-xs text-center block"
                            style={{
                                minHeight: '1rem',
                                color:
                                    passkeyMessage?.ok === true
                                        ? 'var(--success-strong)'
                                        : 'var(--destructive-strong)',
                            }}
                        >
                            {passkeyMessage?.text ?? ''}
                        </output>
                    </div>

                    {signupOpen && (
                        <p
                            className="mt-8 text-sm text-center"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            New organization?{' '}
                            <Link
                                href={signup.url()}
                                className="font-medium underline underline-offset-2"
                                style={{ color: 'var(--accent-strong)' }}
                            >
                                Create one
                            </Link>
                        </p>
                    )}
                </>
            )}
        </>
    );
}

Login.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
