import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

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
        overridden.includes(field) ? (
            <Badge className="ml-1">{tc('console.overridden_62a03f09')}</Badge>
        ) : null;

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={
                    onEnvironmentPlane
                        ? tc('console.the_baseline_every_organization_in_this_envi_75711a2d')
                        : tc('console.how_people_sign_in_to_value0_these_start_as__c9e4acbf', {
                              value0: scopeName,
                          })
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
                                    {tc('console.using_your_environment_s_defaults_caa183bc')}
                                </strong>{' '}
                                {tc('console.nothing_here_is_set_by_3c4318b0')}
                                {scopeName}
                                {tc(
                                    'console.yet_change_anything_below_and_it_becomes_thi_472d5907',
                                )}
                            </>
                        ) : (
                            <>
                                <strong style={{ color: 'var(--foreground)' }}>
                                    {scopeName}
                                    {tc('console.has_its_own_rules_eabdbf0a')}
                                </strong>{' '}
                                {overridden.length}{' '}
                                {overridden.length === 1
                                    ? tc('console.setting_differs_9901f740')
                                    : tc('console.settings_differ_bd913a9c')}{' '}
                                {tc('console.from_your_environment_s_defaults_d638e385')}
                            </>
                        )}
                    </p>

                    {!inheriting && (
                        <Button size="sm" onClick={() => setConfirming('inherit')}>
                            {tc('console.use_environment_defaults_458238c0')}
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
                        label={
                            <>
                                {tc('console.minimum_password_length_212329b7')}
                                {badge('minLength')}
                            </>
                        }
                        error={form.errors.minLength}
                        hint={
                            onEnvironmentPlane
                                ? undefined
                                : tc(
                                      'console.environment_default_value0_you_can_require_m_8d5f9e39',
                                      { value0: baseline.minLength },
                                  )
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
                        label={
                            <>
                                {tc('console.block_reuse_of_the_last_e8e45b8a')}
                                {badge('reuseHistory')}
                            </>
                        }
                        error={form.errors.reuseHistory}
                        hint={
                            <>
                                {tc(
                                    'console.passwords_0_turns_reuse_checking_off_only_ha_f9522130',
                                )}
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
                        label={
                            <>
                                {tc('console.force_a_change_after_58b2d52e')}
                                {badge('maxAgeDays')}
                            </>
                        }
                        error={form.errors.maxAgeDays}
                        hint={
                            <>
                                {tc('console.days_leave_empty_to_never_force_a_rotation_bbc4f068')}
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
                            placeholder={tc('console.never_6300ef80')}
                            value={form.data.maxAgeDays}
                            onChange={(event) => form.setData('maxAgeDays', event.target.value)}
                        />
                    </Field>

                    <Field
                        id="lockout"
                        label={
                            <>
                                {tc('console.lock_out_after_bc9e496f')}
                                {badge('lockoutThreshold')}
                            </>
                        }
                        error={form.errors.lockoutThreshold}
                        hint={
                            <>
                                {/*
                                    EMPTY IS NOT "OFF". It is the deployment's default, which
                                    is on unless the operator switched it off — the page used
                                    to say the opposite.
                                */}
                                {tc('console.failed_attempts_within_b198b8a6')}
                                {lockoutDefault.windowMinutes}
                                {tc('console.minutes_the_account_then_locks_for_2901d638')}
                                {lockoutDefault.durationMinutes}
                                {tc('console.minutes_014f602d')}{' '}
                                {lockoutDefault.threshold === null
                                    ? tc(
                                          'console.leave_empty_for_no_lockout_this_deployment_h_d1f1a3e9',
                                      )
                                    : tc(
                                          'console.leave_empty_for_this_deployment_s_default_of_d2ead774',
                                          { value0: lockoutDefault.threshold },
                                      )}
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
                                    ? tc('console.off_ca7981b4')
                                    : tc('console.value0_default_63685080', {
                                          value0: lockoutDefault.threshold,
                                      })
                            }
                            value={form.data.lockoutThreshold}
                            onChange={(event) =>
                                form.setData('lockoutThreshold', event.target.value)
                            }
                        />
                    </Field>

                    <Field
                        label={
                            <>
                                {tc('console.two_factor_authentication_64eac237')}
                                {badge('mfa')}
                            </>
                        }
                        error={form.errors.mfa}
                    >
                        <Select
                            value={form.data.mfa}
                            onValueChange={(mfa) => form.setData('mfa', mfa)}
                            options={mfaOptions}
                            aria-label={tc('console.two_factor_authentication_b6824cc8')}
                        />
                    </Field>

                    <Field
                        label={
                            <>
                                {tc('console.enterprise_sso_bd67f181')}
                                {badge('sso')}
                            </>
                        }
                        error={form.errors.sso}
                    >
                        <Select
                            value={form.data.sso}
                            onValueChange={(sso) => form.setData('sso', sso)}
                            options={ssoOptions}
                            aria-label={tc('console.enterprise_sso_0f8d7e8e')}
                        />
                    </Field>
                </div>

                <Checkbox
                    checked={form.data.requireBreachCheck}
                    onCheckedChange={(checked) => form.setData('requireBreachCheck', checked)}
                    label={tc('console.refuse_passwords_found_in_known_data_breache_db4c6959')}
                />
                {form.errors.requireBreachCheck !== undefined && (
                    <p className="field-error" role="alert">
                        {form.errors.requireBreachCheck}
                    </p>
                )}

                <div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.save_rules_e5dbdffb')}
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
                    <h2 className="text-base font-semibold">
                        {tc('console.for_the_whole_environment_5b97e28f')}
                    </h2>
                    <p className="text-sm mt-1" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.these_apply_to_every_organization_in_3f27386c')}
                        {environmentName}
                        {tc('console.not_only_6a5ac048')}
                        {scopeName}.
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
                    title={tc('console.per_organization_8cb1cd51')}
                    description={tc(
                        'console.the_rules_in_force_after_this_environment_s__278c536a',
                    )}
                >
                    <div className="overflow-x-auto">
                        <Table
                            caption={tc(
                                'console.authentication_policy_in_force_per_organizat_1463aba6',
                            )}
                        >
                            <thead>
                                <tr>
                                    <Th>{tc('console.organization_d764d425')}</Th>
                                    <Th>{tc('console.min_length_483ad8e5')}</Th>
                                    <Th>2FA</Th>
                                    <Th>SSO</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {organizations.length === 0 ? (
                                    <tr>
                                        <Td colSpan={4} style={{ color: 'var(--faint)' }}>
                                            {tc(
                                                'console.no_organizations_in_this_environment_yet_483d8509',
                                            )}
                                        </Td>
                                    </tr>
                                ) : (
                                    organizations.map((row) => (
                                        <tr key={row.id}>
                                            <Td>
                                                <Link href={row.href}>{row.name}</Link>{' '}
                                                {row.overridden ? (
                                                    <Badge>{tc('console.override_43bc0f5f')}</Badge>
                                                ) : (
                                                    <span
                                                        className="text-xs"
                                                        style={{ color: 'var(--faint)' }}
                                                    >
                                                        {tc('console.inherited_46b2e93c')}
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
                                noun={tc('console.organization_af3a1bb3')}
                                pluralNoun={tc('console.organizations_f8a665a9')}
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
                title={tc('console.this_will_sign_people_out_of_value0_167bd637', {
                    value0: scopeName,
                })}
                description={tc('console.requiring_sso_refuses_every_other_way_in_and_7d9d14ef')}
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>
                            {tc('console.keep_passwords_working_b57484cf')}
                        </Button>
                        <Button variant="danger" onClick={save}>
                            {tc('console.require_sso_and_sign_everyone_out_474ac7df')}
                        </Button>
                    </>
                }
            >
                <ul className="space-y-1 text-sm list-disc pl-5">
                    <li>
                        {tc('console.password_sign_in_stops_working_for_everyone__dd89ee54')}
                        {scopeName}
                        {tc('console.immediately_175925af')}
                    </li>
                    <li>{tc('console.every_session_that_was_opened_with_a_passwor_cf05d595')}</li>
                    <li>{tc('console.people_get_back_in_through_your_identity_pro_6fd7e521')}</li>
                    <li>{tc('console.you_can_set_this_back_to_prefer_sso_or_both__b84203a4')}</li>
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
                    title={tc('console.let_people_sign_up_to_value0_dfabb5ad', {
                        value0: environmentName,
                    })}
                    description={tc(
                        'console.anyone_who_reaches_one_of_your_apps_can_crea_eeb28c84',
                    )}
                    footer={
                        <>
                            <Button onClick={() => setConfirming(null)}>
                                {tc('console.keep_invitation_only_2cf257d6')}
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
                                {tc('console.turn_on_sign_up_487bb553')}
                            </Button>
                        </>
                    }
                >
                    <ul className="space-y-1 text-sm list-disc pl-5">
                        <li>
                            {tc('console.your_sign_in_page_gets_a_ldquo_create_an_acc_020878e6')}
                            <code>{tc('console.prompt_create_46ad4d3f')}</code>.
                        </li>
                        <li>
                            {tc('console.each_new_account_creates_its_own_organizatio_45ebf9cf')}{' '}
                            <code>{tc('console.prompt_create_organization_2a7488dc')}</code>.
                        </li>
                        <li>
                            {tc('console.your_authentication_policy_still_applies_pas_84825ce9')}
                        </li>
                    </ul>
                </Dialog>
            )}

            <Dialog
                open={confirming === 'inherit'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={tc('console.use_your_environment_s_defaults_for_value0_616d87ac', {
                    value0: scopeName,
                })}
                description={tc('console.this_organization_s_own_authentication_polic_a12f3b55')}
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                setConfirming(null);

                                if (inheritHref !== null) {
                                    router.delete(inheritHref, { preserveScroll: true });
                                }
                            }}
                        >
                            {tc('console.use_environment_defaults_458238c0')}
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
    const { t: tc } = useTranslator();

    const [saving, setSaving] = useState(false);

    if (!setting.decidedHere) {
        return (
            <Panel
                id="sign-up"
                title={tc('console.self_service_sign_up_613527ff')}
                description={tc('console.decided_by_this_deployment_s_cbox_id_signup__3d142130', {
                    value0: setting.mode,
                    value1: setting.open ? 'open' : 'closed',
                })}
            />
        );
    }

    return (
        <Panel
            id="sign-up"
            title={tc('console.self_service_sign_up_613527ff')}
            description={
                setting.enabled
                    ? tc('console.anyone_can_create_an_account_in_value0_and_b_f8d282a6', {
                          value0: scopeName,
                      })
                    : tc('console.people_join_value0_by_invitation_only_a4644606', {
                          value0: scopeName,
                      })
            }
            action={
                <Switch
                    aria-label={tc('console.self_service_sign_up_613527ff')}
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
                    {tc('console.on_here_but_this_deployment_has_closed_sign__270a24b1')}
                </p>
            )}
            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {tc('console.apps_send_people_to_sign_up_with_8606caef')}
                <code>{tc('console.prompt_create_46ad4d3f')}</code>
                {tc('console.and_ask_a_signed_in_person_to_create_an_orga_b13d58d0')}
                <code>{tc('console.prompt_create_organization_2a7488dc')}</code>
                {tc('console.both_are_offered_only_while_this_is_on_134a8203')}
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
    const { t: tc } = useTranslator();

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
            {tc('console.off_for_this_whole_deployment_6fda971a')}
            <code className="mono">{variable}</code>
            {tc('console.which_wins_over_this_setting_8282e10b')}
        </>
    );

    return (
        <Panel
            id="sign-in-methods"
            title={tc('console.sign_in_methods_and_sessions_66ef22a6')}
            description={tc('console.which_ways_in_value0_offers_besides_a_passwo_4ead969c', {
                value0: scopeName,
            })}
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
                        label={tc('console.passkeys_c87ccdb4')}
                        hint={
                            deployment.passkeys
                                ? tc(
                                      'console.face_id_touch_id_windows_hello_or_a_security_6e23006c',
                                  )
                                : deploymentOff('CBOX_ID_PASSKEYS_ENABLED')
                        }
                        checked={deployment.passkeys && form.data.passkeys}
                        disabled={!deployment.passkeys}
                        onCheckedChange={(checked) => form.setData('passkeys', checked)}
                    />
                    <Checkbox
                        label={tc('console.magic_link_a8a8b124')}
                        hint={
                            deployment.magicLink
                                ? tc(
                                      'console.a_one_time_sign_in_link_by_email_turning_thi_eeae7dbf',
                                  )
                                : deploymentOff('CBOX_ID_MAGIC_LINK_ENABLED')
                        }
                        checked={deployment.magicLink && form.data.magicLink}
                        disabled={!deployment.magicLink}
                        onCheckedChange={(checked) => form.setData('magicLink', checked)}
                    />
                    <Checkbox
                        label={tc('console.bot_challenge_ed4b037c')}
                        hint={
                            deployment.botChallenge
                                ? tc(
                                      'console.ask_a_sign_up_radar_flags_to_prove_it_is_a_p_5b5f2963',
                                  )
                                : tc(
                                      'console.this_deployment_has_no_turnstile_keys_cbox_i_9ceda61f',
                                  )
                        }
                        checked={deployment.botChallenge && form.data.botChallenge}
                        disabled={!deployment.botChallenge}
                        onCheckedChange={(checked) => form.setData('botChallenge', checked)}
                    />
                </div>

                <div className="grid sm:grid-cols-2 gap-5">
                    <Field
                        id="session-idle"
                        label={tc('console.end_a_session_after_this_long_without_activi_40e79de1')}
                        error={form.errors.sessionIdleMinutes}
                        hint={tc('console.minutes_at_most_value0_empty_uses_the_deploy_3f932b31', {
                            value0: idleCeiling,
                            value1:
                                deployment.sessionIdleMinutes > 0
                                    ? duration(deployment.sessionIdleMinutes)
                                    : tc('console.setting_no_idle_timeout_9ce693ee'),
                            value2:
                                setting.inForce.sessionIdleMinutes > 0
                                    ? duration(setting.inForce.sessionIdleMinutes)
                                    : 'none',
                        })}
                    >
                        <Input
                            id="session-idle"
                            name="sessionIdleMinutes"
                            type="number"
                            min={1}
                            max={idleCeiling}
                            placeholder={
                                deployment.sessionIdleMinutes > 0
                                    ? tc('console.value0_deployment_c0ab2754', {
                                          value0: deployment.sessionIdleMinutes,
                                      })
                                    : tc('console.none_deployment_64577084')
                            }
                            value={form.data.sessionIdleMinutes}
                            onChange={(event) =>
                                form.setData('sessionIdleMinutes', event.target.value)
                            }
                        />
                    </Field>

                    <Field
                        id="session-absolute"
                        label={tc('console.end_a_session_after_this_long_however_active_40352264')}
                        error={form.errors.sessionAbsoluteMinutes}
                        hint={tc('console.minutes_at_most_value0_empty_uses_the_deploy_6b686e82', {
                            value0: deployment.sessionAbsoluteMinutes,
                            value1: duration(deployment.sessionAbsoluteMinutes),
                        })}
                    >
                        <Input
                            id="session-absolute"
                            name="sessionAbsoluteMinutes"
                            type="number"
                            min={5}
                            max={deployment.sessionAbsoluteMinutes}
                            placeholder={tc('console.value0_deployment_c0ab2754', {
                                value0: deployment.sessionAbsoluteMinutes,
                            })}
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
                        {tc('console.save_sign_in_methods_a02553c8')}
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
    const { t: tc } = useTranslator();

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
            title={tc('console.text_message_codes_9f1561f0')}
            description={
                setting.enabled
                    ? tc('console.people_in_value0_can_add_a_phone_number_and__2faf8f5f', {
                          value0: scopeName,
                      })
                    : tc('console.off_people_in_value0_use_an_authenticator_ap_b459eb63', {
                          value0: scopeName,
                      })
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
                        <b>{tc('console.sms_is_the_weakest_second_factor_offered_her_5a90b3ee')}</b>
                        {tc('console.a_code_can_be_taken_by_a_sim_swap_or_number__3d096b96')}
                    </p>
                    <p>{tc('console.every_text_costs_money_codes_go_only_to_the__171894a6')}</p>
                </div>

                <Checkbox
                    label={tc('console.accept_text_message_codes_as_a_second_factor_af308439')}
                    checked={form.data.enabled}
                    onCheckedChange={(checked) => form.setData('enabled', checked)}
                />

                <Field
                    label={tc('console.countries_8faf7ec7')}
                    hint={tc('console.only_numbers_in_these_countries_can_be_added_4859ca3c')}
                    error={form.errors.allowedCountries}
                >
                    <Combobox
                        aria-label={tc('console.add_a_country_3891b432')}
                        value={adding}
                        placeholder={tc('console.add_a_country_fde01a47')}
                        searchPlaceholder={tc('console.search_countries_1a765360')}
                        options={options}
                        onValueChange={(code) => {
                            setAdding(undefined);
                            form.setData('allowedCountries', [...form.data.allowedCountries, code]);
                        }}
                    />
                </Field>

                {form.data.allowedCountries.length > 0 ? (
                    <ul
                        className="flex flex-wrap gap-2"
                        aria-label={tc('console.allowed_countries_d7d6903b')}
                    >
                        {form.data.allowedCountries.map((code) => (
                            <li key={code}>
                                <Badge>
                                    {label(code)} <span className="mono">{code}</span>
                                    <button
                                        type="button"
                                        className="ml-1.5"
                                        aria-label={tc('console.remove_value0_b597e5fb', {
                                            value0: label(code),
                                        })}
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
                        {tc('console.no_countries_yet_add_at_least_one_before_tur_c9f87d37')}
                    </p>
                )}

                {outsideDeployment.length > 0 && (
                    <p className="text-sm" style={{ color: 'var(--warning-strong)' }}>
                        {tc('console.this_deployment_only_texts_5852449e')}
                        {setting.deploymentCountries.join(', ')}
                        {tc('console.so_numbers_in_6914049a')}
                        {outsideDeployment.map(label).join(', ')}
                        {tc('console.cannot_receive_codes_until_cbox_id_sms_allow_5bba04f1')}
                    </p>
                )}

                <Checkbox
                    label={tc('console.owners_and_admins_need_an_authenticator_app__a1f2b392')}
                    hint={tc('console.an_owner_or_admin_can_add_sms_only_next_to_a_07347449')}
                    checked={form.data.privilegedNeedStrongerFactor}
                    onCheckedChange={(checked) =>
                        form.setData('privilegedNeedStrongerFactor', checked)
                    }
                />

                <div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.save_text_message_settings_79884210')}
                    </Button>
                </div>
            </form>
        </Panel>
    );
}
