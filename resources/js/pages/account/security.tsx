import { useTranslator } from '@/i18n';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';
import AccountLayout from '@/layouts/AccountLayout';
import { countryOptions } from '@/lib/countries';
import {
    defaultPasskeyName,
    isCancellation,
    PasskeyError,
    passkeysSupported,
    registerPasskey,
} from '@/lib/passkeys';
import type { HelpContent, PageProps } from '@/types';
import {
    Button,
    Combobox,
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
    ProviderMark,
} from '@/ui';

interface PasskeyRow {
    id: string;
    name: string;
    added: string | null;
    /** "2 days ago", or null when it has not signed in since this was recorded. */
    lastUsed: string | null;
    renameHref: string;
    removeHref: string;
}

/** Which passkey the naming dialog is about, and whether it was just created. */
interface Naming {
    name: string;
    renameHref: string;
    fresh: boolean;
}

/** One linked sign-in identity — a provider account that can sign in as this one. */
interface SignInIdentityRow {
    id: string;
    /** The catalogue key (`google`) — also the mark drawn — or `sso` for an organization's own. */
    provider: string;
    label: string;
    /** The address the provider gave when it was linked; null when it gave none. */
    email: string | null;
    linkedOn: string | null;
    /** "2 days ago", or null when it has not signed in since this was recorded. */
    lastUsed: string | null;
    /** Null for an organization's single sign-on, which is not this page's to remove. */
    disconnectHref: string | null;
}

/** A provider this environment offers that this account has not linked. */
interface ConnectableProvider {
    provider: string;
    label: string;
    connectHref: string;
}

interface TotpRow {
    id: string;
    name: string;
    added: string | null;
    renameHref: string;
    removeHref: string;
}

type OrganizationRow = {
    id: string;
    name: string;
    role: string;
    current: boolean;
    isOwner: boolean;
    leaveHref: string;
};

type TotpSetup = { id: string; name: string; secret: string; qr: string; cancelHref: string };

type Props = PageProps<{
    help: HelpContent;
    returnTo: { url: string; host: string } | null;
    /** Where a tenant host's organization settings went (v2 `/settings`); null elsewhere. */
    organizationSettingsMoved: string | null;
    profile: {
        name: string;
        email: string | null;
        initial: string;
        organization: { name: string; role: string } | null;
    };
    /** The organizations this person belongs to, each with the way out of it. */
    organizations: OrganizationRow[];
    hasPassword: boolean;
    twoFactor: {
        enabled: boolean;
        offered: boolean;
        recoveryRemaining: number;
        devices: TotpRow[];
    };
    /** Null when this environment does not offer text-message codes and none is on file. */
    smsFactor: {
        enrolled: boolean;
        pending: boolean;
        maskedNumber: string | null;
        countries: string[];
        /** An administrator with no authenticator app or passkey: SMS cannot come first. */
        blocked: boolean;
        needsStrongerFactor: boolean;
    } | null;
    passkeys: PasskeyRow[];
    /** Whether a passkey may be added here — off when the environment or deployment turned passkeys off. */
    passkeysEnabled: boolean;
    signInMethods: {
        identities: SignInIdentityRow[];
        connectable: ConnectableProvider[];
    };
    session: { id: string; methods: string[]; signedIn: string | null } | null;
    otherSessions: number;
    urls: {
        profile: string;
        password: string;
        enrolMfa: string;
        confirmMfa: string;
        recoveryCodes: string;
        enrolSms: string;
        confirmSms: string;
        removeSms: string;
        signOutOthers: string;
        logout: string;
        activity: string;
    };
}>;

export default function Security({
    help,
    returnTo,
    organizationSettingsMoved,
    profile,
    organizations,
    hasPassword,
    twoFactor,
    smsFactor,
    passkeys,
    passkeysEnabled,
    signInMethods,
    session,
    otherSessions,
    urls,
}: Props) {
    const { t: tc } = useTranslator();

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
                    {tc('console.return_to_353f0e89')}
                    {returnTo.host}
                </a>
            )}

            <PageHeader
                help={help}
                description={tc('console.your_profile_how_you_sign_in_and_the_session_ab7a0592')}
            />

            {organizationSettingsMoved !== null && (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.organization_settings_moved_here_7b3e91c4')}{' '}
                    <a
                        href={organizationSettingsMoved}
                        className="font-medium"
                        style={{ color: 'var(--accent-strong)' }}
                    >
                        {tc('console.organization_settings_4fd62541')}
                    </a>
                </p>
            )}

            <ProfilePanel profile={profile} href={urls.profile} />

            {organizations.length > 0 && <OrganizationsPanel organizations={organizations} />}

            <SignInMethodsPanel
                methods={signInMethods}
                hasPassword={hasPassword}
                passkeys={passkeys.length}
            />

            <PasswordPanel hasPassword={hasPassword} email={profile.email} href={urls.password} />

            <TwoFactorPanel twoFactor={twoFactor} urls={urls} />

            {smsFactor !== null && (
                <SmsPanel sms={smsFactor} totpEnabled={twoFactor.enabled} urls={urls} />
            )}

            <PasskeyPanel passkeys={passkeys} enabled={passkeysEnabled} />

            <SessionPanel session={session} otherSessions={otherSessions} urls={urls} />
        </div>
    );
}

/**
 * The person's organizations, and leaving one. Their own act on their own membership, so it
 * is here, on the page every host serves them: the login host has no console around it and
 * a customer environment's host has no People page.
 */
function OrganizationsPanel({ organizations }: { organizations: OrganizationRow[] }) {
    const { t: tc } = useTranslator();
    const [leaving, setLeaving] = useState<OrganizationRow | null>(null);
    const leave = useForm({});

    return (
        <Panel title={tc('console.organizations_2730183d')} data-test="account-organizations">
            <ul className="divide-y" style={{ borderColor: 'var(--border)' }}>
                {organizations.map((organization) => (
                    <li
                        key={organization.id}
                        className="flex flex-wrap items-center justify-between gap-3 py-3"
                    >
                        <span className="flex flex-wrap items-center gap-2 text-sm font-medium">
                            {organization.name}
                            <Pill dot={false}>{organization.role}</Pill>
                        </span>
                        <Button
                            size="sm"
                            onClick={() => {
                                leave.clearErrors();
                                setLeaving(organization);
                            }}
                        >
                            {tc('console.leave_fc6e4a40')}
                        </Button>
                    </li>
                ))}
            </ul>

            <Dialog
                open={leaving !== null}
                onOpenChange={(open) => {
                    if (!open && !leave.processing) setLeaving(null);
                }}
                title={tc('console.leave_part1_9c615c04', { part1: leaving?.name ?? '' })}
                description={
                    leaving?.isOwner
                        ? tc(
                              'console.you_lose_access_immediately_as_its_owner_you_can_only_l_d51a2272',
                          )
                        : tc(
                              'console.you_lose_access_to_this_organization_and_its_apps_immed_47fdba25',
                          )
                }
                footer={
                    <>
                        <Button disabled={leave.processing} onClick={() => setLeaving(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="danger"
                            loading={leave.processing}
                            onClick={() => {
                                if (leaving)
                                    leave.post(leaving.leaveHref, {
                                        preserveScroll: true,
                                        onSuccess: () => setLeaving(null),
                                    });
                            }}
                        >
                            {tc('console.leave_organization_ac8fd777')}
                        </Button>
                    </>
                }
            >
                {(leave.errors as Record<string, string>).organization && (
                    <p className="field-error" role="alert">
                        {(leave.errors as Record<string, string>).organization}
                    </p>
                )}
            </Dialog>
        </Panel>
    );
}

function ProfilePanel({ profile, href }: { profile: Props['profile']; href: string }) {
    const { t: tc } = useTranslator();

    const form = useForm({ displayName: profile.name });

    return (
        <Panel
            title={tc('console.profile_d696a35b')}
            description={tc('console.how_you_appear_across_cbox_id_84b2789d')}
        >
            <form
                className="flex items-start gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, { preserveScroll: true });
                }}
            >
                <span
                    className="idc-avatar shrink-0"
                    aria-hidden="true"
                    style={{ width: '3rem', height: '3rem', fontSize: '1.1rem' }}
                >
                    {profile.initial}
                </span>

                <div className="min-w-0 flex-1 space-y-4">
                    <Field label={tc('console.name_dcd1d522')} error={form.errors.displayName}>
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
                        <Kv label={tc('console.email_969ccbd3')}>{profile.email ?? '—'}</Kv>
                        {profile.organization !== null && (
                            <Kv label={tc('console.organization_d764d425')} prose>
                                {profile.organization.name}{' '}
                                <Pill dot={false}>{profile.organization.role}</Pill>
                            </Kv>
                        )}
                    </KvList>

                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.save_name_b7297226')}
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
    const { t: tc } = useTranslator();

    const form = useForm({
        currentPassword: '',
        newPassword: '',
        newPasswordConfirmation: '',
    });

    return (
        <Panel
            id="password"
            title={tc('console.password_e7cf3ef4')}
            description={
                hasPassword
                    ? tc('console.change_the_password_you_use_to_sign_in_b5425719')
                    : tc('console.set_a_password_to_sign_in_without_a_social_a_fd2b9dae')
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
                        label={tc('console.current_password_72ed2bd7')}
                        name="currentPassword"
                        autoComplete="current-password"
                        error={form.errors.currentPassword}
                        value={form.data.currentPassword}
                        onChange={(event) => form.setData('currentPassword', event.target.value)}
                    />
                )}

                <PasswordField
                    label={tc('console.new_password_3dd9df44')}
                    name="newPassword"
                    autoComplete="new-password"
                    policy
                    error={form.errors.newPassword}
                    value={form.data.newPassword}
                    onChange={(event) => form.setData('newPassword', event.target.value)}
                />

                <PasswordField
                    label={tc('console.confirm_new_password_bf000421')}
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
                        {hasPassword
                            ? tc('console.update_password_fe45b401')
                            : tc('console.set_password_4e411687')}
                    </Button>
                </div>
            </form>
        </Panel>
    );
}

function setupFromFlash(flash: ReturnType<typeof usePage>['flash']): TotpSetup | null {
    const { mfaSecret, mfaQrCode, mfaFactorId, mfaDeviceName, mfaCancelHref } = flash;
    return mfaSecret && mfaQrCode && mfaFactorId && mfaCancelHref
        ? {
              id: mfaFactorId,
              name: mfaDeviceName ?? '',
              secret: mfaSecret,
              qr: mfaQrCode,
              cancelHref: mfaCancelHref,
          }
        : null;
}

function TwoFactorPanel({
    twoFactor,
    urls,
}: {
    twoFactor: Props['twoFactor'];
    urls: Props['urls'];
}) {
    const { t: tc } = useTranslator();
    const flash = usePage().flash;
    const { recoveryCodes } = flash;
    const [setup, setSetup] = useState<TotpSetup | null>(() => setupFromFlash(flash));
    const [adding, setAdding] = useState(false);
    const [renaming, setRenaming] = useState<TotpRow | null>(null);
    const [removing, setRemoving] = useState<TotpRow | null>(null);
    const [regenerating, setRegenerating] = useState(false);
    const add = useForm({ name: '' });
    const form = useForm({ code: '' });
    const rename = useForm({ name: '' });
    const remove = useForm({ form: '' });
    const cancel = useForm({ form: '' });
    const codes = Array.isArray(recoveryCodes) ? (recoveryCodes as string[]) : null;

    // Keep a pending QR in component memory after a mistyped code. It is never remembered
    // in Inertia history, local storage or page props; leaving this page discards it.

    return (
        <Panel
            title={tc('console.totp_apps')}
            description={tc('console.totp_description')}
            action={
                twoFactor.enabled && <Pill tone="success">{tc('console.enabled_92c1cdfd')}</Pill>
            }
        >
            {twoFactor.devices.length > 0 && (
                <ul className="mb-5 divide-y divide-[var(--border)]">
                    {twoFactor.devices.map((device) => (
                        <li
                            key={device.id}
                            className="flex flex-col items-stretch justify-between gap-3 py-3 first:pt-0 sm:flex-row sm:items-center"
                        >
                            <div className="flex items-center gap-3 min-w-0 flex-1">
                                <Icon name="key" className="w-5 h-5 shrink-0" />
                                <div className="min-w-0">
                                    <p className="text-sm font-medium break-words">{device.name}</p>
                                    {device.added && (
                                        <p className="text-xs" style={{ color: 'var(--muted)' }}>
                                            {tc('console.totp_added_on', { date: device.added })}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="flex gap-2 shrink-0 self-end sm:self-auto">
                                <Button
                                    size="sm"
                                    aria-label={tc('console.totp_rename_named', {
                                        name: device.name,
                                    })}
                                    onClick={() => {
                                        rename.setData('name', device.name);
                                        rename.clearErrors();
                                        setRenaming(device);
                                    }}
                                >
                                    {tc('console.totp_rename')}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="danger"
                                    disabled={twoFactor.devices.length === 1}
                                    aria-label={tc('console.totp_remove_named', {
                                        name: device.name,
                                    })}
                                    onClick={() => {
                                        remove.clearErrors();
                                        setRemoving(device);
                                    }}
                                >
                                    {tc('console.totp_remove')}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {twoFactor.devices.length === 1 && (
                <p className="text-xs mb-4" style={{ color: 'var(--muted)' }}>
                    {tc('console.totp_last_device')}
                </p>
            )}

            {setup !== null ? (
                <div
                    className="space-y-4 rounded-xl p-4 sm:p-5"
                    style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
                >
                    <h3 className="font-medium break-words">
                        {tc('console.totp_setup_named', { name: setup.name })}
                    </h3>
                    <ol className="text-sm space-y-1" style={{ color: 'var(--muted)' }}>
                        <li>
                            {tc('console.1_scan_the_qr_code_with_your_authenticator_a_3150598a')}
                        </li>
                        <li>{tc('console.2_enter_the_6_digit_code_it_shows_3e5b7922')}</li>
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
                            <img
                                src={setup.qr}
                                alt={tc('console.authenticator_setup_qr_code_f154e1f3')}
                                width={220}
                                height={220}
                            />
                        </div>

                        <div className="min-w-0 flex-1 w-full">
                            <span className="label">
                                {tc('console.setup_key_manual_entry_39842f55')}
                            </span>
                            <div className="flex items-stretch gap-2">
                                <p
                                    className="mono text-sm p-3 rounded-lg select-all break-all flex-1 min-w-0"
                                    style={{
                                        background: 'var(--surface-2)',
                                        border: '1px solid var(--border)',
                                    }}
                                >
                                    {setup.secret}
                                </p>
                                <CopyButton
                                    value={setup.secret}
                                    className="shrink-0"
                                    aria-label={tc('console.copy_setup_key_44ea66fd')}
                                />
                            </div>
                        </div>
                    </div>

                    <form
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform((data) => ({ ...data, factor_id: setup.id }));
                            form.post(urls.confirmMfa, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setSetup(null);
                                    form.reset();
                                    add.reset();
                                },
                            });
                        }}
                    >
                        <div className="w-full sm:w-44">
                            <Field
                                label={tc('console.6_digit_code_0d1fa0df')}
                                error={form.errors.code}
                            >
                                <Input
                                    name="code"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    className="mono"
                                    placeholder="000000"
                                    value={form.data.code}
                                    required
                                    onChange={(event) => form.setData('code', event.target.value)}
                                />
                            </Field>
                        </div>
                        <Button
                            type="submit"
                            variant="primary"
                            loading={form.processing}
                            disabled={cancel.processing}
                        >
                            {tc('console.confirm_eebdd24a')}
                        </Button>
                        <Button
                            type="button"
                            loading={cancel.processing}
                            disabled={form.processing}
                            onClick={() =>
                                cancel.delete(setup.cancelHref, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setSetup(null);
                                        form.reset();
                                        add.reset();
                                    },
                                })
                            }
                        >
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        {cancel.errors.form && (
                            <p className="field-error w-full" role="alert">
                                {cancel.errors.form}
                            </p>
                        )}
                    </form>
                    <p className="text-xs" style={{ color: 'var(--muted)' }}>
                        {tc('console.totp_setup_expires')}
                    </p>
                </div>
            ) : !twoFactor.offered ? (
                <p className="text-sm" style={{ color: 'var(--muted)' }}>
                    {tc('console.your_administrator_has_turned_off_two_factor_e8143f3a')}
                </p>
            ) : twoFactor.devices.length >= 10 ? (
                <p className="text-sm" style={{ color: 'var(--muted)' }}>
                    {tc('console.totp_limit_reached')}
                </p>
            ) : adding ? (
                <form
                    className="space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.clearErrors();
                        cancel.clearErrors();
                        add.post(urls.enrolMfa, {
                            preserveScroll: true,
                            onSuccess: (page) => {
                                setSetup(setupFromFlash(page.flash));
                                setAdding(false);
                            },
                        });
                    }}
                >
                    <Field label={tc('console.totp_name')} error={add.errors.name}>
                        <Input
                            name="name"
                            required
                            maxLength={120}
                            autoComplete="off"
                            placeholder={tc('console.totp_name_example')}
                            value={add.data.name}
                            onChange={(event) => add.setData('name', event.target.value)}
                        />
                    </Field>
                    <p className="text-xs" style={{ color: 'var(--muted)' }}>
                        {tc('console.totp_name_hint')}
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" variant="primary" loading={add.processing}>
                            {tc('console.totp_show_qr')}
                        </Button>
                        <Button
                            type="button"
                            disabled={add.processing}
                            onClick={() => setAdding(false)}
                        >
                            {tc('console.cancel_19766ed6')}
                        </Button>
                    </div>
                </form>
            ) : (
                <Button
                    variant="primary"
                    onClick={() => {
                        add.clearErrors();
                        setAdding(true);
                    }}
                >
                    <Icon name="plus" className="w-4 h-4" />
                    {tc('console.totp_add')}
                </Button>
            )}

            {twoFactor.enabled && (
                <>
                    <div className="mt-4 pt-4" style={{ borderTop: '1px solid var(--border)' }}>
                        <div className="flex items-center gap-2 flex-wrap mb-1">
                            <h3 className="font-medium text-sm">
                                {tc('console.recovery_codes_36880d15')}
                            </h3>
                            <Pill dot={false}>
                                {tc('console.recovery_codes_remaining_count', {
                                    count: twoFactor.recoveryRemaining,
                                })}
                            </Pill>
                        </div>
                        <p className="text-sm" style={{ color: 'var(--muted)' }}>
                            {tc('console.single_use_codes_to_sign_in_if_you_lose_your_c578fbcf')}
                        </p>

                        {codes !== null && <RecoveryCodes codes={codes} />}

                        <Button className="mt-3" onClick={() => setRegenerating(true)}>
                            <Icon name="refresh" className="w-4 h-4" />
                            {twoFactor.recoveryRemaining > 0
                                ? tc('console.regenerate_codes_67b3a892')
                                : tc('console.generate_codes_b36ae7c7')}
                        </Button>
                    </div>

                    <Dialog
                        open={regenerating}
                        onOpenChange={setRegenerating}
                        title={tc('console.generate_new_recovery_codes_1b0cf5b6')}
                        description={tc(
                            'console.your_existing_codes_stop_working_immediately_c2dbcb02',
                        )}
                        footer={
                            <>
                                <Button onClick={() => setRegenerating(false)}>
                                    {tc('console.cancel_19766ed6')}
                                </Button>
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
                                    {tc('console.generate_49e49bb4')}
                                </Button>
                            </>
                        }
                    />
                </>
            )}

            <Dialog
                open={renaming !== null}
                onOpenChange={(open) => {
                    if (!open && !rename.processing) setRenaming(null);
                }}
                title={tc('console.totp_rename')}
            >
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (renaming)
                            rename.patch(renaming.renameHref, {
                                preserveScroll: true,
                                onSuccess: () => setRenaming(null),
                            });
                    }}
                >
                    <Field label={tc('console.totp_name')} error={rename.errors.name}>
                        <Input
                            required
                            maxLength={120}
                            value={rename.data.name}
                            onChange={(event) => rename.setData('name', event.target.value)}
                        />
                    </Field>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            disabled={rename.processing}
                            onClick={() => setRenaming(null)}
                        >
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button type="submit" variant="primary" loading={rename.processing}>
                            {tc('console.totp_save')}
                        </Button>
                    </div>
                </form>
            </Dialog>
            <Dialog
                open={removing !== null}
                onOpenChange={(open) => {
                    if (!open && !remove.processing) setRemoving(null);
                }}
                title={tc('console.totp_remove_named', { name: removing?.name ?? '' })}
                description={tc('console.totp_remove_explanation')}
                footer={
                    <>
                        <Button disabled={remove.processing} onClick={() => setRemoving(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="danger"
                            loading={remove.processing}
                            onClick={() => {
                                if (removing)
                                    remove.delete(removing.removeHref, {
                                        preserveScroll: true,
                                        onSuccess: () => setRemoving(null),
                                    });
                            }}
                        >
                            {tc('console.totp_remove')}
                        </Button>
                    </>
                }
            >
                {remove.errors.form && (
                    <p className="field-error" role="alert">
                        {remove.errors.form}
                    </p>
                )}
            </Dialog>
        </Panel>
    );
}

function RecoveryCodes({ codes }: { codes: string[] }) {
    const { t: tc } = useTranslator();

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
                    label={tc('console.copy_all_codes_17220f23')}
                    aria-label={tc('console.copy_all_recovery_codes_71d3af0d')}
                />
                <p className="text-xs" style={{ color: 'var(--destructive-strong)' }}>
                    {tc('console.shown_only_once_save_them_now_b44e87b6')}
                </p>
            </div>
        </>
    );
}

function PasskeyPanel({ passkeys, enabled }: { passkeys: PasskeyRow[]; enabled: boolean }) {
    const { t: tc } = useTranslator();

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
    const [naming, setNaming] = useState<Naming | null>(null);

    /*
     * ADD, IN ONE GO. If the step-up has lapsed the "Confirm it's you" dialog opens right
     * here, and its last step is a "Continue: create passkey" button the ceremony runs
     * inside (WebAuthn needs a fresh click). Then the new passkey is offered a name — the
     * provider's own when the passkey says which, "Passkey on Chrome · macOS" otherwise —
     * which the person can keep or change.
     */
    const add = useCallback(async () => {
        setBusy(true);
        setMessage(null);

        try {
            const created = await registerPasskey(
                defaultPasskeyName(),
                undefined,
                tc('console.continue_create_passkey_47944e9d'),
            );
            router.reload({ only: ['passkeys'] });

            if (created !== null) {
                setNaming({ name: created.name, renameHref: created.renameHref, fresh: true });
            }
        } catch (error) {
            // A person who dismissed the platform's own prompt — or the step-up — did not
            // fail at anything, and telling them they did is how a page teaches somebody
            // it is broken.
            setMessage(
                isCancellation(error)
                    ? null
                    : error instanceof PasskeyError
                      ? error.message
                      : tc('console.that_passkey_could_not_be_added_c7c701dc'),
            );
        } finally {
            setBusy(false);
        }
    }, [tc]);

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
            id="passkeys"
            title={tc('console.passkeys_c87ccdb4')}
            description={tc('console.sign_in_with_face_id_touch_id_windows_hello__97a33786')}
            action={
                supported &&
                enabled && (
                    <Button variant="primary" className="shrink-0" loading={busy} onClick={add}>
                        <Icon name="plus" className="w-4 h-4" />
                        {tc('console.add_passkey_a323d84f')}
                    </Button>
                )
            }
        >
            <output className="text-xs block mb-2" style={{ minHeight: '1rem' }}>
                {message}
            </output>

            {!enabled && (
                <p className="text-sm mb-3" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.passkeys_are_turned_off_here_so_you_cannot_a_9b3b4401')}
                </p>
            )}

            {enabled && !supported && (
                <p className="text-sm mb-3" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.this_browser_cannot_create_a_passkey_so_ther_2fac1ac0')}
                </p>
            )}

            {passkeys.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--faint)' }}>
                    {tc('console.no_passkeys_registered_yet_8a414b8f')}
                </p>
            ) : (
                <ul className="divide-y" style={{ borderColor: 'var(--border)' }}>
                    {passkeys.map((passkey) => (
                        <li
                            key={passkey.id}
                            className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3"
                        >
                            <div className="flex items-center gap-3 min-w-0">
                                <Icon
                                    name="fingerprint"
                                    className="w-4 h-4 shrink-0"
                                    style={{ color: 'var(--success-strong)' }}
                                />
                                <div className="min-w-0">
                                    <p className="text-sm font-medium break-words">
                                        {passkey.name}
                                    </p>
                                    <p
                                        className="text-xs"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {[
                                            `Added ${passkey.added ?? tc('console.on_an_unknown_date_c30b1ac5')}`,
                                            passkey.lastUsed === null
                                                ? tc('console.not_used_to_sign_in_yet_12293a9f')
                                                : `last used ${passkey.lastUsed}`,
                                        ].join(' · ')}
                                    </p>
                                </div>
                            </div>
                            <div className="flex items-center gap-2 shrink-0">
                                <Button
                                    size="sm"
                                    aria-label={tc('console.rename_value0_b709cc6f', {
                                        value0: passkey.name,
                                    })}
                                    onClick={() =>
                                        setNaming({
                                            name: passkey.name,
                                            renameHref: passkey.renameHref,
                                            fresh: false,
                                        })
                                    }
                                >
                                    {tc('console.rename_3064d79a')}
                                </Button>
                                <Button
                                    size="sm"
                                    variant="danger"
                                    aria-label={tc('console.remove_value0_b597e5fb', {
                                        value0: passkey.name,
                                    })}
                                    onClick={() => setRemoving(passkey)}
                                >
                                    {tc('console.remove_c3812fc4')}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {naming !== null && (
                // Keyed, so each passkey's dialog starts from its own name — never from the
                // draft the last one was left with.
                <PasskeyNameDialog
                    key={`${naming.renameHref}:${String(naming.fresh)}`}
                    naming={naming}
                    onClose={() => setNaming(null)}
                />
            )}

            {/*
                An ordinary confirm rather than type-to-confirm: this is the reader's own
                account, where the two-identical-tabs hazard that dialog exists for does not
                apply — and a passkey can be added again from this very panel.
            */}
            <Dialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={
                    removing === null
                        ? ''
                        : tc('console.remove_value0_b8b38a7a', { value0: removing.name })
                }
                description={tc('console.that_device_stops_being_able_to_sign_you_in__5e148d22')}
                footer={
                    <>
                        <Button onClick={() => setRemoving(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
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
                            {tc('console.remove_c3812fc4')}
                        </Button>
                    </>
                }
            />
        </Panel>
    );
}

/**
 * NAME A PASSKEY — straight after adding one, or later from the list.
 *
 * After adding, the field is filled with the name the server chose (the password manager
 * that made it, or the browser and system), so "Keep this name" is one click and costs
 * nothing: the passkey already has that name. Saving runs the same action the API and MCP
 * run (`account.passkeys.rename`), so the rule — 1 to 64 characters, no line breaks — and
 * the audit entry are the same at every door.
 */
function PasskeyNameDialog({ naming, onClose }: { naming: Naming; onClose: () => void }) {
    const { t: tc } = useTranslator();

    const form = useForm({ name: naming.name });
    const field = useRef<HTMLInputElement>(null);
    const { errors } = usePage().props;
    const serverError = typeof errors.passkeyName === 'string' ? errors.passkeyName : null;

    const trimmed = form.data.name.trim();
    const localError =
        trimmed === ''
            ? null
            : [...trimmed].length > 64
              ? tc('console.keep_the_name_to_64_characters_or_fewer_b186900e')
              : null;

    return (
        <Dialog
            open
            size="sm"
            initialFocus={field}
            onOpenChange={(open) => !open && onClose()}
            title={
                naming.fresh
                    ? tc('console.passkey_added_name_it_3793f072')
                    : tc('console.rename_passkey_419f7ffe')
            }
            description={
                naming.fresh
                    ? tc('console.give_it_a_name_you_will_recognise_later_like_3cdadec6')
                    : tc('console.a_name_you_will_recognise_later_like_the_dev_25a795c4')
            }
            footer={
                <>
                    <Button onClick={onClose}>
                        {naming.fresh
                            ? tc('console.keep_this_name_a2b5aba4')
                            : tc('console.cancel_19766ed6')}
                    </Button>
                    <Button
                        type="submit"
                        form="cbx-passkey-name"
                        variant="primary"
                        loading={form.processing}
                        disabled={trimmed === '' || localError !== null}
                    >
                        {tc('console.save_name_b7297226')}
                    </Button>
                </>
            }
        >
            <form
                id="cbx-passkey-name"
                onSubmit={(event) => {
                    event.preventDefault();

                    if (trimmed === '' || localError !== null) {
                        return;
                    }

                    form.transform((data) => ({ name: data.name.trim() }));
                    form.patch(naming.renameHref, {
                        preserveScroll: true,
                        onSuccess: (page) => {
                            const after = (page.props.errors ?? {}) as Record<string, unknown>;

                            if (typeof after.passkeyName !== 'string') {
                                onClose();
                            }
                        },
                    });
                }}
            >
                <Field
                    label={tc('console.name_dcd1d522')}
                    hint={tc('console.up_to_64_characters_9f115a96')}
                    error={localError ?? serverError ?? form.errors.name}
                >
                    <Input
                        ref={field}
                        name="name"
                        autoComplete="off"
                        maxLength={64}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </form>
        </Dialog>
    );
}

/**
 * MY ACCOUNT › SIGN-IN METHODS — every way in to this account, in one place.
 *
 * It replaced "Connected accounts", which listed only the deployment operator's own
 * providers and was absent entirely where Google was the environment's — so the sign-in
 * page's "connect it from your account settings" pointed at nothing. This lists what the
 * account HAS (password, passkeys, every linked provider account, whichever door it came
 * through) and offers what the sign-in page offers.
 *
 * Connect is an Inertia link: the visit clears the step-up in place, and the server then
 * answers it with a real navigation (`Inertia::location`), because the provider's consent
 * page is another origin a client-side visit cannot follow. Disconnect asks first, and the
 * server refuses it when it would leave no way in — the refusal is shown here.
 */
function SignInMethodsPanel({
    methods,
    hasPassword,
    passkeys,
}: {
    methods: Props['signInMethods'];
    hasPassword: boolean;
    passkeys: number;
}) {
    const { t: tc } = useTranslator();

    const { errors } = usePage().props;
    const [disconnecting, setDisconnecting] = useState<SignInIdentityRow | null>(null);
    const error = typeof errors.signInMethods === 'string' ? errors.signInMethods : null;

    return (
        <Panel
            id="sign-in-methods"
            title={tc('console.sign_in_methods_477be867')}
            description={tc('console.every_way_you_can_sign_in_linking_is_always__bcef066d')}
        >
            <ul className="divide-y divide-[color:var(--border)]">
                <li className="flex items-center justify-between gap-4 py-3">
                    <div className="flex items-center gap-3 min-w-0">
                        <Icon name="key" className="w-4 h-4 shrink-0" />
                        <span className="text-sm font-medium">
                            {tc('console.password_e7cf3ef4')}
                        </span>
                        {hasPassword ? (
                            <Pill tone="success">{tc('console.set_b6f6f3ad')}</Pill>
                        ) : (
                            <Pill tone="neutral">{tc('console.not_set_4895f731')}</Pill>
                        )}
                    </div>
                    <a
                        href="#password"
                        className="text-sm font-medium underline underline-offset-2 shrink-0"
                        style={{ color: 'var(--accent-strong)' }}
                    >
                        {hasPassword
                            ? tc('console.change_c0bf75bd')
                            : tc('console.set_a_password_1b10004d')}
                    </a>
                </li>

                <li className="flex items-center justify-between gap-4 py-3">
                    <div className="flex items-center gap-3 min-w-0">
                        <Icon name="shield" className="w-4 h-4 shrink-0" />
                        <span className="text-sm font-medium">
                            {tc('console.passkeys_c87ccdb4')}
                        </span>
                        <span className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {passkeys === 0
                                ? tc('console.none_yet_adb85cf2')
                                : passkeys === 1
                                  ? tc('console.1_passkey_02e9a8d3')
                                  : tc('console.value0_passkeys_ab6cc0db', { value0: passkeys })}
                        </span>
                    </div>
                    <a
                        href="#passkeys"
                        className="text-sm font-medium underline underline-offset-2 shrink-0"
                        style={{ color: 'var(--accent-strong)' }}
                    >
                        {tc('console.manage_5a234448')}
                    </a>
                </li>

                {methods.identities.map((identity) => (
                    <li
                        key={identity.id}
                        className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3"
                    >
                        <div className="flex items-center gap-3 min-w-0">
                            <ProviderMark provider={identity.provider} />
                            <div className="min-w-0">
                                <p className="text-sm font-medium">
                                    {identity.label}{' '}
                                    <Pill tone="success">{tc('console.linked_bfda026e')}</Pill>
                                </p>
                                <p
                                    className="text-xs break-all"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {[
                                        identity.email,
                                        identity.linkedOn === null
                                            ? null
                                            : `Linked ${identity.linkedOn}`,
                                        identity.lastUsed === null
                                            ? null
                                            : `last used ${identity.lastUsed}`,
                                    ]
                                        .filter((part): part is string => part !== null)
                                        .join(' · ')}
                                </p>
                            </div>
                        </div>
                        {identity.disconnectHref !== null ? (
                            <Button
                                size="sm"
                                variant="danger"
                                aria-label={tc('console.disconnect_value0_value1_28fdb90f', {
                                    value0: identity.label,
                                    value1: identity.email === null ? '' : ` (${identity.email})`,
                                })}
                                onClick={() => setDisconnecting(identity)}
                            >
                                {tc('console.disconnect_acfc5be7')}
                            </Button>
                        ) : (
                            <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                                {tc('console.managed_by_your_organization_54508019')}
                            </span>
                        )}
                    </li>
                ))}
            </ul>

            {error !== null && (
                <p className="field-error mt-2" role="alert">
                    {error}
                </p>
            )}

            {methods.connectable.length > 0 && (
                <div className="mt-4">
                    <h3 className="text-sm font-medium">
                        {tc('console.connect_another_way_to_sign_in_81600aea')}
                    </h3>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.you_sign_in_to_the_provider_and_it_is_linked_59410359')}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2.5">
                        {methods.connectable.map((provider) => (
                            /*
                                An Inertia visit, so a lapsed step-up is asked for HERE, in
                                the "Confirm it's you" dialog, and the click carries on once
                                it is answered; the server then sends the browser to the
                                provider for real. Still an anchor: it can be opened in a new
                                tab, which takes the full-page step-up instead.
                            */
                            <Link
                                key={provider.provider}
                                href={provider.connectHref}
                                className="btn btn-ghost"
                            >
                                <ProviderMark provider={provider.provider} />
                                <span>
                                    {tc('console.connect_3b887c98')}
                                    {provider.label}
                                </span>
                            </Link>
                        ))}
                    </div>
                </div>
            )}

            <Dialog
                open={disconnecting !== null}
                onOpenChange={(open) => !open && setDisconnecting(null)}
                title={
                    disconnecting === null
                        ? ''
                        : tc('console.disconnect_value0_1d09db11', { value0: disconnecting.label })
                }
                description={tc('console.you_stop_being_able_to_sign_in_with_it_if_it_66b928b9')}
                footer={
                    <>
                        <Button onClick={() => setDisconnecting(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                const identity = disconnecting;
                                setDisconnecting(null);

                                if (identity?.disconnectHref != null) {
                                    router.delete(identity.disconnectHref, {
                                        preserveScroll: true,
                                    });
                                }
                            }}
                        >
                            {tc('console.disconnect_acfc5be7')}
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
    const { t: tc } = useTranslator();

    const [signingOut, setSigningOut] = useState(false);

    return (
        <Panel
            title={tc('console.current_session_a32789d2')}
            description={tc('console.the_session_you_are_signed_in_with_right_now_258bbe06')}
        >
            {session === null ? (
                <p className="text-sm" style={{ color: 'var(--faint)' }}>
                    {tc('console.no_active_session_details_are_available_869756b3')}
                </p>
            ) : (
                <KvList>
                    <Kv label={tc('console.authentication_methods_22dfd75d')} prose>
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
                    <Kv label={tc('console.signed_in_ca566c89')}>{session.signedIn ?? '—'}</Kv>
                    <Kv label={tc('console.session_id_cb9ac5c5')}>{session.id}</Kv>
                </KvList>
            )}

            <div
                className="mt-5 pt-4 flex flex-wrap items-center gap-3"
                style={{ borderTop: '1px solid var(--border)' }}
            >
                <form method="POST" action={urls.logout}>
                    <Button type="submit" variant="danger">
                        <Icon name="logout" className="w-4 h-4" />
                        {tc('console.sign_out_b4ea0920')}
                    </Button>
                </form>

                {otherSessions > 0 && (
                    <Button onClick={() => setSigningOut(true)}>
                        <Icon name="logout" className="w-4 h-4" />
                        {tc('console.sign_out_other_sessions_action_count', {
                            count: otherSessions,
                        })}
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
                    {tc('console.see_where_you_are_signed_in_5fd7f027')}
                </Link>
            </div>

            <Dialog
                open={signingOut}
                onOpenChange={setSigningOut}
                title={tc('console.sign_out_other_sessions_count', { count: otherSessions })}
                description={tc('console.every_other_device_holding_a_session_as_you__a01280d4')}
                footer={
                    <>
                        <Button onClick={() => setSigningOut(false)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                setSigningOut(false);
                                router.post(urls.signOutOthers, {}, { preserveScroll: true });
                            }}
                        >
                            {tc('console.sign_out_48f0d3d3')}
                        </Button>
                    </>
                }
            />
        </Panel>
    );
}

Security.layout = (page: React.ReactNode) => <AccountLayout>{page}</AccountLayout>;

/**
 * TEXT-MESSAGE CODES — a phone number as a second factor, where the environment offers it.
 *
 * Adding one is a ceremony like the authenticator app's: the number is texted a code and
 * the code proves it. The number is shown masked even here — it is stored sealed, and the
 * page has no reason to hold more of it than the person needs to recognise it.
 *
 * The trade-off is said on the panel: this is the factor a SIM swap defeats, so it is
 * offered next to the stronger ones rather than instead of them.
 */
function SmsPanel({
    sms,
    totpEnabled,
    urls,
}: {
    sms: NonNullable<Props['smsFactor']>;
    totpEnabled: boolean;
    urls: Props['urls'];
}) {
    const { t: tc, locale: smsLocale } = useTranslator();

    const { smsEnrolmentSentTo, recoveryCodes } = usePage().flash;
    const enrol = useForm({ phone: '', country: sms.countries[0] ?? '' });
    const confirm = useForm({ smsCode: '' });
    const [removing, setRemoving] = useState(false);

    // The flash lives one render; a mistyped code must not lose the confirmation step.
    const awaitingCode = smsEnrolmentSentTo !== undefined || sms.pending;
    const codes = !totpEnabled && Array.isArray(recoveryCodes) ? (recoveryCodes as string[]) : null;

    return (
        <Panel
            title={tc('console.text_message_codes_9f1561f0')}
            description={tc('console.a_code_sent_to_your_phone_by_sms_as_a_second_074a279a')}
            action={sms.enrolled && <Pill tone="success">{tc('console.on_13001175')}</Pill>}
        >
            {sms.needsStrongerFactor && (
                <p className="mb-4 text-sm" style={{ color: 'var(--warning-strong)' }}>
                    {tc('console.as_an_administrator_add_an_authenticator_app_935b1e4e')}
                </p>
            )}

            {sms.enrolled ? (
                <>
                    <p className="text-sm" style={{ color: 'var(--muted)' }}>
                        {tc('console.codes_are_sent_to_fd74684d')}
                        <b className="mono">{sms.maskedNumber}</b>.
                    </p>

                    {codes !== null && <RecoveryCodes codes={codes} />}

                    <Button className="mt-4" variant="danger" onClick={() => setRemoving(true)}>
                        {tc('console.remove_phone_number_935b9204')}
                    </Button>

                    <Dialog
                        open={removing}
                        onOpenChange={setRemoving}
                        title={tc('console.remove_your_phone_number_06f081f0')}
                        description={tc(
                            'console.you_will_no_longer_be_able_to_sign_in_with_a_a575bedf',
                        )}
                        footer={
                            <>
                                <Button onClick={() => setRemoving(false)}>
                                    {tc('console.cancel_19766ed6')}
                                </Button>
                                <Button
                                    variant="danger"
                                    onClick={() => {
                                        setRemoving(false);
                                        router.delete(urls.removeSms, { preserveScroll: true });
                                    }}
                                >
                                    {tc('console.remove_c3812fc4')}
                                </Button>
                            </>
                        }
                    />
                </>
            ) : sms.blocked ? (
                <p className="text-sm" style={{ color: 'var(--muted)' }}>
                    {tc('console.as_an_administrator_set_up_an_authenticator__17d31053')}
                </p>
            ) : sms.countries.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--muted)' }}>
                    {tc('console.text_message_codes_are_no_longer_offered_her_1a9ddf89')}
                </p>
            ) : (
                <div className="space-y-4">
                    <p className="text-sm" style={{ color: 'var(--muted)' }}>
                        {tc('console.text_messages_are_the_weakest_second_step_so_fad83e4a')}
                    </p>

                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            enrol.post(urls.enrolSms, { preserveScroll: true });
                        }}
                    >
                        <div className="flex flex-col sm:flex-row gap-3">
                            {sms.countries.length > 1 && (
                                <Field
                                    label={tc('console.country_701d021d')}
                                    error={enrol.errors.country}
                                >
                                    {/* A flag and a name, found by typing; never an ISO code. */}
                                    <Combobox
                                        aria-label={tc('console.country_701d021d')}
                                        value={enrol.data.country || undefined}
                                        onValueChange={(code) => enrol.setData('country', code)}
                                        options={countryOptions(sms.countries, smsLocale)}
                                    />
                                </Field>
                            )}
                            <Field
                                label={tc('console.phone_number_306f1bb2')}
                                hint={tc(
                                    'console.international_format_works_anywhere_e_g_45_1_e06a0cd1',
                                )}
                                error={enrol.errors.phone}
                                className="flex-1"
                            >
                                <Input
                                    name="phone"
                                    type="tel"
                                    inputMode="tel"
                                    autoComplete="tel"
                                    maxLength={32}
                                    value={enrol.data.phone}
                                    onChange={(event) => enrol.setData('phone', event.target.value)}
                                />
                            </Field>
                        </div>

                        <Button
                            type="submit"
                            variant={awaitingCode ? 'secondary' : 'primary'}
                            loading={enrol.processing}
                        >
                            {awaitingCode
                                ? tc('console.send_a_new_code_9200fc2f')
                                : tc('console.text_me_a_code_54b10aea')}
                        </Button>
                    </form>

                    {awaitingCode && (
                        <form
                            className="space-y-4 pt-4"
                            style={{ borderTop: '1px solid var(--border)' }}
                            onSubmit={(event) => {
                                event.preventDefault();
                                confirm.post(urls.confirmSms, { preserveScroll: true });
                            }}
                        >
                            <p className="text-sm" style={{ color: 'var(--muted)' }}>
                                {smsEnrolmentSentTo !== undefined ? (
                                    <>
                                        {tc('console.we_sent_a_code_to_a2377ce0')}{' '}
                                        <b className="mono">{smsEnrolmentSentTo}</b>.
                                    </>
                                ) : (
                                    <>
                                        {tc('console.a_code_was_sent_to_522746c2')}{' '}
                                        <b className="mono">{sms.maskedNumber}</b>.
                                    </>
                                )}{' '}
                                {tc('console.enter_it_to_turn_on_text_message_codes_1d7162aa')}
                            </p>
                            <Field
                                label={tc('console.code_340f4630')}
                                error={confirm.errors.smsCode}
                            >
                                <Input
                                    name="smsCode"
                                    className="mono"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={10}
                                    value={confirm.data.smsCode}
                                    onChange={(event) =>
                                        confirm.setData('smsCode', event.target.value)
                                    }
                                />
                            </Field>
                            <Button type="submit" variant="primary" loading={confirm.processing}>
                                {tc('console.confirm_phone_number_3afac393')}
                            </Button>
                        </form>
                    )}
                </div>
            )}
        </Panel>
    );
}
