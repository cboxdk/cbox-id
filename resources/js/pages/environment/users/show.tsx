import { UserProfile } from './profile';
import { LinkTabs } from '@/ui';
import { EnvironmentReadOnly } from '@/ui/EnvironmentReadOnly';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { MoreHorizontal, Pencil } from 'lucide-react';
import { useState } from 'react';
import { absoluteTime } from '@/lib/time';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    AccessRoleHint,
    type AccessRoleOption,
    Badge,
    Breadcrumb,
    Button,
    Checkbox,
    Combobox,
    ConfirmDelete,
    CopyButton,
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    EmptyState,
    Field,
    Help,
    Input,
    Panel,
    Pill,
    type PillTone,
    type RoleOption,
    roleSelectOptions,
    Select,
    type StaffRoleOption,
    StaffRolePicker,
    staffRoleScope,
    type SupportSessionRow,
    SupportSessions,
} from '@/ui';

type AccessRole = AccessRoleOption;

interface MembershipRow {
    organizationId: string;
    organizationName: string;
    /** What a picker calls it: the name, with the slug where two share a name. */
    organizationLabel: string;
    /** Its slug: two organizations may share a name, never a slug. */
    organizationSlug: string | null;
    /** When they joined it. */
    joinedAt: string | null;
    role: string;
    /** Owners and admins cannot be impersonated — stepping in would hand over a tenant. */
    managesOrganization: boolean;
    accessRoles: AccessRole[];
    accessRoleIds: string[];
    href: string;
    urls: { role: string; accessRole: string; remove: string };
}

interface SessionRow {
    id: string;
    device: string | null;
    ip: string | null;
    lastActive: string | null;
    impersonation: boolean;
    revokeHref: string;
}

interface SupportProps {
    /** Only the apps a support session can reach: first-party, owned by this environment. */
    apps: { value: string; label: string }[];
    /** Only the organizations they are an active member of. */
    organizations: { value: string; label: string }[];
    maxMinutes: number;
    sessions: SupportSessionRow[];
    help: HelpContent;
    startHref: string;
}

type Props = PageProps<{
    user: {
        id: string;
        publicId: string;
        name: string | null;
        email: string;
        status: string;
        verified: boolean;
        hasMfa: boolean;
        /** The phone number for text-message codes, masked; null when there is none. */
        smsFactor: {
            maskedNumber: string;
            country: string;
            confirmed: boolean;
            /** Whether this environment's SMS policy accepts it right now. */
            usable: boolean;
        } | null;
        requiresPasswordChange: boolean;
    };
    memberships: MembershipRow[];
    joinableOrganizations: { value: string; label: string }[];
    joiningOrganization: string;
    joiningAccessRoles: AccessRole[];
    staffRoles: StaffRoleOption[];
    heldStaffRoles: string[];
    /** Role id → when the person was first given it. */
    staffRolesSince: Record<string, string>;
    staffHref: string;
    support: SupportProps;
    sessions: SessionRow[];
    /** What a new membership may be given — never Owner. */
    assignableRoles: RoleOption[];
    /** The same plus Owner, disabled: an owner's row names what it holds. */
    membershipRoles: RoleOption[];
    indexHref: string;
    urls: {
        update: string;
        password: string;
        passwordReset: string;
        resendVerification: string;
        markVerified: string;
        resetMfa: string;
        removeSms: string;
        deactivate: string;
        reactivate: string;
        erase: string;
        revokeAllSessions: string;
        assignOrganization: string;
        environmentRole: string;
        impersonate: string;
        /** Radar's decisions about this person's sign-ins and sign-ups — why one was challenged. */
        signInDecisions: string | null;
    };
}>;

function statusTone(status: string): PillTone {
    if (status === 'disabled') {
        return 'warning';
    }

    return status === 'locked' ? 'destructive' : 'success';
}

export default function UserDetail({
    user,
    memberships,
    joinableOrganizations,
    joiningOrganization,
    joiningAccessRoles,
    staffRoles,
    heldStaffRoles,
    staffRolesSince,
    staffHref,
    support,
    sessions,
    assignableRoles,
    membershipRoles,
    indexHref,
    urls,
}: Props) {
    const { t: tc } = useConsoleTranslator();

    const page = usePage<Props>();
    const requestedSection =
        new URL(page.url, window.location.origin).searchParams.get('section') ?? 'profile';
    const sections = [
        { key: 'profile', label: tc('console.profile_d696a35b') },
        { key: 'security', label: tc('console.security_8f6fb4eb') },
        { key: 'access', label: tc('console.access_ec5ba0ab') },
        { key: 'support', label: tc('console.support_access_851e9a34') },
        { key: 'settings', label: tc('console.settings_74a883a0') },
    ];
    const section = sections.some((item) => item.key === requestedSection)
        ? requestedSection
        : 'profile';
    const detailPath = page.url.split('?')[0];
    const label = user.name ?? user.email;

    return (
        <EnvironmentReadOnly>
            <div className="space-y-6">
                <div>
                    <Breadcrumb href={indexHref} label={tc('console.users_6b0cc904')} />
                    <div className="mt-2 flex items-center gap-3 flex-wrap">
                        <h1 className="cbx-page-title">{label}</h1>
                        {!user.verified && (
                            <Pill tone="warning">{tc('console.unverified_33c8e8de')}</Pill>
                        )}
                        <Pill tone={statusTone(user.status)}>
                            {tc(
                                user.status === 'active'
                                    ? 'console.active_92340695'
                                    : user.status === 'locked'
                                      ? 'console.ux_locked'
                                      : 'console.disabled_75081b59',
                            )}
                        </Pill>
                    </div>
                    <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                        {user.publicId}
                    </p>
                </div>

                <LinkTabs
                    label={tc('console.ux_user_details')}
                    tabs={sections.map((item) => ({
                        key: item.key,
                        label: item.label,
                        href: `${detailPath}?section=${item.key}`,
                        current: section === item.key,
                    }))}
                />

                {section === 'profile' && <UserProfile user={user} href={urls.update} />}

                {section === 'security' && (
                    <>
                        <Security user={user} urls={urls} />

                        <Sessions
                            sessions={sessions}
                            email={user.email}
                            revokeAllHref={urls.revokeAllSessions}
                            decisionsHref={urls.signInDecisions}
                        />
                    </>
                )}

                {section === 'access' && (
                    <>
                        <Organizations
                            user={user}
                            memberships={memberships}
                            joinableOrganizations={joinableOrganizations}
                            joiningOrganization={joiningOrganization}
                            joiningAccessRoles={joiningAccessRoles}
                            assignableRoles={assignableRoles}
                            membershipRoles={membershipRoles}
                            urls={urls}
                        />

                        <StaffRolesPanel
                            roles={staffRoles}
                            held={heldStaffRoles}
                            since={staffRolesSince}
                            staffHref={staffHref}
                            href={urls.environmentRole}
                        />
                    </>
                )}

                {section === 'support' && (
                    <>
                        <SupportAccess
                            support={support}
                            label={label}
                            active={user.status === 'active'}
                        />

                        <Impersonation memberships={memberships} href={urls.impersonate} />
                    </>
                )}

                {section === 'settings' && <EraseUser email={user.email} href={urls.erase} />}
            </div>
        </EnvironmentReadOnly>
    );
}

/**
 * Everything that can be done TO this account, and the one thing that cannot.
 *
 * The three actions gated on a step-up — setting a password, marking an address verified,
 * resetting two-factor — are the three that add up to a complete takeover, and the server
 * asks for the credential rather than the button being hidden.
 */
function Security({ user, urls }: { user: Props['user']; urls: Props['urls'] }) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    // Shown once: only a hash is stored, and props are written into the browser's history
    // entry, so the plaintext rides the flash channel and nowhere else.
    const issued = usePage().flash.issuedPassword;

    const [dismissed, setDismissed] = useState(false);
    const [setting, setSetting] = useState(false);
    const [resettingMfa, setResettingMfa] = useState(false);
    const [removingSms, setRemovingSms] = useState(false);
    const [deactivating, setDeactivating] = useState(false);

    return (
        <Panel title={consoleT('console.security_lifecycle_bc793cc5')}>
            <div className="space-y-4">
                {user.requiresPasswordChange && (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.this_user_is_held_at_a_password_change_they_cannot_reac_ce196382',
                        )}
                    </p>
                )}

                {issued !== undefined && !dismissed && (
                    <div
                        className="rounded-lg p-4"
                        style={{
                            border: '1px solid color-mix(in srgb, var(--warning) 40%, transparent)',
                            background: 'color-mix(in srgb, var(--warning) 8%, transparent)',
                        }}
                    >
                        <div className="flex items-start justify-between gap-4 flex-wrap">
                            <div className="min-w-0">
                                <p
                                    className="font-semibold text-sm"
                                    style={{ color: 'var(--warning-strong)' }}
                                >
                                    {consoleT(
                                        'console.copy_this_password_now_it_won_t_be_shown_again_ee41a1ae',
                                    )}
                                </p>
                                <p className="mt-3 select-all break-all mono text-sm">{issued}</p>
                                <p className="mt-3 text-xs" style={{ color: 'var(--faint)' }}>
                                    {consoleRich(
                                        'console.hand_it_to_part1_over_a_channel_you_trust_we_never_stor_205e825e',
                                        { part1: user.email },
                                    )}
                                </p>
                            </div>
                            <div className="flex items-center gap-2 shrink-0">
                                <CopyButton value={issued} variant="primary" />
                                <Button size="sm" onClick={() => setDismissed(true)}>
                                    {consoleT('console.dismiss_48845bff')}
                                </Button>
                            </div>
                        </div>
                    </div>
                )}

                {setting && (
                    <SetPassword
                        email={user.email}
                        href={urls.password}
                        onCancel={() => setSetting(false)}
                    />
                )}

                <div className="flex flex-wrap gap-2">
                    <SendMail
                        href={urls.passwordReset}
                        label={consoleT('console.send_password_reset_d22c5045')}
                    />

                    {!setting && (
                        <Button size="sm" onClick={() => setSetting(true)}>
                            {consoleT('console.set_password_2f1395cf')}
                        </Button>
                    )}

                    {!user.verified && (
                        <>
                            <SendMail
                                href={urls.resendVerification}
                                label={consoleT('console.resend_verification_1eedf1ae')}
                            />
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(urls.markVerified, {}, { preserveScroll: true })
                                }
                            >
                                {consoleT('console.mark_verified_1a32eb8a')}
                            </Button>
                        </>
                    )}

                    {user.hasMfa && (
                        <Button size="sm" onClick={() => setResettingMfa(true)}>
                            {consoleT('console.reset_2fa_01c88bb1')}
                        </Button>
                    )}

                    {user.smsFactor !== null && (
                        <Button size="sm" onClick={() => setRemovingSms(true)}>
                            {consoleT('console.remove_phone_number_935b9204')}
                        </Button>
                    )}

                    {user.status === 'active' ? (
                        <Button size="sm" variant="danger" onClick={() => setDeactivating(true)}>
                            {consoleT('console.deactivate_fb1e6fa5')}
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(urls.reactivate, {}, { preserveScroll: true })
                            }
                        >
                            {consoleT('console.reactivate_2dc7478f')}
                        </Button>
                    )}
                </div>

                <p className="text-xs" style={{ color: 'var(--faint)' }}>
                    {consoleRich('console.two_factor_part1_part2_d0a29caf', {
                        part1: user.hasMfa
                            ? consoleText('console.enabled_fb9cf756')
                            : consoleText('console.not_enrolled_4f827cce'),
                        part2: user.smsFactor !== null && (
                            <>
                                {consoleRich(
                                    'console._part1_text_message_codes_to_part2_part3_part4_63faabe2',
                                    {
                                        part1: ' ',
                                        part2: ' ',
                                        part3: (
                                            <span className="mono">
                                                {user.smsFactor.maskedNumber}
                                            </span>
                                        ),
                                        part4: !user.smsFactor.confirmed
                                            ? consoleText('console._not_yet_confirmed_4bae5cf3')
                                            : !user.smsFactor.usable
                                              ? consoleText(
                                                    'console._not_accepted_by_this_environment_s_sms_policy_1790f4c2',
                                                )
                                              : '',
                                    },
                                )}
                            </>
                        ),
                    })}
                </p>

                {/*
                    SAYS WHAT EACH OFF-SWITCH DOES. A delete button used to sit here and
                    reported success without erasing anything; an administrator who believes
                    an erasure happened stops pursuing it, which is the worse failure. The
                    erasure is real now, at the foot of the page — and deactivation still
                    keeps everything, which is what this says.
                */}
                <div
                    className="rounded-lg p-3 text-xs"
                    style={{ border: '1px solid var(--border)', color: 'var(--muted-foreground)' }}
                >
                    <p>
                        {consoleRich(
                            'console._part1_sessions_passkeys_and_second_factors_identity_pr_1ab27bf9',
                            {
                                part1: (
                                    <b>
                                        {consoleT(
                                            'console.deactivating_stops_all_sign_in_but_keeps_the_person_s_r_a03d5fe9',
                                        )}
                                    </b>
                                ),
                            },
                        )}
                    </p>
                    <p className="mt-1.5">
                        {consoleRich(
                            'console.to_answer_a_right_to_erasure_request_use_part1_at_the_f_536cbf09',
                            { part1: <b>{consoleT('console.erase_user_5a55d8c8')}</b> },
                        )}
                    </p>
                </div>
            </div>

            <ConfirmDelete
                open={resettingMfa}
                onOpenChange={setResettingMfa}
                name={user.email}
                title={consoleUiText('console.reset_user_mfa_title', { name: user.email })}
                verb={consoleAttributeText('console.reset_2fa_01c88bb1')}
                consequence={consoleUiText(
                    'console.this_destroys_the_user_s_enrolled_second_factor_and_the_288206be',
                )}
                onConfirm={() => {
                    setResettingMfa(false);
                    router.post(urls.resetMfa, {}, { preserveScroll: true });
                }}
            />

            {user.smsFactor !== null && (
                <ConfirmDelete
                    open={removingSms}
                    onOpenChange={setRemovingSms}
                    name={user.email}
                    title={consoleUiText('console.remove_user_phone_title', { name: user.email })}
                    verb={consoleAttributeText('console.remove_phone_number_935b9204')}
                    consequence={consoleFinalText(
                        'console.text_message_codes_to_part1_stop_working_their_authenti_81d0cd27',
                        { part1: user.smsFactor.maskedNumber },
                    )}
                    onConfirm={() => {
                        setRemovingSms(false);
                        router.post(urls.removeSms, {}, { preserveScroll: true });
                    }}
                />
            )}

            <ConfirmDelete
                open={deactivating}
                onOpenChange={setDeactivating}
                name={user.email}
                verb={consoleShortText('console.deactivate_fb1e6fa5')}
                consequence={consoleUiText(
                    'console.this_user_can_no_longer_sign_in_to_any_application_in_t_571ac0d8',
                )}
                onConfirm={() => {
                    setDeactivating(false);
                    router.post(urls.deactivate, {}, { preserveScroll: true });
                }}
            />
        </Panel>
    );
}

/**
 * A button that SENDS MAIL, and says it is busy.
 *
 * Without the busy state a double-click sent two reset links, and the second one
 * invalidates the first — so the person following the earlier mail is told their link has
 * expired for no reason they could see.
 */
function SendMail({ href, label }: { href: string; label: string }) {
    const { t: consoleText } = useConsoleTranslator();
    const [sending, setSending] = useState(false);

    return (
        <Button
            size="sm"
            loading={sending}
            onClick={() =>
                router.post(
                    href,
                    {},
                    {
                        preserveScroll: true,
                        onStart: () => setSending(true),
                        onFinish: () => setSending(false),
                    },
                )
            }
        >
            {sending ? consoleText('console.sending_b8ed5279') : label}
        </Button>
    );
}

/**
 * Generate a strong password in the BROWSER, so an admin never invents a weak one by hand.
 *
 * Client-side rather than a round trip: the credential exists in one fewer place that way,
 * and the alphabet is the same one the server's generator uses — no look-alike characters,
 * because somebody is about to read this aloud or type it from a note.
 */
function generatePassword(): string {
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const bytes = new Uint32Array(20);

    crypto.getRandomValues(bytes);

    return Array.from(bytes, (byte) => alphabet[byte % alphabet.length]).join('');
}

function SetPassword({
    email,
    href,
    onCancel,
}: {
    email: string;
    href: string;
    onCancel: () => void;
}) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        password: '',
        reason: '',
        mode: 'temporary',
        delivery: 'reveal',
        revoke: 'sessions_and_tokens',
        expiryHours: 24,
    });

    return (
        <form
            className="rounded-lg border p-4 space-y-4"
            style={{ borderColor: 'var(--border)' }}
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, { preserveScroll: true, onSuccess: () => onCancel() });
            }}
        >
            <Field label={consoleT('console.new_password_3dd9df44')} error={form.errors.password}>
                <div className="flex gap-2">
                    {/*
                        type="text", deliberately: the administrator has to be able to READ
                        what they are about to hand over, and it is not their own credential
                        being shoulder-surfed. autoComplete off so no password manager
                        offers to save somebody else's password into this admin's vault.
                    */}
                    <Input
                        name="password"
                        className="mono"
                        autoComplete="off"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                    <Button
                        type="button"
                        size="sm"
                        className="shrink-0"
                        onClick={() => form.setData('password', generatePassword())}
                    >
                        {consoleT('console.generate_49e49bb4')}
                    </Button>
                </div>
            </Field>

            <div className="grid sm:grid-cols-2 gap-4">
                <Field label={consoleT('console.type_baaddf70')} error={form.errors.mode}>
                    <Select
                        value={form.data.mode}
                        onValueChange={(mode) => form.setData('mode', mode)}
                        options={[
                            {
                                value: 'temporary',
                                label: consoleText(
                                    'console.temporary_they_must_change_it_at_next_sign_in_eb04cedb',
                                ),
                            },
                            {
                                value: 'permanent',
                                label: consoleText(
                                    'console.permanent_stands_until_they_change_it_a9923a2b',
                                ),
                            },
                        ]}
                    />
                </Field>

                {form.data.mode === 'temporary' && (
                    <Field
                        label={consoleT('console.valid_for_867ff87e')}
                        error={form.errors.expiryHours}
                    >
                        <Select
                            value={String(form.data.expiryHours)}
                            onValueChange={(hours) => form.setData('expiryHours', Number(hours))}
                            options={[
                                { value: '1', label: consoleText('console.1_hour_f8b8883f') },
                                { value: '24', label: consoleText('console.24_hours_f0514e8d') },
                                { value: '72', label: consoleText('console.3_days_36071944') },
                                {
                                    value: '0',
                                    label: consoleText('console.until_they_change_it_98433667'),
                                },
                            ]}
                        />
                    </Field>
                )}
            </div>

            <div className="grid sm:grid-cols-2 gap-4">
                <Field
                    label={consoleT('console.how_they_get_it_bd631bbf')}
                    error={form.errors.delivery}
                >
                    <Select
                        value={form.data.delivery}
                        onValueChange={(delivery) => form.setData('delivery', delivery)}
                        options={[
                            {
                                value: 'reveal',
                                label: consoleText('console.show_me_once_i_ll_pass_it_on_4a3cf299'),
                            },
                            {
                                value: 'email',
                                label: consoleFinalText('console.email_it_to_part1_c4ce7ffd', {
                                    part1: email,
                                }),
                            },
                        ]}
                    />
                </Field>

                <Field
                    label={consoleT('console.existing_access_3064b6b6')}
                    error={form.errors.revoke}
                >
                    <Select
                        value={form.data.revoke}
                        onValueChange={(revoke) => form.setData('revoke', revoke)}
                        options={[
                            {
                                value: 'sessions_and_tokens',
                                label: consoleText(
                                    'console.sign_out_everywhere_and_revoke_api_tokens_e5577aaf',
                                ),
                            },
                            {
                                value: 'sessions_only',
                                label: consoleText(
                                    'console.sign_out_everywhere_keep_api_tokens_f7cb68c8',
                                ),
                            },
                            {
                                value: 'nothing',
                                label: consoleText(
                                    'console.leave_existing_sessions_alone_3abdfa9c',
                                ),
                            },
                        ]}
                    />
                </Field>
            </div>

            <Field
                label={consoleT('console.reason_f81ab834')}
                hint={consoleT('console.recorded_on_the_audit_trail_alongside_your_name_2751f14b')}
                error={form.errors.reason}
            >
                <Input
                    name="reason"
                    maxLength={200}
                    placeholder={consoleRuntimeText(
                        'console.e_g_locked_out_after_losing_their_phone_509f27bc',
                    )}
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
            </Field>

            <div className="flex items-center gap-2">
                <Button type="submit" size="sm" variant="primary" loading={form.processing}>
                    {consoleT('console.set_password_4e411687')}
                </Button>
                <Button type="button" size="sm" onClick={onCancel}>
                    {consoleT('console.cancel_19766ed6')}
                </Button>
            </div>
        </form>
    );
}

function Sessions({
    sessions,
    email,
    revokeAllHref,
    decisionsHref,
}: {
    sessions: SessionRow[];
    email: string;
    revokeAllHref: string;
    decisionsHref: string | null;
}) {
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [revokingAll, setRevokingAll] = useState(false);

    return (
        <Panel
            title={consoleT('console.active_sessions_61ad9909')}
            description={consoleT(
                'console.who_is_signed_in_as_them_right_now_why_a_sign_in_was_al_181f0520',
            )}
            action={
                <div className="flex flex-wrap gap-2">
                    {/*
                        "Why was their last sign-in challenged?" is asked from the person's
                        page, and the answer — the rule that fired, the signals it read —
                        was on Radar with nothing here pointing at it.
                    */}
                    {decisionsHref !== null && (
                        <Button asChild size="sm" icon="shield">
                            <Link href={decisionsHref}>
                                {consoleT('console.sign_in_decisions_51fe82dc')}
                            </Link>
                        </Button>
                    )}
                    {sessions.length > 0 && (
                        <Button size="sm" variant="danger" onClick={() => setRevokingAll(true)}>
                            {consoleT('console.revoke_all_c6847a4b')}
                        </Button>
                    )}
                </div>
            }
        >
            <div className="space-y-2">
                {sessions.length === 0 ? (
                    <EmptyState
                        icon="shield"
                        title={consoleT('console.no_active_sessions_1f59d3a2')}
                        description={consoleT(
                            'console.this_user_has_no_signed_in_sessions_right_now_they_appe_0ac73d09',
                        )}
                    />
                ) : (
                    sessions.map((session) => (
                        <div
                            key={session.id}
                            className="flex items-center gap-3 rounded-lg border px-3 py-2"
                            style={{ borderColor: 'var(--border)' }}
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-sm truncate">
                                    {session.device ??
                                        consoleRuntimeText('console.unknown_device_06c4a77e')}
                                </p>
                                <p className="text-xs truncate" style={{ color: 'var(--faint)' }}>
                                    {session.ip ?? '—'} ·{' '}
                                    {session.lastActive ?? consoleT('console.never_6300ef80')}
                                    {session.impersonation && (
                                        <>
                                            {' · '}
                                            <span style={{ color: 'var(--accent-strong)' }}>
                                                {consoleT('console.impersonation_09d36284')}
                                            </span>
                                        </>
                                    )}
                                </p>
                            </div>
                            {/* One session, and they can sign in again: a quiet button, not a red one per row. */}
                            <Button
                                size="sm"
                                className="shrink-0"
                                onClick={() =>
                                    router.delete(session.revokeHref, { preserveScroll: true })
                                }
                            >
                                {consoleT('console.revoke_87e6d00b')}
                            </Button>
                        </div>
                    ))
                )}
            </div>

            <ConfirmDelete
                open={revokingAll}
                onOpenChange={setRevokingAll}
                name={email}
                title={consoleUiText('console.revoke_user_sessions_title', { name: email })}
                verb={consoleAttributeText('console.revoke_87e6d00b')}
                consequence={consoleUiText(
                    'console.every_one_of_this_user_s_sessions_is_terminated_immedia_ebf3fb04',
                )}
                onConfirm={() => {
                    setRevokingAll(false);
                    router.delete(revokeAllHref, { preserveScroll: true });
                }}
            />
        </Panel>
    );
}

/**
 * ORGANIZATIONS — one compact row per membership.
 *
 * Each row says which organization (its name, and its slug, because two organizations may
 * share a name), the built-in role as an inline picker, the app and custom roles as chips
 * with a pencil to change them, and the rarer actions behind "⋯" — removal among them,
 * confirmed. It used to be a tall card per organization with a full-width picker and a
 * red button, so four memberships filled the screen.
 */
function Organizations({
    user,
    memberships,
    joinableOrganizations,
    joiningOrganization,
    joiningAccessRoles,
    assignableRoles,
    membershipRoles,
    urls,
}: {
    user: Props['user'];
    memberships: MembershipRow[];
    joinableOrganizations: { value: string; label: string }[];
    joiningOrganization: string;
    joiningAccessRoles: AccessRole[];
    assignableRoles: RoleOption[];
    membershipRoles: RoleOption[];
    urls: Props['urls'];
}) {
    const { t, rich, locale } = useConsoleTranslator();
    const [managing, setManaging] = useState<string | null>(null);
    const [removing, setRemoving] = useState<MembershipRow | null>(null);

    return (
        <Panel
            title={t('console.organizations_2730183d')}
            description={rich(
                'console.each_organization_gives_them_one_part1_what_they_may_ad_69f922a2',
                {
                    part1: <b>{t('console.built_in_role_59a06e31')}</b>,
                    part2: <b>{t('console.app_and_custom_roles_5ccc5c71')}</b>,
                },
            )}
        >
            <div className="space-y-4">
                {memberships.length === 0 ? (
                    <EmptyState
                        icon="layers"
                        title={t('console.not_a_member_of_any_organization_49453904')}
                        description={t(
                            'console.add_them_to_one_below_to_grant_access_inside_it_or_if_y_386e5f3c',
                        )}
                    />
                ) : (
                    <div className="idc-access">
                        <div className="idc-access-head" aria-hidden>
                            <span>{t('console.organization_d764d425')}</span>
                            <span>{t('console.built_in_role_9f680a16')}</span>
                            <span>{t('console.app_and_custom_roles_ecb0207e')}</span>
                            <span />
                        </div>
                        <ul className="idc-access-list">
                            {memberships.map((membership) => {
                                const held = membership.accessRoles.filter((role) =>
                                    membership.accessRoleIds.includes(role.id),
                                );
                                const open = managing === membership.organizationId;

                                return (
                                    <li key={membership.organizationId}>
                                        <div className="idc-access-row">
                                            <div className="idc-access-who">
                                                <Link
                                                    href={membership.href}
                                                    className="idc-access-name"
                                                >
                                                    {membership.organizationName}
                                                </Link>
                                                <span className="idc-access-sub">
                                                    <span className="mono">
                                                        {membership.organizationSlug ??
                                                            membership.organizationId.slice(-8)}
                                                    </span>
                                                    {membership.joinedAt !== null && (
                                                        <span
                                                            title={absoluteTime(
                                                                membership.joinedAt,
                                                                locale,
                                                            )}
                                                        >
                                                            {t('console.user_access_since', {
                                                                when: shortDate(
                                                                    membership.joinedAt,
                                                                    locale,
                                                                ),
                                                            })}
                                                        </span>
                                                    )}
                                                </span>
                                            </div>

                                            <Select
                                                className="idc-access-role"
                                                aria-label={t(
                                                    'console.built_in_role_in_part1_de1834ee',
                                                    {
                                                        part1: membership.organizationName,
                                                    },
                                                )}
                                                value={membership.role}
                                                onValueChange={(role) =>
                                                    router.patch(
                                                        membership.urls.role,
                                                        { role },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                options={roleSelectOptions(membershipRoles)}
                                            />

                                            <div className="idc-access-chips">
                                                {held.length === 0 ? (
                                                    <span className="idc-access-none">
                                                        {membership.accessRoles.length === 0
                                                            ? t('console.user_access_none_offered')
                                                            : t('console.none_dc937b59')}
                                                    </span>
                                                ) : (
                                                    held.map((role) => (
                                                        <Badge key={role.id}>{role.name}</Badge>
                                                    ))
                                                )}
                                                {membership.accessRoles.length > 0 && (
                                                    <button
                                                        type="button"
                                                        className="idc-access-edit"
                                                        aria-expanded={open}
                                                        aria-label={t(
                                                            'console.user_access_edit_roles',
                                                            {
                                                                organization:
                                                                    membership.organizationName,
                                                            },
                                                        )}
                                                        title={t('console.edit_roles_3b3489d0')}
                                                        onClick={() =>
                                                            setManaging(
                                                                open
                                                                    ? null
                                                                    : membership.organizationId,
                                                            )
                                                        }
                                                    >
                                                        <Pencil
                                                            className="h-3.5 w-3.5"
                                                            aria-hidden
                                                        />
                                                    </button>
                                                )}
                                            </div>

                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        size="sm"
                                                        className="idc-access-more"
                                                        aria-label={t(
                                                            'console.more_actions_for_part1_b26d24f6',
                                                            { part1: membership.organizationName },
                                                        )}
                                                    >
                                                        <MoreHorizontal
                                                            className="h-4 w-4"
                                                            aria-hidden
                                                        />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent>
                                                    <DropdownMenuItem asChild>
                                                        <Link href={membership.href}>
                                                            {t(
                                                                'console.user_access_open_organization',
                                                            )}
                                                        </Link>
                                                    </DropdownMenuItem>
                                                    {membership.accessRoles.length > 0 && (
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                setManaging(
                                                                    membership.organizationId,
                                                                )
                                                            }
                                                        >
                                                            {t('console.edit_roles_3b3489d0')}
                                                        </DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuItem
                                                        destructive
                                                        onSelect={() => setRemoving(membership)}
                                                    >
                                                        {t(
                                                            'console.remove_from_organization_2416fd1f',
                                                        )}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>

                                        {open && (
                                            <div className="idc-access-editor">
                                                <p className="idc-access-sub">
                                                    {t('console.app_and_custom_roles_in_part1', {
                                                        part1: membership.organizationName,
                                                    })}
                                                </p>
                                                <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                                    {membership.accessRoles.map((role) => (
                                                        <Checkbox
                                                            key={role.id}
                                                            checked={membership.accessRoleIds.includes(
                                                                role.id,
                                                            )}
                                                            onCheckedChange={(granted) =>
                                                                router.post(
                                                                    membership.urls.accessRole,
                                                                    { role: role.id, granted },
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                            label={role.name}
                                                            hint={<AccessRoleHint role={role} />}
                                                        />
                                                    ))}
                                                </div>
                                                <div className="mt-3">
                                                    <Button
                                                        size="sm"
                                                        onClick={() => setManaging(null)}
                                                    >
                                                        {t('console.done_11a6767d')}
                                                    </Button>
                                                </div>
                                            </div>
                                        )}
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}

                <AddToOrganization
                    joinable={joinableOrganizations}
                    joining={joiningOrganization}
                    accessRoles={joiningAccessRoles}
                    assignableRoles={assignableRoles}
                    href={urls.assignOrganization}
                />
            </div>

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={user.email}
                title={t('console.remove_user_membership_title', { name: user.email })}
                verb={t('console.remove_c3812fc4')}
                consequence={t(
                    'console.they_lose_every_role_this_organization_grants_them_imme_81e63573',
                )}
                onConfirm={() => {
                    const membership = removing;
                    setRemoving(null);

                    if (membership !== null) {
                        router.delete(membership.urls.remove, { preserveScroll: true });
                    }
                }}
            />
        </Panel>
    );
}

/** A date without the time — "12 Nov 2026" — for a row's "since". */
function shortDate(iso: string, locale: string): string {
    return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(new Date(iso));
}

/**
 * STAFF ROLES — grants that name no organization.
 *
 * Every grant above is scoped to one organization, which cannot describe a support agent
 * acting across all of them, somebody who has joined none, or an app with no tenancy of
 * its own to hang a grant on. An app's own role granted here reaches only that app's
 * tokens; a role for all apps reaches every one.
 *
 * One row per grant — the role, the app it reaches and since when — with taking it back
 * behind "⋯" and confirmed, rather than a red button on every row.
 */
function StaffRolesPanel({
    roles,
    held,
    since,
    staffHref,
    href,
}: {
    roles: StaffRoleOption[];
    held: string[];
    since: Record<string, string>;
    staffHref: string;
    href: string;
}) {
    const { t, rich, locale } = useConsoleTranslator();
    const [role, setRole] = useState('');
    const [takingBack, setTakingBack] = useState<StaffRoleOption | null>(null);
    const { errors } = usePage().props;
    const holding = roles.filter((candidate) => held.includes(candidate.id));
    const offered = roles.filter((candidate) => !held.includes(candidate.id));

    return (
        <Panel
            title={t('console.admin_support_roles_19f22d25')}
            description={rich(
                'console.roles_held_across_the_whole_environment_in_every_organi_eb64cef7',
                {
                    part1: ' ',
                    part2: (
                        <Link
                            href={staffHref}
                            className="underline underline-offset-2"
                            style={{ color: 'var(--accent-strong)' }}
                        >
                            {t('console.everyone_with_an_admin_support_role_e0389623')}
                        </Link>
                    ),
                },
            )}
        >
            <div className="space-y-4">
                {holding.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        {t('console.no_admin_support_roles_7e88c28f')}
                    </p>
                ) : (
                    <div className="idc-access">
                        <div className="idc-access-head idc-access-head-staff" aria-hidden>
                            <span>{t('console.role_14736a2e')}</span>
                            <span>{t('console.user_access_reaches')}</span>
                            <span>{t('console.user_access_since_label')}</span>
                            <span />
                        </div>
                        <ul className="idc-access-list">
                            {holding.map((staffRole) => (
                                <li
                                    key={staffRole.id}
                                    className="idc-access-row idc-access-row-staff"
                                >
                                    <span className="idc-access-name">{staffRole.name}</span>
                                    <span className="idc-access-sub">
                                        {staffRoleScope(staffRole, t('console.all_apps_01bed311'))}
                                    </span>
                                    <span className="idc-access-sub">
                                        {(() => {
                                            const at = since[staffRole.id];

                                            return at === undefined ? '—' : shortDate(at, locale);
                                        })()}
                                    </span>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                size="sm"
                                                className="idc-access-more"
                                                aria-label={t(
                                                    'console.more_actions_for_part1_b26d24f6',
                                                    {
                                                        part1: staffRole.name,
                                                    },
                                                )}
                                            >
                                                <MoreHorizontal className="h-4 w-4" aria-hidden />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent>
                                            <DropdownMenuItem
                                                destructive
                                                onSelect={() => setTakingBack(staffRole)}
                                            >
                                                {t('console.take_back_7d2a64f5')}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {offered.length > 0 && (
                    <form
                        className="idc-access-add"
                        onSubmit={(event) => {
                            event.preventDefault();

                            if (role === '') {
                                return;
                            }

                            router.post(
                                href,
                                { role, granted: true },
                                { preserveScroll: true, onSuccess: () => setRole('') },
                            );
                        }}
                    >
                        <Field
                            label={t('console.grant_an_admin_support_role_ebbcd7de')}
                            error={errors.staffRole}
                        >
                            <StaffRolePicker roles={offered} value={role} onValueChange={setRole} />
                        </Field>
                        <Button
                            type="submit"
                            className="idc-access-add-button"
                            disabled={role === ''}
                        >
                            {t('console.grant_78b7d037')}
                        </Button>
                    </form>
                )}
            </div>

            <ConfirmDelete
                open={takingBack !== null}
                onOpenChange={(open) => !open && setTakingBack(null)}
                name={takingBack?.name ?? ''}
                title={t('console.take_back_the_admin_support_role_part1_9287a559', {
                    part1: takingBack?.name ?? '',
                })}
                actionLabel={t('console.take_back_7d2a64f5')}
                consequence={t(
                    'console.they_lose_this_role_in_every_organization_at_once_apps_39477fda',
                )}
                onConfirm={() => {
                    const staffRole = takingBack;
                    setTakingBack(null);

                    if (staffRole !== null) {
                        router.post(
                            href,
                            { role: staffRole.id, granted: false },
                            { preserveScroll: true },
                        );
                    }
                }}
            />
        </Panel>
    );
}

/**
 * The picker, and the roles of whichever organization it names.
 *
 * The role list follows the selection through a PARTIAL RELOAD rather than the page
 * shipping every organization's catalogue: app-declared roles are scoped per organization,
 * so the whole catalogue would grow with the environment and be wrong for all but one of
 * them anyway.
 */
function AddToOrganization({
    joinable,
    joining,
    accessRoles,
    assignableRoles,
    href,
}: {
    joinable: { value: string; label: string }[];
    joining: string;
    accessRoles: AccessRole[];
    assignableRoles: RoleOption[];
    href: string;
}) {
    const { t } = useConsoleTranslator();
    const form = useForm({
        organization: joining,
        role: 'member',
        accessRoles: [] as string[],
    });

    const choose = (organization: string): void => {
        form.setData({ ...form.data, organization, accessRoles: [] });

        router.reload({
            data: { org: organization },
            only: ['joiningOrganization', 'joiningAccessRoles'],
        });
    };

    if (joinable.length === 0) {
        return null;
    }

    return (
        <form
            className="space-y-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <div className="idc-access-add idc-access-add-org">
                <Field
                    label={t('console.add_to_organization_0e0cb636')}
                    error={form.errors.organization}
                >
                    <Combobox
                        value={form.data.organization === '' ? undefined : form.data.organization}
                        onValueChange={choose}
                        placeholder={t('console.find_an_organization_eb2523db')}
                        options={joinable}
                    />
                </Field>

                <Field label={t('console.built_in_role_9f680a16')} error={form.errors.role}>
                    <Select
                        value={form.data.role}
                        onValueChange={(role) => form.setData('role', role)}
                        options={roleSelectOptions(assignableRoles)}
                    />
                </Field>

                <Button
                    type="submit"
                    variant="primary"
                    loading={form.processing}
                    className="idc-access-add-button"
                    disabled={form.data.organization === ''}
                >
                    {t('console.add_9fd728c6')}
                </Button>
            </div>

            {form.data.organization !== '' && accessRoles.length > 0 && (
                <div>
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {t('console.app_and_custom_roles_granted_immediately_optional_313d930a')}
                    </p>
                    <div className="mt-2 grid gap-2 sm:grid-cols-2">
                        {accessRoles.map((role) => (
                            <Checkbox
                                key={role.id}
                                checked={form.data.accessRoles.includes(role.id)}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'accessRoles',
                                        checked
                                            ? [...form.data.accessRoles, role.id]
                                            : form.data.accessRoles.filter((id) => id !== role.id),
                                    )
                                }
                                label={role.name}
                                hint={<AccessRoleHint role={role} />}
                            />
                        ))}
                    </div>
                </div>
            )}
        </form>
    );
}

/**
 * "SIGN IN TO <APP> AS <USER>" — support access.
 *
 * Starting one sends this browser to the app, which starts its own sign-in and receives
 * tokens that name you as the one really there. Only apps the environment owns and trusts
 * are offered, only organizations the person is an active member of, and never longer
 * than the configured maximum; the server asks all three again.
 */
function SupportAccess({
    support,
    label,
    active,
}: {
    support: SupportProps;
    label: string;
    active: boolean;
}) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const durations = [15, 30, 60, support.maxMinutes]
        .filter(
            (minutes, index, all) =>
                minutes <= support.maxMinutes && all.indexOf(minutes) === index,
        )
        .sort((a, b) => a - b);

    const form = useForm({
        app: support.apps[0]?.value ?? '',
        organization: support.organizations[0]?.value ?? '',
        reason: '',
        minutes: String(Math.min(30, support.maxMinutes)),
    });

    const appName = support.apps.find((app) => app.value === form.data.app)?.label ?? 'an app';

    const unavailable =
        support.apps.length === 0
            ? 'No app can be entered this way yet. Support access reaches only first-party apps this environment owns that sign people in with a redirect.'
            : !active
              ? 'This person cannot sign in, so nobody can sign in as them either.'
              : support.organizations.length === 0
                ? 'They are not an active member of any organization, and a support session always acts inside one.'
                : null;

    return (
        <Panel
            title={
                <span className="inline-flex items-center gap-1.5">
                    {consoleRich('console.support_access_part1_1a6f6ed4', {
                        part1: <Help help={support.help} />,
                    })}
                </span>
            }
            description={consoleFinalText(
                'console.sign_in_to_one_of_your_apps_as_part1_to_see_what_they_s_1c96b645',
                { part1: label },
            )}
        >
            <div className="space-y-4">
                <SupportSessions sessions={support.sessions} lead="organization" />

                {unavailable !== null ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {unavailable}
                    </p>
                ) : (
                    <form
                        className="grid gap-3 sm:grid-cols-2 items-start"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(support.startHref, { preserveScroll: true });
                        }}
                    >
                        <Field label={consoleT('console.app_0d04bfeb')} error={form.errors.app}>
                            <Select
                                value={form.data.app}
                                onValueChange={(app) => form.setData('app', app)}
                                options={support.apps}
                            />
                        </Field>

                        <Field
                            label={consoleT('console.organization_d764d425')}
                            error={form.errors.organization}
                        >
                            <Select
                                value={form.data.organization}
                                onValueChange={(organization) =>
                                    form.setData('organization', organization)
                                }
                                options={support.organizations}
                            />
                        </Field>

                        <Field
                            label={consoleT('console.reason_f81ab834')}
                            hint={consoleT(
                                'console.the_organization_sees_this_on_its_audit_log_d8a04add',
                            )}
                            error={form.errors.reason}
                        >
                            <Input
                                name="reason"
                                maxLength={500}
                                required
                                placeholder={consoleRuntimeText(
                                    'console.ticket_4411_invoice_totals_look_wrong_4098e1f8',
                                )}
                                value={form.data.reason}
                                onChange={(event) => form.setData('reason', event.target.value)}
                            />
                        </Field>

                        <Field label={consoleT('console.for_ca15ebc0')} error={form.errors.minutes}>
                            <Select
                                value={form.data.minutes}
                                onValueChange={(minutes) => form.setData('minutes', minutes)}
                                options={durations.map((minutes) => ({
                                    value: String(minutes),
                                    label:
                                        minutes === 60
                                            ? '1 hour'
                                            : consoleFinalText('console._part1_minutes_60ab435a', {
                                                  part1: minutes,
                                              }),
                                }))}
                            />
                        </Field>

                        <div className="sm:col-span-2">
                            <Button type="submit" variant="primary" loading={form.processing}>
                                {consoleRich('console.sign_in_to_part1_as_part2_8473a968', {
                                    part1: appName,
                                    part2: label,
                                })}
                            </Button>
                        </div>
                    </form>
                )}
            </div>
        </Panel>
    );
}

/**
 * Stepping into somebody's session for support.
 *
 * NO MEMBERSHIP REQUIRED. This offered a picker of the user's organizations and, when they
 * had none, told the administrator to invent one — in an environment that may not use
 * organizations at all, which is where support is needed just as much. Without one the
 * session simply names no organization, exactly as an ordinary sign-in by that person
 * would.
 */
function Impersonation({ memberships, href }: { memberships: MembershipRow[]; href: string }) {
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [confirming, setConfirming] = useState(false);

    const impersonatable = memberships.filter((membership) => !membership.managesOrganization);
    const allowed = memberships.length === 0 || impersonatable.length > 0;

    const form = useForm({
        organization: impersonatable[0]?.organizationId ?? '',
        reason: '',
    });

    if (!allowed) {
        return (
            <Panel title={consoleT('console.support_impersonation_f82aa229')}>
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {consoleT(
                        'console.this_user_owns_or_administers_an_organization_and_those_941764ef',
                    )}
                </p>
            </Panel>
        );
    }

    return (
        <Panel
            title={consoleT('console.support_impersonation_f82aa229')}
            description={consoleT(
                'console.time_boxed_to_30_minutes_and_recorded_on_the_audit_trai_74ec6657',
            )}
        >
            <form
                className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] items-start"
                onSubmit={(event) => {
                    event.preventDefault();
                    setConfirming(true);
                }}
            >
                {impersonatable.length > 0 ? (
                    <Field
                        label={consoleT('console.organization_d764d425')}
                        error={form.errors.organization}
                    >
                        <Select
                            value={form.data.organization}
                            onValueChange={(organization) =>
                                form.setData('organization', organization)
                            }
                            options={impersonatable.map((membership) => ({
                                value: membership.organizationId,
                                label: membership.organizationLabel,
                            }))}
                        />
                    </Field>
                ) : (
                    <p className="text-sm self-center" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT('console.signed_in_as_themselves_in_no_organization_39da9100')}
                    </p>
                )}

                <Field label={consoleT('console.reason_f81ab834')} error={form.errors.reason}>
                    <Input
                        name="reason"
                        maxLength={200}
                        required
                        placeholder={consoleUiText('console.why_you_need_to_step_in_c1edb921')}
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                    />
                </Field>

                <Button type="submit" className="shrink-0 self-end" loading={form.processing}>
                    {consoleT('console.impersonate_f78577dc')}
                </Button>
            </form>

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={
                    impersonatable.find(
                        (membership) => membership.organizationId === form.data.organization,
                    )?.organizationLabel ?? ''
                }
                title={consoleUiText('console.impersonate_organization_title', {
                    name:
                        impersonatable.find(
                            (membership) => membership.organizationId === form.data.organization,
                        )?.organizationLabel ?? '',
                })}
                verb={consoleAttributeText('console.impersonate_f78577dc')}
                consequence={consoleUiText(
                    'console.you_act_as_this_person_in_their_session_with_their_acce_36ea7fac',
                )}
                onConfirm={() => {
                    setConfirming(false);
                    form.post(href);
                }}
            />
        </Panel>
    );
}

/**
 * The danger zone: erasing the person, GDPR Art. 17.
 *
 * THE ADDRESS IS TYPED, NOT CLICKED, and the server checks it again: a person's whole
 * account goes, and the two-identical-tabs hazard (staging and production) is exactly the
 * one a typed address catches. It also asks for a fresh credential, on the server, before
 * anything runs.
 *
 * Says what is NOT erased, too — the audit trail keeps the opaque id — because a
 * controller answering a regulator has to be able to say so precisely.
 */
function EraseUser({ email, href }: { email: string; href: string }) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [erasing, setErasing] = useState(false);
    const [busy, setBusy] = useState(false);
    const { errors } = usePage().props;

    return (
        <Panel
            title={consoleT('console.danger_zone_fd8b8dae')}
            description={consoleT(
                'console.erase_this_person_for_good_the_right_to_erasure_gdpr_ar_06ade5c9',
            )}
        >
            <div className="space-y-3">
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {consoleT(
                        'console.their_sessions_and_tokens_are_revoked_their_passkeys_se_78603573',
                    )}
                </p>

                {errors.erase !== undefined && (
                    <p className="field-error" role="alert">
                        {errors.erase}
                    </p>
                )}

                <Button size="sm" variant="danger" loading={busy} onClick={() => setErasing(true)}>
                    {consoleT('console.erase_user_0d51e048')}
                </Button>
            </div>

            <ConfirmDelete
                open={erasing}
                onOpenChange={setErasing}
                name={email}
                verb={consoleShortText('console.erase_511a2da0')}
                actionLabel={consoleUiText('console.erase_user_5a55d8c8')}
                consequence={consoleUiText(
                    'console.this_cannot_be_undone_their_credentials_memberships_and_0d29eed0',
                )}
                confirming={busy}
                onConfirm={() => {
                    setErasing(false);
                    router.post(
                        href,
                        { confirmation: email },
                        {
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
            />
        </Panel>
    );
}

UserDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
