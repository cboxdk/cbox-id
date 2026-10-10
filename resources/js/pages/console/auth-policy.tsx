import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    Combobox,
    Dialog,
    Field,
    Input,
    PageHeader,
    Pagination,
    Panel,
    Select,
    Switch,
    Table,
    Td,
    Th,
} from '@/ui';

interface Policy {
    minLength: number;
    requireBreachCheck: boolean;
    /** Empty means "no limit" — the loosest value the field can hold, not zero. */
    maxAgeDays: string;
    reuseHistory: number;
    mfa: string;
    sso: string;
    lockoutThreshold: string;
}

/** The environment's self-service sign-up switch. Environment plane only. */
interface SelfServiceSignup {
    /** False on a single-tenant install, where the deployment's signup mode decides. */
    decidedHere: boolean;
    enabled: boolean;
    /** Whether sign-up is actually open here right now. */
    open: boolean;
    mode: string;
    href: string;
}

/** Text-message codes as a second factor. Environment plane only. */
interface SmsFactorSetting {
    enabled: boolean;
    allowedCountries: string[];
    privilegedNeedStrongerFactor: boolean;
    /** The deployment's own ceiling (CBOX_ID_SMS_ALLOWED_COUNTRIES); empty means none. */
    deploymentCountries: string[];
    countries: { value: string; label: string }[];
    href: string;
}

/**
 * Passkeys, magic links, the bot challenge and session lengths — the environment's own,
 * under the deployment's ceiling. Environment plane only.
 */
interface SignInMethodsSetting {
    passkeys: boolean;
    magicLink: boolean;
    botChallenge: boolean;
    /** Empty means "the deployment's". */
    sessionIdleMinutes: string;
    sessionAbsoluteMinutes: string;
    /** What the deployment allows: a method it switched off cannot be switched on here. */
    deployment: {
        passkeys: boolean;
        magicLink: boolean;
        /** Whether the deployment has Turnstile keys at all. */
        botChallenge: boolean;
        /** 0 when the deployment sets no idle timeout. */
        sessionIdleMinutes: number;
        sessionAbsoluteMinutes: number;
    };
    /** What applies today, after the ceiling. */
    inForce: { sessionIdleMinutes: number; sessionAbsoluteMinutes: number };
    href: string;
}

interface OrganizationRow {
    id: string;
    name: string;
    /** The organization's own Policy tab, where its override is edited. */
    href: string;
    overridden: boolean;
    minLength: number;
    mfa: string;
    sso: string;
}

type Props = PageProps<{
    onEnvironmentPlane: boolean;
    policy: Policy;
    baseline: Policy;
    inheriting: boolean;
    overridden: string[];
    scopeName: string;
    /** What an empty threshold means: the deployment default, null when it is switched off. */
    lockoutDefault: { threshold: number | null; windowMinutes: number; durationMinutes: number };
    passwordsCurrentlyWork: boolean;
    mfaOptions: { value: string; label: string }[];
    ssoOptions: { value: string; label: string }[];
    organizations: OrganizationRow[] | null;
    organizationsPagination: PaginationState | null;
    saveHref: string;
    /** Null on the environment baseline, which inherits from nothing. */
    inheritHref: string | null;
    selfServiceSignup: SelfServiceSignup | null;
    smsFactor: SmsFactorSetting | null;
    signInMethods: SignInMethodsSetting | null;
    /** The environment's name — what the environment-wide panels are about. */
    environmentName: string;
    help: HelpContent;
}>;

export default function AuthPolicyPage({
    onEnvironmentPlane,
    policy,
    baseline,
    inheriting,
    overridden,
    scopeName,
    lockoutDefault,
    passwordsCurrentlyWork,
    mfaOptions,
    ssoOptions,
    organizations,
    organizationsPagination,
    saveHref,
    inheritHref,
    selfServiceSignup,
    smsFactor,
    signInMethods,
    environmentName,
    help,
}: Props) {
    const form = useForm<Policy>(policy);
    const [confirming, setConfirming] = useState<'lockout' | 'inherit' | 'signup' | null>(null);

    /*
     * Whether saving would sign people out.
     *
     * Passwords work today AND the choice about to be saved refuses them. The second half
     * is the whole rule: an organization already covered by an environment-wide mandate
     * loses nothing by restating it, and asking "are you sure you want to end every
     * session" about a change that ends none is how confirmations become reflexes.
     */
    const endsSessions = passwordsCurrentlyWork && form.data.sso === 'required';

    const save = () => {
        setConfirming(null);
        form.put(saveHref, { preserveScroll: true });
    };

    const badge = (field: string) =>
        overridden.includes(field) ? <Badge className="ml-1">Overridden</Badge> : null;

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={
                    onEnvironmentPlane
                        ? 'The baseline every organization in this environment inherits. An organization can ask for stricter rules — never looser.'
                        : `How people sign in to ${scopeName}. These start as your environment's defaults; anything you change here can only make them stricter.`
                }
            />

            {/*
                The inheritance state, said out loud before any control is read. An
                administrator looking at "Require SSO: off" cannot otherwise tell whose
                decision that is.
            */}
            {!onEnvironmentPlane && (
                <div
                    className="rounded-xl border p-4 flex flex-wrap items-center justify-between gap-3"
                    style={{ borderColor: 'var(--border)', background: 'var(--surface-2)' }}
                >
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {inheriting ? (
                            <>
                                <strong style={{ color: 'var(--foreground)' }}>
                                    Using your environment's defaults.
                                </strong>{' '}
                                Nothing here is set by {scopeName} yet — change anything below and
                                it becomes this organization's own rule.
                            </>
                        ) : (
                            <>
                                <strong style={{ color: 'var(--foreground)' }}>
                                    {scopeName} has its own rules.
                                </strong>{' '}
                                {overridden.length}{' '}
                                {overridden.length === 1 ? 'setting differs' : 'settings differ'}{' '}
                                from your environment's defaults.
                            </>
                        )}
                    </p>

                    {!inheriting && (
                        <Button size="sm" onClick={() => setConfirming('inherit')}>
                            Use environment defaults
                        </Button>
                    )}
                </div>
            )}

            <form
                className="rounded-xl border p-5 space-y-5"
                style={{ borderColor: 'var(--border)' }}
                onSubmit={(event) => {
                    event.preventDefault();

                    if (endsSessions) {
                        setConfirming('lockout');

                        return;
                    }

                    save();
                }}
            >
                <div className="grid sm:grid-cols-2 gap-5">
                    <Field
                        id="min-length"
                        label={<>Minimum password length {badge('minLength')}</>}
                        error={form.errors.minLength}
                        hint={
                            onEnvironmentPlane
                                ? undefined
                                : `Environment default: ${baseline.minLength}. You can require more, not fewer.`
                        }
                    >
                        <Input
                            id="min-length"
                            name="minLength"
                            type="number"
                            min={8}
                            max={128}
                            value={form.data.minLength}
                            onChange={(event) =>
                                form.setData('minLength', Number(event.target.value))
                            }
                        />
                    </Field>

                    <Field
                        id="reuse-history"
                        label={<>Block reuse of the last {badge('reuseHistory')}</>}
                        error={form.errors.reuseHistory}
                        hint={
                            <>
                                Passwords. 0 turns reuse checking off. Only hashes are kept.
                                {!onEnvironmentPlane &&
                                    ` Environment default: ${baseline.reuseHistory}.`}
                            </>
                        }
                    >
                        <Input
                            id="reuse-history"
                            name="reuseHistory"
                            type="number"
                            min={0}
                            max={24}
                            value={form.data.reuseHistory}
                            onChange={(event) =>
                                form.setData('reuseHistory', Number(event.target.value))
                            }
                        />
                    </Field>

                    <Field
                        id="max-age"
                        label={<>Force a change after {badge('maxAgeDays')}</>}
                        error={form.errors.maxAgeDays}
                        hint={
                            <>
                                Days. Leave empty to never force a rotation.
                                {!onEnvironmentPlane &&
                                    ` Environment default: ${baseline.maxAgeDays === '' ? 'never' : `${baseline.maxAgeDays} days`}.`}
                            </>
                        }
                    >
                        <Input
                            id="max-age"
                            name="maxAgeDays"
                            type="number"
                            min={1}
                            max={3650}
                            placeholder="Never"
                            value={form.data.maxAgeDays}
                            onChange={(event) => form.setData('maxAgeDays', event.target.value)}
                        />
                    </Field>

                    <Field
                        id="lockout"
                        label={<>Lock out after {badge('lockoutThreshold')}</>}
                        error={form.errors.lockoutThreshold}
                        hint={
                            <>
                                {/*
                                    EMPTY IS NOT "OFF". It is the deployment's default, which
                                    is on unless the operator switched it off — the page used
                                    to say the opposite.
                                */}
                                Failed attempts within {lockoutDefault.windowMinutes} minutes; the
                                account then locks for {lockoutDefault.durationMinutes} minutes.{' '}
                                {lockoutDefault.threshold === null
                                    ? 'Leave empty for no lockout — this deployment has switched its default off.'
                                    : `Leave empty for this deployment's default of ${lockoutDefault.threshold}.`}
                                {!onEnvironmentPlane &&
                                    ` Environment default: ${baseline.lockoutThreshold === '' ? (lockoutDefault.threshold ?? 'off') : baseline.lockoutThreshold}.`}
                            </>
                        }
                    >
                        <Input
                            id="lockout"
                            name="lockoutThreshold"
                            type="number"
                            min={3}
                            max={100}
                            placeholder={
                                lockoutDefault.threshold === null
                                    ? 'Off'
                                    : `${lockoutDefault.threshold} (default)`
                            }
                            value={form.data.lockoutThreshold}
                            onChange={(event) =>
                                form.setData('lockoutThreshold', event.target.value)
                            }
                        />
                    </Field>

                    <Field
                        label={<>Two-factor authentication {badge('mfa')}</>}
                        error={form.errors.mfa}
                    >
                        <Select
                            value={form.data.mfa}
                            onValueChange={(mfa) => form.setData('mfa', mfa)}
                            options={mfaOptions}
                            aria-label="Two-factor authentication"
                        />
                    </Field>

                    <Field label={<>Enterprise SSO {badge('sso')}</>} error={form.errors.sso}>
                        <Select
                            value={form.data.sso}
                            onValueChange={(sso) => form.setData('sso', sso)}
                            options={ssoOptions}
                            aria-label="Enterprise SSO"
                        />
                    </Field>
                </div>

                <Checkbox
                    checked={form.data.requireBreachCheck}
                    onCheckedChange={(checked) => form.setData('requireBreachCheck', checked)}
                    label="Refuse passwords found in known data breaches. Checked against Have I Been Pwned without ever sending the password — if the service is unreachable the sign-up is allowed rather than blocked."
                />
                {form.errors.requireBreachCheck !== undefined && (
                    <p className="field-error" role="alert">
                        {form.errors.requireBreachCheck}
                    </p>
                )}

                <div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Save rules
                    </Button>
                </div>
            </form>

            {/*
                On an organization's own console these are drawn only where its administrators
                are the environment's — a single-tenant install — and they are said to be the
                environment's, because changing one changes it for every organization here.
            */}
            {!onEnvironmentPlane && signInMethods !== null && (
                <div className="pt-2">
                    <h2 className="text-base font-semibold">For the whole environment</h2>
                    <p className="text-sm mt-1" style={{ color: 'var(--muted-foreground)' }}>
                        These apply to every organization in {environmentName}, not only {scopeName}
                        .
                    </p>
                </div>
            )}

            {signInMethods !== null && (
                <SignInMethodsPanel setting={signInMethods} scopeName={environmentName} />
            )}

            {selfServiceSignup !== null && (
                <SelfServiceSignupPanel
                    setting={selfServiceSignup}
                    scopeName={environmentName}
                    onEnable={() => setConfirming('signup')}
                />
            )}

            {smsFactor !== null && (
                <SmsFactorPanel setting={smsFactor} scopeName={environmentName} />
            )}

            {/* What each organization actually ends up with. */}
            {onEnvironmentPlane && organizations !== null && (
                <Panel
                    id="organizations"
                    title="Per organization"
                    description="The rules in force after this environment's baseline is applied. An organization's own override can only make these stricter."
                >
                    <div className="overflow-x-auto">
                        <Table caption="Authentication policy in force, per organization">
                            <thead>
                                <tr>
                                    <Th>Organization</Th>
                                    <Th>Min length</Th>
                                    <Th>2FA</Th>
                                    <Th>SSO</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {organizations.length === 0 ? (
                                    <tr>
                                        <Td colSpan={4} style={{ color: 'var(--faint)' }}>
                                            No organizations in this environment yet.
                                        </Td>
                                    </tr>
                                ) : (
                                    organizations.map((row) => (
                                        <tr key={row.id}>
                                            <Td>
                                                <Link href={row.href}>{row.name}</Link>{' '}
                                                {row.overridden ? (
                                                    <Badge>Override</Badge>
                                                ) : (
                                                    <span
                                                        className="text-xs"
                                                        style={{ color: 'var(--faint)' }}
                                                    >
                                                        inherited
                                                    </span>
                                                )}
                                            </Td>
                                            <Td style={{ fontVariantNumeric: 'tabular-nums' }}>
                                                {row.minLength}
                                            </Td>
                                            <Td>{row.mfa}</Td>
                                            <Td>{row.sso}</Td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </Table>
                    </div>

                    {organizationsPagination !== null && (
                        <div className="mt-4">
                            <Pagination
                                pagination={organizationsPagination}
                                noun="organization"
                                href={(page) => `${window.location.pathname}?page=${page}`}
                            />
                        </div>
                    )}
                </Panel>
            )}

            {/*
                THE CONSEQUENCE, BEFORE THE CHANGE. Turning the mandate on ends every
                password session it governs — `RevokingAuthPolicies` does that on the way
                through the contract — and the person most likely to be holding one is the
                administrator reading this. A native confirm() cannot say that.
            */}
            <Dialog
                open={confirming === 'lockout'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={`This will sign people out of ${scopeName}`}
                description="Requiring SSO refuses every other way in, and ends the sessions that used one."
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>Keep passwords working</Button>
                        <Button variant="danger" onClick={save}>
                            Require SSO and sign everyone out
                        </Button>
                    </>
                }
            >
                <ul className="space-y-1 text-sm list-disc pl-5">
                    <li>
                        Password sign-in stops working for everyone in {scopeName}, immediately.
                    </li>
                    <li>
                        Every session that was opened with a password ends — including yours, if you
                        signed in that way.
                    </li>
                    <li>
                        People get back in through your identity provider. Anyone without one
                        connected cannot sign in at all.
                    </li>
                    <li>
                        You can set this back to "Prefer SSO" or "Both available" at any time;
                        sessions that ended stay ended.
                    </li>
                </ul>
            </Dialog>

            {/*
                OPENING THE DOOR, SAID BEFORE IT OPENS. Turning sign-up on lets anybody
                create an account here — and with it an organization of their own — which is
                what a product with self-serve onboarding wants and what a B2B product with
                provisioned customers very much does not.
            */}
            {selfServiceSignup !== null && (
                <Dialog
                    open={confirming === 'signup'}
                    onOpenChange={(open) => !open && setConfirming(null)}
                    title={`Let people sign up to ${environmentName}?`}
                    description="Anyone who reaches one of your apps can create an account here, without an invitation."
                    footer={
                        <>
                            <Button onClick={() => setConfirming(null)}>
                                Keep invitation-only
                            </Button>
                            <Button
                                variant="primary"
                                onClick={() => {
                                    setConfirming(null);
                                    router.put(
                                        selfServiceSignup.href,
                                        { enabled: true },
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                Turn on sign-up
                            </Button>
                        </>
                    }
                >
                    <ul className="space-y-1 text-sm list-disc pl-5">
                        <li>
                            Your sign-in page gets a &ldquo;Create an account&rdquo; link, and apps
                            can send people straight to sign-up with <code>prompt=create</code>.
                        </li>
                        <li>
                            Each new account creates its own organization and owns it. Apps can also
                            ask a signed-in person to create one with{' '}
                            <code>prompt=create_organization</code>.
                        </li>
                        <li>
                            Your authentication policy still applies: password strength, the breach
                            check, email confirmation, rate limits and bot checks.
                        </li>
                    </ul>
                </Dialog>
            )}

            <Dialog
                open={confirming === 'inherit'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={`Use your environment's defaults for ${scopeName}?`}
                description="This organization's own authentication policy is dropped, and it is governed by the environment baseline from here on."
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>Cancel</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                setConfirming(null);

                                if (inheritHref !== null) {
                                    router.delete(inheritHref, { preserveScroll: true });
                                }
                            }}
                        >
                            Use environment defaults
                        </Button>
                    </>
                }
            />
        </div>
    );
}

AuthPolicyPage.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;

/**
 * SELF-SERVICE SIGN-UP — whether a stranger may create an account in this environment.
 *
 * A switch, because it takes effect the moment it is flipped. Turning it ON goes through
 * a confirmation (the parent owns the dialog); turning it off closes the door at once and
 * needs none — nobody is signed out, and people already in stay in.
 */
function SelfServiceSignupPanel({
    setting,
    scopeName,
    onEnable,
}: {
    setting: SelfServiceSignup;
    scopeName: string;
    onEnable: () => void;
}) {
    const [saving, setSaving] = useState(false);

    if (!setting.decidedHere) {
        return (
            <Panel
                id="sign-up"
                title="Self-service sign-up"
                description={`Decided by this deployment's CBOX_ID_SIGNUP_MODE, which is "${setting.mode}". Sign-up is ${setting.open ? 'open' : 'closed'}.`}
            />
        );
    }

    return (
        <Panel
            id="sign-up"
            title="Self-service sign-up"
            description={
                setting.enabled
                    ? `Anyone can create an account in ${scopeName}, and becomes the owner of their own organization.`
                    : `People join ${scopeName} by invitation only.`
            }
            action={
                <Switch
                    aria-label="Self-service sign-up"
                    checked={setting.enabled}
                    disabled={saving}
                    onCheckedChange={(checked) => {
                        if (checked) {
                            onEnable();

                            return;
                        }

                        setSaving(true);
                        router.put(
                            setting.href,
                            { enabled: false },
                            { preserveScroll: true, onFinish: () => setSaving(false) },
                        );
                    }}
                />
            }
        >
            {setting.enabled && !setting.open && (
                <p className="text-sm" style={{ color: 'var(--warning-strong)' }}>
                    On here, but this deployment has closed sign-up everywhere
                    (CBOX_ID_SIGNUP_MODE=closed), so nobody can sign up until that changes.
                </p>
            )}
            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Apps send people to sign-up with <code>prompt=create</code>, and ask a signed-in
                person to create an organization with <code>prompt=create_organization</code>. Both
                are offered only while this is on.
            </p>
        </Panel>
    );
}

/** "90 minutes", "8 hours", "2 days" — whichever reads whole. */
function duration(minutes: number): string {
    if (minutes % 1440 === 0) {
        const days = minutes / 1440;

        return `${days} ${days === 1 ? 'day' : 'days'}`;
    }

    if (minutes % 60 === 0) {
        const hours = minutes / 60;

        return `${hours} ${hours === 1 ? 'hour' : 'hours'}`;
    }

    return `${minutes} minutes`;
}

/**
 * SIGN-IN METHODS AND SESSIONS — the switches that used to be the deployment's alone.
 *
 * Each sits UNDER the deployment: a method the deployment switched off is drawn off and
 * disabled, with the variable named, because a switch the console cannot honour is worse
 * than none. The two lengths say the most they may be, and an empty field is the
 * deployment's own value — written as the placeholder, so "empty" never reads as "none".
 */
function SignInMethodsPanel({
    setting,
    scopeName,
}: {
    setting: SignInMethodsSetting;
    scopeName: string;
}) {
    const form = useForm({
        passkeys: setting.passkeys,
        magicLink: setting.magicLink,
        botChallenge: setting.botChallenge,
        sessionIdleMinutes: setting.sessionIdleMinutes,
        sessionAbsoluteMinutes: setting.sessionAbsoluteMinutes,
    });
    const { deployment } = setting;
    const idleCeiling =
        deployment.sessionIdleMinutes > 0
            ? deployment.sessionIdleMinutes
            : deployment.sessionAbsoluteMinutes;

    const deploymentOff = (variable: string) => (
        <>
            Off for this whole deployment (<code className="mono">{variable}</code>), which wins
            over this setting.
        </>
    );

    return (
        <Panel
            id="sign-in-methods"
            title="Sign-in methods and sessions"
            description={`Which ways in ${scopeName} offers besides a password, and how long a sign-in lasts. These are the same for every organization in it.`}
        >
            <form
                className="space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(setting.href, { preserveScroll: true });
                }}
            >
                <div className="space-y-4">
                    <Checkbox
                        label="Passkeys"
                        hint={
                            deployment.passkeys
                                ? 'Face ID, Touch ID, Windows Hello or a security key. Turning this off keeps the passkeys people already added, for when you turn it back on.'
                                : deploymentOff('CBOX_ID_PASSKEYS_ENABLED')
                        }
                        checked={deployment.passkeys && form.data.passkeys}
                        disabled={!deployment.passkeys}
                        onCheckedChange={(checked) => form.setData('passkeys', checked)}
                    />
                    <Checkbox
                        label="Magic link"
                        hint={
                            deployment.magicLink
                                ? 'A one-time sign-in link by email. Turning this off also stops links already sent from working.'
                                : deploymentOff('CBOX_ID_MAGIC_LINK_ENABLED')
                        }
                        checked={deployment.magicLink && form.data.magicLink}
                        disabled={!deployment.magicLink}
                        onCheckedChange={(checked) => form.setData('magicLink', checked)}
                    />
                    <Checkbox
                        label="Bot challenge"
                        hint={
                            deployment.botChallenge
                                ? 'Ask a sign-up Radar flags to prove it is a person (Cloudflare Turnstile). Off, a flagged sign-up confirms its email address instead.'
                                : 'This deployment has no Turnstile keys (CBOX_ID_TURNSTILE_SITE_KEY), so there is no challenge to turn on. A flagged sign-up confirms its email address instead.'
                        }
                        checked={deployment.botChallenge && form.data.botChallenge}
                        disabled={!deployment.botChallenge}
                        onCheckedChange={(checked) => form.setData('botChallenge', checked)}
                    />
                </div>

                <div className="grid sm:grid-cols-2 gap-5">
                    <Field
                        id="session-idle"
                        label="End a session after this long without activity"
                        error={form.errors.sessionIdleMinutes}
                        hint={`Minutes, at most ${idleCeiling}. Empty uses the deployment's ${deployment.sessionIdleMinutes > 0 ? duration(deployment.sessionIdleMinutes) : 'setting: no idle timeout'}. In force now: ${setting.inForce.sessionIdleMinutes > 0 ? duration(setting.inForce.sessionIdleMinutes) : 'none'}.`}
                    >
                        <Input
                            id="session-idle"
                            name="sessionIdleMinutes"
                            type="number"
                            min={1}
                            max={idleCeiling}
                            placeholder={
                                deployment.sessionIdleMinutes > 0
                                    ? `${deployment.sessionIdleMinutes} (deployment)`
                                    : 'None (deployment)'
                            }
                            value={form.data.sessionIdleMinutes}
                            onChange={(event) =>
                                form.setData('sessionIdleMinutes', event.target.value)
                            }
                        />
                    </Field>

                    <Field
                        id="session-absolute"
                        label="End a session after this long, however active"
                        error={form.errors.sessionAbsoluteMinutes}
                        hint={`Minutes, at most ${deployment.sessionAbsoluteMinutes}. Empty uses the deployment's ${duration(deployment.sessionAbsoluteMinutes)}. Shortening it also ends longer sessions already running.`}
                    >
                        <Input
                            id="session-absolute"
                            name="sessionAbsoluteMinutes"
                            type="number"
                            min={5}
                            max={deployment.sessionAbsoluteMinutes}
                            placeholder={`${deployment.sessionAbsoluteMinutes} (deployment)`}
                            value={form.data.sessionAbsoluteMinutes}
                            onChange={(event) =>
                                form.setData('sessionAbsoluteMinutes', event.target.value)
                            }
                        />
                    </Field>
                </div>

                {(form.errors.passkeys ?? form.errors.magicLink ?? form.errors.botChallenge) !==
                    undefined && (
                    <p className="field-error" role="alert">
                        {form.errors.passkeys ?? form.errors.magicLink ?? form.errors.botChallenge}
                    </p>
                )}

                <div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Save sign-in methods
                    </Button>
                </div>
            </form>
        </Panel>
    );
}

/**
 * TEXT-MESSAGE CODES — SMS as a second factor, off until an environment turns it on.
 *
 * The trade-off is written on the panel rather than in a help page, because it is the
 * whole decision: SMS is better than a password alone and worse than every other factor
 * here, and each country on the list is a place the environment pays to send texts to.
 * Turning it on needs at least one country — the action refuses SMS with none, so the
 * page cannot show "on" for a setting nobody can enrol under.
 */
function SmsFactorPanel({ setting, scopeName }: { setting: SmsFactorSetting; scopeName: string }) {
    const form = useForm({
        enabled: setting.enabled,
        allowedCountries: setting.allowedCountries,
        privilegedNeedStrongerFactor: setting.privilegedNeedStrongerFactor,
    });
    const [adding, setAdding] = useState<string | undefined>(undefined);

    const label = (code: string) =>
        setting.countries.find((country) => country.value === code)?.label ?? code;

    const outsideDeployment = form.data.allowedCountries.filter(
        (code) =>
            setting.deploymentCountries.length > 0 && !setting.deploymentCountries.includes(code),
    );

    const options = setting.countries.filter(
        (country) => !form.data.allowedCountries.includes(country.value),
    );

    return (
        <Panel
            id="sms"
            title="Text-message codes"
            description={
                setting.enabled
                    ? `People in ${scopeName} can add a phone number and receive sign-in codes by SMS.`
                    : `Off. People in ${scopeName} use an authenticator app, a passkey or recovery codes.`
            }
        >
            <form
                className="space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(setting.href, { preserveScroll: true });
                }}
            >
                <div
                    className="rounded-lg p-3 text-sm space-y-1.5"
                    style={{ border: '1px solid var(--border)', color: 'var(--muted-foreground)' }}
                >
                    <p>
                        <b>SMS is the weakest second factor offered here.</b> A code can be taken by
                        a SIM swap or number port-out, intercepted in the phone network, or typed
                        into a convincing fake sign-in page. It is still far better than a password
                        alone — turn it on for people who cannot use an authenticator app or a
                        passkey.
                    </p>
                    <p>
                        Every text costs money. Codes go only to the countries listed below, and
                        each number, network address and this environment have daily limits.
                    </p>
                </div>

                <Checkbox
                    label="Accept text-message codes as a second factor"
                    checked={form.data.enabled}
                    onCheckedChange={(checked) => form.setData('enabled', checked)}
                />

                <Field
                    label="Countries"
                    hint="Only numbers in these countries can be added and texted. Removing a country stops texts to it at once."
                    error={form.errors.allowedCountries}
                >
                    <Combobox
                        aria-label="Add a country"
                        value={adding}
                        placeholder="Add a country…"
                        searchPlaceholder="Search countries…"
                        options={options}
                        onValueChange={(code) => {
                            setAdding(undefined);
                            form.setData('allowedCountries', [...form.data.allowedCountries, code]);
                        }}
                    />
                </Field>

                {form.data.allowedCountries.length > 0 ? (
                    <ul className="flex flex-wrap gap-2" aria-label="Allowed countries">
                        {form.data.allowedCountries.map((code) => (
                            <li key={code}>
                                <Badge>
                                    {label(code)} <span className="mono">{code}</span>
                                    <button
                                        type="button"
                                        className="ml-1.5"
                                        aria-label={`Remove ${label(code)}`}
                                        onClick={() =>
                                            form.setData(
                                                'allowedCountries',
                                                form.data.allowedCountries.filter(
                                                    (other) => other !== code,
                                                ),
                                            )
                                        }
                                    >
                                        ×
                                    </button>
                                </Badge>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        No countries yet. Add at least one before turning text-message codes on.
                    </p>
                )}

                {outsideDeployment.length > 0 && (
                    <p className="text-sm" style={{ color: 'var(--warning-strong)' }}>
                        This deployment only texts {setting.deploymentCountries.join(', ')}, so
                        numbers in {outsideDeployment.map(label).join(', ')} cannot receive codes
                        until CBOX_ID_SMS_ALLOWED_COUNTRIES includes them.
                    </p>
                )}

                <Checkbox
                    label="Owners and admins need an authenticator app or a passkey too"
                    hint="An owner or admin can add SMS only next to a stronger factor, and is asked to add one if SMS is all they have."
                    checked={form.data.privilegedNeedStrongerFactor}
                    onCheckedChange={(checked) =>
                        form.setData('privilegedNeedStrongerFactor', checked)
                    }
                />

                <div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Save text-message settings
                    </Button>
                </div>
            </form>
        </Panel>
    );
}
