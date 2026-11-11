import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { flag } from '@/lib/countries';
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
    const { t: tc } = usePublicTranslator();
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleNestedText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich, choice: consoleChoice } = useConsoleTranslator();
    const { t: consoleT, locale } = useConsoleTranslator();
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
            <Badge className="ml-1">{consoleT('console.overridden_62a03f09')}</Badge>
        ) : null;

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={
                    onEnvironmentPlane
                        ? consoleText(
                              'console.the_baseline_every_organization_in_this_environment_inh_75711a2d',
                          )
                        : consoleFinalText(
                              'console.how_people_sign_in_to_part1_these_start_as_your_environ_133e7090',
                              { part1: scopeName },
                          )
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
                                {consoleRich(
                                    'console._part1_part2_nothing_here_is_set_by_part3_yet_change_an_a8ead075',
                                    {
                                        part1: (
                                            <strong style={{ color: 'var(--foreground)' }}>
                                                {consoleT(
                                                    'console.using_your_environment_s_defaults_caa183bc',
                                                )}
                                            </strong>
                                        ),
                                        part2: ' ',
                                        part3: scopeName,
                                    },
                                )}
                            </>
                        ) : (
                            <>
                                <strong style={{ color: 'var(--foreground)' }}>
                                    {consoleRich('console._part1_has_its_own_rules_2979eb4e', {
                                        part1: scopeName,
                                    })}
                                </strong>{' '}
                                {consoleChoice('console.auth_settings_differ', overridden.length)}
                            </>
                        )}
                    </p>

                    {!inheriting && (
                        <Button size="sm" onClick={() => setConfirming('inherit')}>
                            {consoleT('console.use_environment_defaults_458238c0')}
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
                                {consoleRich('console.minimum_password_length_part1_17210255', {
                                    part1: badge('minLength'),
                                })}
                            </>
                        }
                        error={form.errors.minLength}
                        hint={
                            onEnvironmentPlane
                                ? undefined
                                : consoleFinalText(
                                      'console.environment_default_part1_you_can_require_more_not_fewe_8d900b4b',
                                      { part1: baseline.minLength },
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
                                {consoleRich('console.password_reuse_label', {
                                    part1: badge('reuseHistory'),
                                })}
                            </>
                        }
                        error={form.errors.reuseHistory}
                        hint={
                            <>
                                {consoleRich('console.password_reuse_hint', {
                                    part1:
                                        !onEnvironmentPlane &&
                                        consoleNestedText(
                                            'console._environment_default_part1_3f0792ae',
                                            { part1: baseline.reuseHistory },
                                        ),
                                })}
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
                                {consoleRich('console.password_validity_label', {
                                    part1: badge('maxAgeDays'),
                                })}
                            </>
                        }
                        error={form.errors.maxAgeDays}
                        hint={
                            <>
                                {consoleRich('console.password_validity_hint', {
                                    part1:
                                        !onEnvironmentPlane &&
                                        consoleNestedText(
                                            'console._environment_default_part1_3f0792ae',
                                            {
                                                part1:
                                                    baseline.maxAgeDays === ''
                                                        ? consoleText('console.never_6497e4b3')
                                                        : duration(
                                                              Number(baseline.maxAgeDays) * 1440,
                                                              locale,
                                                          ),
                                            },
                                        ),
                                })}
                            </>
                        }
                    >
                        <Input
                            id="max-age"
                            name="maxAgeDays"
                            type="number"
                            min={1}
                            max={3650}
                            placeholder={consoleUiText('console.never_6300ef80')}
                            value={form.data.maxAgeDays}
                            onChange={(event) => form.setData('maxAgeDays', event.target.value)}
                        />
                    </Field>

                    <Field
                        id="lockout"
                        label={
                            <>
                                {consoleRich('console.lockout_attempts_label', {
                                    part1: badge('lockoutThreshold'),
                                })}
                            </>
                        }
                        error={form.errors.lockoutThreshold}
                        hint={
                            <>
                                {consoleRich('console.lockout_window_hint', {
                                    part1: duration(lockoutDefault.windowMinutes, locale),
                                    part2: duration(lockoutDefault.durationMinutes, locale),
                                    part3: ' ',
                                    part4:
                                        lockoutDefault.threshold === null
                                            ? consoleText(
                                                  'console.leave_empty_for_no_lockout_this_deployment_has_switched_d1f1a3e9',
                                              )
                                            : consoleNestedText(
                                                  'console.leave_empty_for_this_deployment_s_default_of_part1_f391c886',
                                                  { part1: lockoutDefault.threshold },
                                              ),
                                    part5:
                                        !onEnvironmentPlane &&
                                        consoleNestedText(
                                            'console._environment_default_part1_3f0792ae',
                                            {
                                                part1:
                                                    baseline.lockoutThreshold === ''
                                                        ? (lockoutDefault.threshold ??
                                                          consoleText('console.off_ca7981b4'))
                                                        : baseline.lockoutThreshold,
                                            },
                                        ),
                                })}
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
                                    ? consoleShortText('console.off_ca7981b4')
                                    : consoleFinalText('console._part1_default_aeb03a88', {
                                          part1: lockoutDefault.threshold,
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
                                {consoleRich('console.two_factor_authentication_part1_8c022f7b', {
                                    part1: badge('mfa'),
                                })}
                            </>
                        }
                        error={form.errors.mfa}
                    >
                        <Select
                            value={form.data.mfa}
                            onValueChange={(mfa) => form.setData('mfa', mfa)}
                            options={mfaOptions}
                            aria-label={consoleT('console.two_factor_authentication_b6824cc8')}
                        />
                    </Field>

                    <Field
                        label={
                            <>
                                {consoleRich('console.enterprise_sso_part1_ef8e20aa', {
                                    part1: badge('sso'),
                                })}
                            </>
                        }
                        error={form.errors.sso}
                    >
                        <Select
                            value={form.data.sso}
                            onValueChange={(sso) => form.setData('sso', sso)}
                            options={ssoOptions}
                            aria-label={consoleT('console.enterprise_sso_0f8d7e8e')}
                        />
                    </Field>
                </div>

                <Checkbox
                    checked={form.data.requireBreachCheck}
                    onCheckedChange={(checked) => form.setData('requireBreachCheck', checked)}
                    label={consoleT(
                        'console.refuse_passwords_found_in_known_data_breaches_checked_a_db4c6959',
                    )}
                />
                {form.errors.requireBreachCheck !== undefined && (
                    <p className="field-error" role="alert">
                        {form.errors.requireBreachCheck}
                    </p>
                )}

                <div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.save_rules_e5dbdffb')}
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
                        {consoleT('console.for_the_whole_environment_5b97e28f')}
                    </h2>
                    <p className="text-sm mt-1" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleRich(
                            'console.these_apply_to_every_organization_in_part1_not_only_par_ac56590d',
                            { part1: environmentName, part2: scopeName },
                        )}
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
                    title={consoleT('console.per_organization_8cb1cd51')}
                    description={consoleT(
                        'console.the_rules_in_force_after_this_environment_s_baseline_is_278c536a',
                    )}
                >
                    <div className="overflow-x-auto">
                        <Table
                            caption={consoleUiText(
                                'console.authentication_policy_in_force_per_organization_1463aba6',
                            )}
                        >
                            <thead>
                                <tr>
                                    <Th>{consoleT('console.organization_d764d425')}</Th>
                                    <Th>{consoleT('console.min_length_483ad8e5')}</Th>
                                    <Th>{consoleT('console.2fa_8ce118ca')}</Th>
                                    <Th>SSO</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {organizations.length === 0 ? (
                                    <tr>
                                        <Td colSpan={4} style={{ color: 'var(--faint)' }}>
                                            {consoleT(
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
                                                    <Badge>
                                                        {consoleT('console.override_43bc0f5f')}
                                                    </Badge>
                                                ) : (
                                                    <span
                                                        className="text-xs"
                                                        style={{ color: 'var(--faint)' }}
                                                    >
                                                        {consoleT('console.inherited_46b2e93c')}
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
                title={consoleFinalText('console.this_will_sign_people_out_of_part1_c2cac70c', {
                    part1: scopeName,
                })}
                description={consoleT(
                    'console.requiring_sso_refuses_every_other_way_in_and_ends_the_s_7d9d14ef',
                )}
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>
                            {consoleT('console.keep_passwords_working_b57484cf')}
                        </Button>
                        <Button variant="danger" onClick={save}>
                            {consoleT('console.require_sso_and_sign_everyone_out_474ac7df')}
                        </Button>
                    </>
                }
            >
                <ul className="space-y-1 text-sm list-disc pl-5">
                    <li>
                        {consoleRich(
                            'console.password_sign_in_stops_working_for_everyone_in_part1_im_adc97716',
                            { part1: scopeName },
                        )}
                    </li>
                    <li>
                        {consoleT(
                            'console.every_session_that_was_opened_with_a_password_ends_incl_cf05d595',
                        )}
                    </li>
                    <li>
                        {consoleT(
                            'console.people_get_back_in_through_your_identity_provider_anyon_6fd7e521',
                        )}
                    </li>
                    <li>
                        {consoleT(
                            'console.you_can_set_this_back_to_prefer_sso_or_both_available_a_b84203a4',
                        )}
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
                    title={consoleFinalText('console.let_people_sign_up_to_part1_6978f3c6', {
                        part1: environmentName,
                    })}
                    description={consoleT(
                        'console.anyone_who_reaches_one_of_your_apps_can_create_an_accou_eeb28c84',
                    )}
                    footer={
                        <>
                            <Button onClick={() => setConfirming(null)}>
                                {consoleT('console.keep_invitation_only_2cf257d6')}
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
                                {consoleT('console.turn_on_sign_up_487bb553')}
                            </Button>
                        </>
                    }
                >
                    <ul className="space-y-1 text-sm list-disc pl-5">
                        <li>
                            {consoleRich(
                                'console.your_sign_in_page_gets_a_create_an_account_link_and_app_8a665905',
                                { part1: <code>{'prompt=create'}</code> },
                            )}
                        </li>
                        <li>
                            {consoleRich(
                                'console.each_new_account_creates_its_own_organization_and_owns_3528fda3',
                                { part1: ' ', part2: <code>{'prompt=create_organization'}</code> },
                            )}
                        </li>
                        <li>
                            {consoleT(
                                'console.your_authentication_policy_still_applies_password_stren_84825ce9',
                            )}
                        </li>
                    </ul>
                </Dialog>
            )}

            <Dialog
                open={confirming === 'inherit'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={consoleFinalText(
                    'console.use_your_environment_s_defaults_for_part1_c214943d',
                    { part1: scopeName },
                )}
                description={consoleT(
                    'console.this_organization_s_own_authentication_policy_is_droppe_a12f3b55',
                )}
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>
                            {consoleT('console.cancel_19766ed6')}
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
                            {consoleT('console.use_environment_defaults_458238c0')}
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
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [saving, setSaving] = useState(false);

    if (!setting.decidedHere) {
        return (
            <Panel
                id="sign-up"
                title={consoleT('console.self_service_sign_up_613527ff')}
                description={consoleFinalText(
                    'console.decided_by_this_deployment_s_cbox_id_signup_mode_which_5691d2c8',
                    {
                        part1: setting.mode,
                        part2: setting.open
                            ? consoleText('console.open_2348f998')
                            : consoleText('console.closed_c3eefb58'),
                    },
                )}
            />
        );
    }

    return (
        <Panel
            id="sign-up"
            title={consoleT('console.self_service_sign_up_613527ff')}
            description={
                setting.enabled
                    ? consoleFinalText(
                          'console.anyone_can_create_an_account_in_part1_and_becomes_the_o_ff47cb27',
                          { part1: scopeName },
                      )
                    : consoleFinalText('console.people_join_part1_by_invitation_only_a1970c4f', {
                          part1: scopeName,
                      })
            }
            action={
                <Switch
                    aria-label={consoleT('console.self_service_sign_up_613527ff')}
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
                    {consoleT(
                        'console.on_here_but_this_deployment_has_closed_sign_up_everywhe_270a24b1',
                    )}
                </p>
            )}
            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {consoleRich(
                    'console.apps_send_people_to_sign_up_with_part1_and_ask_a_signed_e3725846',
                    {
                        part1: <code>{'prompt=create'}</code>,
                        part2: <code>{'prompt=create_organization'}</code>,
                    },
                )}
            </p>
        </Panel>
    );
}

/** "90 minutes", "8 hours", "2 days" — whichever reads whole. */
function duration(minutes: number, locale: string): string {
    const unit = minutes % 1440 === 0 ? 'day' : minutes % 60 === 0 ? 'hour' : 'minute';
    const value = unit === 'day' ? minutes / 1440 : unit === 'hour' ? minutes / 60 : minutes;
    return new Intl.NumberFormat(locale, { style: 'unit', unit, unitDisplay: 'long' }).format(
        value,
    );
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
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT, locale } = useConsoleTranslator();
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
            {consoleRich(
                'console.off_for_this_whole_deployment_part1_which_wins_over_thi_8cd60ca5',
                { part1: <code className="mono">{variable}</code> },
            )}
        </>
    );

    return (
        <Panel
            id="sign-in-methods"
            title={consoleT('console.sign_in_methods_and_sessions_66ef22a6')}
            description={consoleFinalText(
                'console.which_ways_in_part1_offers_besides_a_password_and_how_l_37522a30',
                { part1: scopeName },
            )}
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
                        label={consoleT('console.passkeys_c87ccdb4')}
                        hint={
                            deployment.passkeys
                                ? consoleText(
                                      'console.face_id_touch_id_windows_hello_or_a_security_key_turnin_6e23006c',
                                  )
                                : deploymentOff('CBOX_ID_PASSKEYS_ENABLED')
                        }
                        checked={deployment.passkeys && form.data.passkeys}
                        disabled={!deployment.passkeys}
                        onCheckedChange={(checked) => form.setData('passkeys', checked)}
                    />
                    <Checkbox
                        label={consoleT('console.magic_link_a8a8b124')}
                        hint={
                            deployment.magicLink
                                ? consoleText(
                                      'console.a_one_time_sign_in_link_by_email_turning_this_off_also_eeae7dbf',
                                  )
                                : deploymentOff('CBOX_ID_MAGIC_LINK_ENABLED')
                        }
                        checked={deployment.magicLink && form.data.magicLink}
                        disabled={!deployment.magicLink}
                        onCheckedChange={(checked) => form.setData('magicLink', checked)}
                    />
                    <Checkbox
                        label={consoleT('console.bot_challenge_ed4b037c')}
                        hint={
                            deployment.botChallenge
                                ? consoleText(
                                      'console.ask_a_sign_up_radar_flags_to_prove_it_is_a_person_cloud_5b5f2963',
                                  )
                                : consoleText(
                                      'console.this_deployment_has_no_turnstile_keys_cbox_id_turnstile_9ceda61f',
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
                        label={consoleT(
                            'console.end_a_session_after_this_long_without_activity_40e79de1',
                        )}
                        error={form.errors.sessionIdleMinutes}
                        hint={consoleFinalText(
                            'console.minutes_at_most_part1_empty_uses_the_deployment_s_part2_3412e91d',
                            {
                                part1: idleCeiling,
                                part2:
                                    deployment.sessionIdleMinutes > 0
                                        ? duration(deployment.sessionIdleMinutes, locale)
                                        : consoleText('console.setting_no_idle_timeout_9ce693ee'),
                                part3:
                                    setting.inForce.sessionIdleMinutes > 0
                                        ? duration(setting.inForce.sessionIdleMinutes, locale)
                                        : consoleText('console.none_140bedbf'),
                            },
                        )}
                    >
                        <Input
                            id="session-idle"
                            name="sessionIdleMinutes"
                            type="number"
                            min={1}
                            max={idleCeiling}
                            placeholder={
                                deployment.sessionIdleMinutes > 0
                                    ? consoleFinalText('console._part1_deployment_be39447b', {
                                          part1: deployment.sessionIdleMinutes,
                                      })
                                    : consoleText('console.none_deployment_64577084')
                            }
                            value={form.data.sessionIdleMinutes}
                            onChange={(event) =>
                                form.setData('sessionIdleMinutes', event.target.value)
                            }
                        />
                    </Field>

                    <Field
                        id="session-absolute"
                        label={consoleT(
                            'console.end_a_session_after_this_long_however_active_40352264',
                        )}
                        error={form.errors.sessionAbsoluteMinutes}
                        hint={consoleFinalText(
                            'console.minutes_at_most_part1_empty_uses_the_deployment_s_part2_2c261db9',
                            {
                                part1: deployment.sessionAbsoluteMinutes,
                                part2: duration(deployment.sessionAbsoluteMinutes, locale),
                            },
                        )}
                    >
                        <Input
                            id="session-absolute"
                            name="sessionAbsoluteMinutes"
                            type="number"
                            min={5}
                            max={deployment.sessionAbsoluteMinutes}
                            placeholder={consoleFinalText('console._part1_deployment_be39447b', {
                                part1: deployment.sessionAbsoluteMinutes,
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
                        {consoleT('console.save_sign_in_methods_a02553c8')}
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
    const { t: tc } = usePublicTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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

    // A flag before each name: a country is recognised, then picked by typing its name.
    const options = setting.countries
        .filter((country) => !form.data.allowedCountries.includes(country.value))
        .map((country) => ({
            value: country.value,
            label: `${flag(country.value)} ${country.label}`,
        }));

    return (
        <Panel
            id="sms"
            title={consoleT('console.text_message_codes_9f1561f0')}
            description={
                setting.enabled
                    ? consoleFinalText(
                          'console.people_in_part1_can_add_a_phone_number_and_receive_sign_dbeee425',
                          { part1: scopeName },
                      )
                    : consoleFinalText(
                          'console.off_people_in_part1_use_an_authenticator_app_a_passkey_27c6b7ea',
                          { part1: scopeName },
                      )
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
                        {consoleRich(
                            'console._part1_a_code_can_be_taken_by_a_sim_swap_or_number_port_690b2597',
                            {
                                part1: (
                                    <b>
                                        {consoleT(
                                            'console.sms_is_the_weakest_second_factor_offered_here_5a90b3ee',
                                        )}
                                    </b>
                                ),
                            },
                        )}
                    </p>
                    <p>
                        {consoleT(
                            'console.every_text_costs_money_codes_go_only_to_the_countries_l_171894a6',
                        )}
                    </p>
                    <p>{tc('console.every_text_costs_money_codes_go_only_to_the__171894a6')}</p>
                </div>

                <Checkbox
                    label={consoleT(
                        'console.accept_text_message_codes_as_a_second_factor_af308439',
                    )}
                    checked={form.data.enabled}
                    onCheckedChange={(checked) => form.setData('enabled', checked)}
                />

                <Field
                    label={consoleT('console.countries_8faf7ec7')}
                    hint={consoleT(
                        'console.only_numbers_in_these_countries_can_be_added_and_texted_4859ca3c',
                    )}
                    error={form.errors.allowedCountries}
                >
                    <Combobox
                        aria-label={consoleT('console.add_a_country_3891b432')}
                        value={adding}
                        placeholder={consoleUiText('console.add_a_country_fde01a47')}
                        searchPlaceholder={consoleT('console.search_countries_1a765360')}
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
                        aria-label={consoleT('console.allowed_countries_d7d6903b')}
                    >
                        {form.data.allowedCountries.map((code) => (
                            <li key={code}>
                                <Badge>
                                    {flag(code)} {label(code)}
                                    <button
                                        type="button"
                                        className="ml-1.5"
                                        aria-label={consoleFinalText(
                                            'console.remove_part1_369983dd',
                                            { part1: label(code) },
                                        )}
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
                        {consoleT(
                            'console.no_countries_yet_add_at_least_one_before_turning_text_m_c9f87d37',
                        )}
                    </p>
                )}

                {outsideDeployment.length > 0 && (
                    <p className="text-sm" style={{ color: 'var(--warning-strong)' }}>
                        {consoleRich(
                            'console.this_deployment_only_texts_part1_so_numbers_in_part2_ca_6cb21f4d',
                            {
                                part1: setting.deploymentCountries.join(', '),
                                part2: outsideDeployment.map(label).join(', '),
                            },
                        )}
                    </p>
                )}

                <Checkbox
                    label={consoleT(
                        'console.owners_and_admins_need_an_authenticator_app_or_a_passke_a1f2b392',
                    )}
                    hint={consoleT(
                        'console.an_owner_or_admin_can_add_sms_only_next_to_a_stronger_f_07347449',
                    )}
                    checked={form.data.privilegedNeedStrongerFactor}
                    onCheckedChange={(checked) =>
                        form.setData('privilegedNeedStrongerFactor', checked)
                    }
                />

                <div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {consoleT('console.save_text_message_settings_79884210')}
                    </Button>
                </div>
            </form>
        </Panel>
    );
}
