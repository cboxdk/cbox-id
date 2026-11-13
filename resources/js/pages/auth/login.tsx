import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { CatalogAppIcon } from '@/chrome/CatalogAppIcon';
import { useTranslator } from '@/i18n';
import AuthLayout, { AuthCardFoot } from '@/layouts/AuthLayout';
import {
    isCancellation,
    passkeyAutofillSupported,
    passkeysSupported,
    signInWithPasskey,
} from '@/lib/passkeys';
import type { PageProps } from '@/types';
import { Button, Divider, Field, Icon, Input, PasswordField, ProviderMark, Spinner } from '@/ui';
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
    /**
     * The app an authorization request sent this person here for, or null when they came
     * by themselves. Its icon is drawn when the environment's app catalog has one.
     */
    signingInTo: { name: string; iconUrl: string | null; iconDarkUrl: string | null } | null;
    /** What the identifier step captured. The server's old-input bag, stated as a prop. */
    email: string;
    signupOpen: boolean;
    /** The environment's console on the app host, for its administrators; null hides the link. */
    adminUrl: string | null;
    providers: SocialProvider[];
    /** The environment's switches, under the deployment's. A method that is off is not drawn. */
    methods: { passkeys: boolean; magicLink: boolean };
    /**
     * Which way this device signed in last — a provider key (`google`), `passkey`,
     * `password`, `magic_link` or `sso` — or null. Read on the server from a first-party
     * cookie; see `LastSignInMethod`.
     */
    lastUsed: string | null;
}>;

/** The id the "last used" hint is referenced by — one per page, since one method is marked. */
const LAST_USED_HINT = 'last-used-hint';

export default function Login({
    purpose,
    signingInTo,
    email,
    signupOpen,
    adminUrl,
    providers,
    methods,
    lastUsed,
}: Props) {
    /*
     * ON THE FLASH CHANNEL, not in props.
     *
     * Every one of these is a step in a flow rather than state: the identifier step
     * passed, a link was sent, a mandate refused this attempt. Props are written into the
     * browser's history entry, so a person pressing Back would land on a page still
     * claiming their address had been identified — or still showing a mandate that has
     * since been spent.
     */
    const { identified, ssoOffer, ssoOfferLeads, magicSentTo, magicUrl, mandate, signInProblem } =
        usePage().flash;
    const { t, rich } = useTranslator();

    const form = useForm({ email, password: '' });

    const [passkeyMessage, setPasskeyMessage] = useState<{ text: string; ok: boolean } | null>(
        null,
    );
    const [passkeyBusy, setPasskeyBusy] = useState(false);
    // A lazy initialiser, not an effect: reading it after mount renders one frame with
    // the passkey button ABSENT and then adds it, which moves the two buttons below it
    // under the cursor at exactly the moment somebody is reaching for one.
    //
    // Guarded on `window` inside `passkeysSupported()`, so this is the one place that
    // would still be correct under server-side rendering.
    const [canUsePasskeys] = useState(passkeysSupported);

    const passwordRef = useRef<HTMLInputElement>(null);

    /*
     * "LAST USED", as Clerk, WorkOS and Stytch mark it: a small badge on the method this
     * device signed in with last time. The ORDER does not change — somebody who has learnt
     * where their button is should find it there tomorrow, and a list that rearranges itself
     * per device is one a support article cannot describe. A password and single sign-on
     * both start at the email step, so that is where their badge goes.
     *
     * The badge is drawn for the eye and hidden from the accessibility tree; the button is
     * DESCRIBED by one sentence that says the same thing in full, so a screen reader hears
     * "Continue with Google — the way you signed in last time on this device" rather than a
     * bare "Last used" glued to the end of the button's name.
     */
    const marks = (method: string | string[]): boolean =>
        lastUsed !== null &&
        (Array.isArray(method) ? method.includes(lastUsed) : method === lastUsed);
    const lastUsedProps = (method: string | string[]) =>
        marks(method) ? { 'aria-describedby': LAST_USED_HINT, 'data-last-used': true } : {};
    const badge = (method: string | string[]) =>
        marks(method) ? (
            <span className="last-used-badge" aria-hidden="true">
                {t('auth.login.last_used')}
            </span>
        ) : null;

    // The password field is revealed by a server round trip, so nothing focused it. HTML
    // `autofocus` only fires at document parse and this element arrives after that.
    useEffect(() => {
        if (identified === true && mandate === undefined) {
            passwordRef.current?.focus();
        }
    }, [identified, mandate]);

    /*
     * PASSKEYS IN THE ADDRESS FIELD'S AUTOFILL (WebAuthn conditional UI). Where the
     * browser can do it, a request waits behind the field (`autocomplete="… webauthn"`)
     * and the person's passkeys are offered in the same list as their saved addresses:
     * one tap, no button to find. The button below stays, for browsers that cannot and
     * for a passkey on another device.
     *
     * Nothing is shown and nothing is submitted until the person picks one. A browser
     * runs ONE WebAuthn request at a time, so the button's ceremony ends this wait first
     * (`autofill.current`), and the field is armed again when that ceremony ends without
     * signing anybody in.
     */
    const autofill = useRef<AbortController | null>(null);
    const autofillOffered = methods.passkeys && mandate === undefined;
    const passkeyFailed = t('auth.login.passkey_failed');

    const armAutofill = useCallback((): void => {
        autofill.current?.abort();
        autofill.current = null;

        if (!autofillOffered) {
            return;
        }

        const controller = new AbortController();
        autofill.current = controller;

        void (async () => {
            if (!(await passkeyAutofillSupported()) || controller.signal.aborted) {
                return;
            }

            try {
                const destination = await signInWithPasskey('/passkeys', {
                    autofill: true,
                    signal: controller.signal,
                });

                if (destination !== null) {
                    window.location.assign(destination);
                }
            } catch (error) {
                // Ended by this page, or dismissed: nothing to report. A refusal (an
                // unknown passkey, an expired challenge) is said under the button and the
                // field is NOT armed again by itself: a failure that repeats would ask
                // the server for a new challenge in a loop.
                if (!controller.signal.aborted && !isCancellation(error)) {
                    setPasskeyMessage({
                        text: error instanceof Error ? error.message : passkeyFailed,
                        ok: false,
                    });
                }
            }
        })();
    }, [autofillOffered, passkeyFailed]);

    useEffect(() => {
        armAutofill();

        return () => autofill.current?.abort();
    }, [armAutofill]);

    const signIn = async (): Promise<void> => {
        setPasskeyBusy(true);
        setPasskeyMessage(null);
        // The field's waiting request first: the browser refuses a second one beside it.
        autofill.current?.abort();

        try {
            const destination = await signInWithPasskey();

            if (destination !== null) {
                window.location.assign(destination);

                return;
            }
        } catch (error) {
            // A dismissed system prompt is not a failure worth reporting: saying "passkey
            // failed" for a deliberate cancel teaches people to distrust the message that
            // appears when something really did go wrong.
            if (!isCancellation(error)) {
                setPasskeyMessage({
                    text: error instanceof Error ? error.message : t('auth.login.passkey_failed'),
                    ok: false,
                });
            }
        } finally {
            setPasskeyBusy(false);
        }

        // Still on the page (cancelled, or refused): the field offers passkeys again.
        armAutofill();
    };

    return (
        <>
            {signingInTo !== null && signingInTo.iconUrl !== null && (
                <div className="mb-4">
                    <CatalogAppIcon
                        app={{
                            name: signingInTo.name,
                            icon_url: signingInTo.iconUrl,
                            icon_dark_url: signingInTo.iconDarkUrl,
                        }}
                    />
                </div>
            )}
            <h1 className="auth-title">{t('auth.login.title')}</h1>
            <p className="mt-1.5 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {purpose}
            </p>

            {lastUsed !== null && (
                <span id={LAST_USED_HINT} className="sr-only">
                    {t('auth.login.last_used_hint')}
                </span>
            )}

            {/*
                WHY THE LAST SIGN-IN DID NOT COMPLETE — a social or single sign-on door that
                came back here instead of signing anybody in: a Cancel at the provider, an
                expired link, a provider that has not vouched for the address. These used to
                be written into the validation bag, which this page never reads on a fresh
                load, so the person landed on a blank form after a sign-in that had worked
                at Google. An alert, so it is announced as well as drawn.
            */}
            {signInProblem !== undefined && (
                <div role="alert" className="auth-notice is-error mt-5">
                    <Icon name="warning" className="w-4 h-4 shrink-0 mt-0.5" />
                    <span>{signInProblem}</span>
                </div>
            )}

            {magicSentTo !== undefined && (
                <output className="auth-notice is-info mt-5">
                    <span className="auth-notice-icon" aria-hidden="true">
                        <Icon name="mail" className="w-4 h-4" />
                    </span>
                    <span className="min-w-0 block">
                        <span className="font-medium block">
                            {t('auth.common.check_your_inbox')}
                        </span>
                        <span className="mt-0.5 block" style={{ color: 'var(--muted-foreground)' }}>
                            {rich('auth.login.magic.sent_to', {
                                email: (
                                    <b
                                        className="font-medium"
                                        style={{ color: 'var(--foreground)' }}
                                    >
                                        {magicSentTo}
                                    </b>
                                ),
                            })}
                        </span>
                        {magicUrl != null && (
                            <>
                                <a
                                    href={magicUrl}
                                    className="auth-link mt-2 inline-block text-xs mono"
                                    style={{ wordBreak: 'break-all' }}
                                >
                                    {magicUrl}
                                </a>
                                <span
                                    className="mt-1 block text-xs"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {t('auth.login.magic.dev_note')}
                                </span>
                            </>
                        )}
                    </span>
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
                <div role="alert" className="mt-6 card p-5">
                    <h2 className="text-base font-semibold">
                        {t('auth.login.mandate.heading', { organization: mandate.organization })}
                    </h2>
                    <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {mandate.reason}
                    </p>

                    {mandate.startUrl !== null ? (
                        // A full navigation, not a client visit: the destination is the
                        // identity provider's own redirect endpoint, which answers with a
                        // cross-origin 302 that a client-side navigation cannot follow.
                        <a href={mandate.startUrl} className="btn btn-primary btn-lg w-full mt-4">
                            {t('auth.login.mandate.continue', {
                                organization: mandate.organization,
                            })}
                        </a>
                    ) : (
                        <p
                            className="mt-4 rounded-lg px-3.5 py-3 text-sm"
                            style={{
                                background: 'var(--destructive-soft)',
                                color: 'var(--destructive-strong)',
                            }}
                        >
                            {t('auth.login.mandate.no_provider', {
                                organization: mandate.organization,
                            })}
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
                        {t('auth.login.use_different_email')}
                    </Button>
                </div>
            ) : (
                <>
                    {identified !== true ? (
                        <form
                            className="mt-6 space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(identify.url());
                            }}
                        >
                            <Field
                                id="email"
                                label={t('auth.common.email')}
                                error={form.errors.email}
                            >
                                <Input
                                    name="email"
                                    scale="lg"
                                    type="email"
                                    inputMode="email"
                                    autoComplete="username webauthn"
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
                                className="w-full relative"
                                loading={form.processing}
                                {...lastUsedProps(['password', 'sso'])}
                            >
                                {t('auth.login.continue')}
                                {badge(['password', 'sso'])}
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
                                <div className="mt-6">
                                    <a
                                        href={ssoOffer}
                                        className="btn btn-primary btn-lg w-full relative"
                                        {...lastUsedProps('sso')}
                                    >
                                        {t('auth.login.sso.continue')}
                                        {badge('sso')}
                                    </a>
                                    <Divider className="my-5">
                                        {t('auth.login.sso.or_password')}
                                    </Divider>
                                </div>
                            )}

                            <form
                                className={
                                    ssoOffer != null && ssoOfferLeads === true
                                        ? 'mt-5 space-y-4'
                                        : 'mt-6 space-y-4'
                                }
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post(attempt.url());
                                }}
                            >
                                <Field
                                    id="email"
                                    label={t('auth.common.email')}
                                    error={form.errors.email}
                                    labelAction={
                                        <Link href={loginRoute.url()} className="auth-link text-xs">
                                            {t('auth.login.use_different_email')}
                                        </Link>
                                    }
                                >
                                    <Input
                                        name="email"
                                        scale="lg"
                                        type="email"
                                        inputMode="email"
                                        autoComplete="username webauthn"
                                        autoCapitalize="none"
                                        spellCheck={false}
                                        placeholder="you@company.com"
                                        value={form.data.email}
                                        onChange={(event) =>
                                            form.setData('email', event.target.value)
                                        }
                                    />
                                </Field>

                                <PasswordField
                                    ref={passwordRef}
                                    id="password"
                                    label={t('auth.common.password')}
                                    name="password"
                                    showLabel={t('auth.password_field.show')}
                                    hideLabel={t('auth.password_field.hide')}
                                    labelAction={
                                        <Link
                                            href={forgotPassword.url()}
                                            className="auth-link text-xs"
                                        >
                                            {t('auth.login.forgot_password')}
                                        </Link>
                                    }
                                    autoComplete="current-password"
                                    placeholder="••••••••••••"
                                    error={form.errors.password}
                                    value={form.data.password}
                                    onChange={(event) =>
                                        form.setData('password', event.target.value)
                                    }
                                />

                                <Button
                                    type="submit"
                                    variant="primary"
                                    size="lg"
                                    className="w-full"
                                    loading={form.processing}
                                >
                                    {t('auth.common.sign_in')}
                                </Button>
                            </form>

                            {ssoOffer != null && ssoOfferLeads !== true && (
                                <a
                                    href={ssoOffer}
                                    className="btn btn-ghost btn-lg w-full mt-3 relative"
                                    {...lastUsedProps('sso')}
                                >
                                    {t('auth.login.sso.instead')}
                                    {badge('sso')}
                                </a>
                            )}
                        </>
                    )}

                    {/*
                        "OR" only above something to choose instead. With every other method
                        switched off for this environment the divider would introduce nothing.
                    */}
                    {(providers.length > 0 ||
                        methods.magicLink ||
                        (methods.passkeys && canUsePasskeys)) && (
                        <Divider>{t('auth.login.or')}</Divider>
                    )}

                    {/*
                        EVERY OTHER WAY IN, AS ONE GROUP: equal tiles, two to a row, each with
                        its mark and a short name. The full sentence ("Continue with Google")
                        stays each control's accessible name; the short label is what is
                        drawn.
                    */}
                    {(providers.length > 0 ||
                        methods.magicLink ||
                        (methods.passkeys && canUsePasskeys)) && (
                        <div className="auth-methods">
                            {providers.map((provider) => (
                                <a
                                    key={provider.provider}
                                    href={provider.url}
                                    className="auth-method"
                                    {...lastUsedProps(provider.provider)}
                                >
                                    <ProviderMark provider={provider.provider} size={16} />
                                    <span aria-hidden="true">{provider.label}</span>
                                    <span className="sr-only">
                                        {t('auth.login.continue_with', {
                                            provider: provider.label,
                                        })}
                                    </span>
                                    {badge(provider.provider)}
                                </a>
                            ))}

                            {methods.magicLink && (
                                <button
                                    type="button"
                                    className="auth-method"
                                    onClick={() => form.post(magicLink.url())}
                                    {...lastUsedProps('magic_link')}
                                >
                                    <Icon name="magic" className="w-4 h-4" />
                                    <span aria-hidden="true">
                                        {t('auth.login.method.magic_link')}
                                    </span>
                                    <span className="sr-only">{t('auth.login.magic_link')}</span>
                                    {badge('magic_link')}
                                </button>
                            )}

                            {/*
                                Hidden entirely where the browser cannot do WebAuthn. An
                                affordance that always fails is worse than one that is absent.
                            */}
                            {methods.passkeys && canUsePasskeys && (
                                <button
                                    type="button"
                                    className="auth-method"
                                    disabled={passkeyBusy}
                                    aria-busy={passkeyBusy}
                                    onClick={() => void signIn()}
                                    {...lastUsedProps('passkey')}
                                >
                                    {passkeyBusy ? (
                                        <Spinner />
                                    ) : (
                                        <Icon name="key" className="w-4 h-4" />
                                    )}
                                    <span aria-hidden="true">{t('auth.login.method.passkey')}</span>
                                    <span className="sr-only">{t('auth.login.passkey')}</span>
                                    {badge('passkey')}
                                </button>
                            )}
                        </div>
                    )}

                    <div>
                        <output
                            className="text-xs text-center block mt-3 empty:mt-0"
                            style={{
                                color:
                                    passkeyMessage?.ok === true
                                        ? 'var(--success-strong)'
                                        : 'var(--destructive-strong)',
                            }}
                        >
                            {passkeyMessage?.text ?? ''}
                        </output>
                    </div>
                </>
            )}

            {((mandate === undefined && signupOpen) || adminUrl !== null) && (
                <AuthCardFoot>
                    {mandate === undefined && signupOpen && (
                        <p>
                            {/*
                                On the way to an app the person wants an ACCOUNT for it.
                                "New organization? Create one" is the console's question.
                            */}
                            {t(
                                signingInTo === null
                                    ? 'auth.login.new_organization'
                                    : 'auth.login.no_account',
                            )}{' '}
                            <Link href={signup.url()} className="auth-link">
                                {t(
                                    signingInTo === null
                                        ? 'auth.login.create_one'
                                        : 'auth.login.create_account',
                                )}
                            </Link>
                        </p>
                    )}

                    {/*
                        For whoever ADMINISTERS this environment, not for the people signing
                        in: a plain anchor to another origin (the console on the app host),
                        small and last. The environment can hide it.
                    */}
                    {adminUrl !== null && (
                        <p className="text-xs">
                            <a href={adminUrl} className="auth-link is-quiet">
                                {t('auth.login.admin_link')}
                            </a>
                        </p>
                    )}
                </AuthCardFoot>
            )}
        </>
    );
}

Login.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
