import { initialsOf } from '@cboxdk/cbox-ui';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';
import { useTranslator } from '@/i18n';
import { RowMenu, SettingRow } from '@/chrome/AccountRows';
import AccountLayout from '@/layouts/AccountLayout';
import { countryOptions } from '@/lib/countries';
import {
    defaultPasskeyName,
    isCancellation,
    PasskeyError,
    passkeysSupported,
    registerPasskey,
} from '@/lib/passkeys';
import { absoluteDate, absoluteTime, relativeTime } from '@/lib/time';
import type { HelpContent, PageProps } from '@/types';
import {
    Button,
    Combobox,
    CopyButton,
    Dialog,
    DropdownMenuItem,
    Field,
    Icon,
    Input,
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
    /** ISO 8601; drawn in the reader's own format and zone. */
    added: string | null;
    /** ISO 8601, or null when it has not signed in since this was recorded. */
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
    /** ISO 8601. */
    linkedOn: string | null;
    /** ISO 8601, or null when it has not signed in since this was recorded. */
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
    /** ISO 8601. */
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
    /**
     * Why leaving is not offered, said rather than refused after the click: an owner hands
     * the organization over first, and a customer's team is administered in its own console.
     */
    leaveBlocked: 'owner' | 'managed' | null;
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
    /** `methods` are labels in the reader's language ("Password", "Approved on your phone"). */
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
                <a href={returnTo.url} className="cbx-back-link">
                    <Icon name="arrow-left" className="w-4 h-4" />
                    <span>
                        {tc('console.return_to_353f0e89')}
                        {returnTo.host}
                    </span>
                </a>
            )}

            <PageHeader
                help={help}
                description={tc('console.your_profile_how_you_sign_in_and_the_session_ab7a0592')}
            />

            {organizationSettingsMoved !== null && (
                <p className="acct-notice">
                    <Icon name="info" className="w-4 h-4 shrink-0" />
                    <span>
                        {tc('console.organization_settings_moved_here_7b3e91c4')}{' '}
                        <a href={organizationSettingsMoved}>
                            {tc('console.organization_settings_4fd62541')}
                        </a>
                    </span>
                </p>
            )}

            <ProfilePanel profile={profile} href={urls.profile} />

            <SignInMethodsPanel
                methods={signInMethods}
                hasPassword={hasPassword}
                email={profile.email}
                passwordHref={urls.password}
                passkeys={passkeys}
                passkeysEnabled={passkeysEnabled}
            />

            <TwoStepPanel twoFactor={twoFactor} smsFactor={smsFactor} urls={urls} />

            <SessionPanel session={session} otherSessions={otherSessions} urls={urls} />

            {organizations.length > 0 && <OrganizationsPanel organizations={organizations} />}
        </div>
    );
}

/* ─── Shared pieces ──────────────────────────────────────────────────────── */

/** "Added 12 Nov 2026 · last used 5 minutes ago", in the reader's language, format and zone. */
function useDates() {
    const { t: tc, locale } = useTranslator();

    return {
        date: (iso: string) => absoluteDate(iso, locale),
        added: (iso: string | null) =>
            iso === null
                ? null
                : tc('console.account_added_date', { date: absoluteDate(iso, locale) }),
        linked: (iso: string | null) =>
            iso === null
                ? null
                : tc('console.account_linked_date', { date: absoluteDate(iso, locale) }),
        lastUsed: (iso: string | null) =>
            iso === null
                ? tc('console.not_used_to_sign_in_yet_12293a9f')
                : tc('console.account_last_used_when', { when: relativeTime(iso, locale) }),
    };
}

function joinMeta(parts: (string | null | undefined)[]): string {
    return parts
        .filter((part): part is string => part !== null && part !== undefined && part !== '')
        .join(' · ');
}

/* ─── Profile ────────────────────────────────────────────────────────────── */

function ProfilePanel({ profile, href }: { profile: Props['profile']; href: string }) {
    const { t: tc } = useTranslator();

    const form = useForm({ displayName: profile.name });
    const changed = form.data.displayName.trim() !== profile.name;

    return (
        <Panel
            title={tc('console.profile_d696a35b')}
            description={tc('console.how_you_appear_across_cbox_id_84b2789d')}
        >
            <form
                className="acct-profile"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, { preserveScroll: true });
                }}
            >
                <span className="acct-avatar" aria-hidden="true">
                    {initialsOf(profile.name !== '' ? profile.name : (profile.email ?? ''))}
                </span>

                <div className="acct-profile-fields">
                    <Field label={tc('console.name_dcd1d522')} error={form.errors.displayName}>
                        <div className="acct-inline-field">
                            <Input
                                name="displayName"
                                maxLength={120}
                                autoComplete="name"
                                value={form.data.displayName}
                                onChange={(event) =>
                                    form.setData('displayName', event.target.value)
                                }
                            />
                            <Button
                                type="submit"
                                variant={changed ? 'primary' : 'secondary'}
                                disabled={!changed}
                                loading={form.processing}
                            >
                                {tc('console.save_name_b7297226')}
                            </Button>
                        </div>
                    </Field>

                    {/*
                        Read-only on purpose. The email is the sign-in identifier, and
                        changing it without re-proving control of the new address is how an
                        account gets taken over — so it moves through a verification flow,
                        not a text field on this page.
                    */}
                    <div>
                        <p className="label">{tc('console.email_address_f2488fd4')}</p>
                        <p className="acct-readonly">{profile.email ?? '—'}</p>
                        <p className="acct-hint">{tc('console.account_email_hint')}</p>
                    </div>
                </div>
            </form>
        </Panel>
    );
}

/* ─── Sign-in methods: password, passkeys, linked accounts ───────────────── */

/**
 * MY ACCOUNT › SIGN-IN METHODS — every way in to this account, in one place, each with its
 * own management right where it is listed: the password's change, each passkey's rename
 * and removal, each linked account's disconnect. One list, so nothing is shown twice.
 *
 * Connect is an Inertia link: the visit clears the step-up in place, and the server then
 * answers it with a real navigation (`Inertia::location`), because the provider's consent
 * page is another origin a client-side visit cannot follow. Disconnect asks first, and the
 * server refuses it when it would leave no way in — the refusal is shown here.
 */
function SignInMethodsPanel({
    methods,
    hasPassword,
    email,
    passwordHref,
    passkeys,
    passkeysEnabled,
}: {
    methods: Props['signInMethods'];
    hasPassword: boolean;
    email: string | null;
    passwordHref: string;
    passkeys: PasskeyRow[];
    passkeysEnabled: boolean;
}) {
    const { t: tc } = useTranslator();
    const dates = useDates();
    const { errors } = usePage().props;
    const [disconnecting, setDisconnecting] = useState<SignInIdentityRow | null>(null);
    // A link to `#password` (the hold that asks for a new password, a bookmark) opens it.
    const [changingPassword, setChangingPassword] = useState(
        () => typeof window !== 'undefined' && window.location.hash === '#password',
    );
    const error = typeof errors.signInMethods === 'string' ? errors.signInMethods : null;

    return (
        <Panel
            id="sign-in-methods"
            title={tc('console.sign_in_methods_477be867')}
            description={tc('console.every_way_you_can_sign_in_linking_is_always__bcef066d')}
            flush
        >
            <ul className="acct-list">
                <SettingRow
                    id="password"
                    icon="key"
                    title={tc('console.password_e7cf3ef4')}
                    badge={
                        hasPassword ? (
                            <Pill tone="success">{tc('console.set_b6f6f3ad')}</Pill>
                        ) : (
                            <Pill tone="neutral">{tc('console.not_set_4895f731')}</Pill>
                        )
                    }
                    meta={
                        hasPassword
                            ? tc('console.change_the_password_you_use_to_sign_in_b5425719')
                            : tc('console.set_a_password_to_sign_in_without_a_social_a_fd2b9dae')
                    }
                    actions={
                        <Button size="sm" onClick={() => setChangingPassword(true)}>
                            {hasPassword
                                ? tc('console.account_change_password')
                                : tc('console.set_password_4e411687')}
                        </Button>
                    }
                />
            </ul>

            <PasskeyGroup passkeys={passkeys} enabled={passkeysEnabled} />

            {methods.identities.length > 0 && (
                <ul className="acct-list">
                    {methods.identities.map((identity) => (
                        <SettingRow
                            key={identity.id}
                            mark={<ProviderMark provider={identity.provider} size={16} />}
                            title={identity.label}
                            badge={<Pill tone="success">{tc('console.linked_bfda026e')}</Pill>}
                            meta={joinMeta([
                                identity.email,
                                dates.linked(identity.linkedOn),
                                identity.lastUsed === null
                                    ? null
                                    : dates.lastUsed(identity.lastUsed),
                            ])}
                            actions={
                                identity.disconnectHref !== null ? (
                                    <RowMenu
                                        name={`${identity.label}${identity.email === null ? '' : ` (${identity.email})`}`}
                                    >
                                        <DropdownMenuItem
                                            destructive
                                            onSelect={() => setDisconnecting(identity)}
                                        >
                                            {tc('console.disconnect_acfc5be7')}
                                        </DropdownMenuItem>
                                    </RowMenu>
                                ) : (
                                    <span className="acct-hint">
                                        {tc('console.managed_by_your_organization_54508019')}
                                    </span>
                                )
                            }
                        />
                    ))}
                </ul>
            )}

            {error !== null && (
                <p className="field-error acct-section-pad" role="alert">
                    {error}
                </p>
            )}

            {methods.connectable.length > 0 && (
                <div className="acct-section acct-section-pad">
                    <h3 className="cbx-section-title">
                        {tc('console.connect_another_way_to_sign_in_81600aea')}
                    </h3>
                    <p className="acct-hint mt-1">
                        {tc('console.you_sign_in_to_the_provider_and_it_is_linked_59410359')}
                    </p>
                    <div className="mt-3 flex flex-wrap gap-2">
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
                                className="btn btn-ghost btn-sm"
                            >
                                <ProviderMark provider={provider.provider} size={16} />
                                <span>
                                    {tc('console.connect_3b887c98')}
                                    {provider.label}
                                </span>
                            </Link>
                        ))}
                    </div>
                </div>
            )}

            <PasswordDialog
                open={changingPassword}
                onClose={() => setChangingPassword(false)}
                hasPassword={hasPassword}
                email={email}
                href={passwordHref}
            />

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

function PasswordDialog({
    open,
    onClose,
    hasPassword,
    email,
    href,
}: {
    open: boolean;
    onClose: () => void;
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

    const close = () => {
        if (form.processing) return;
        form.reset();
        form.clearErrors();
        onClose();
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && close()}
            title={
                hasPassword
                    ? tc('console.account_change_password')
                    : tc('console.set_password_4e411687')
            }
            description={
                hasPassword
                    ? tc('console.change_the_password_you_use_to_sign_in_b5425719')
                    : tc('console.set_a_password_to_sign_in_without_a_social_a_fd2b9dae')
            }
            footer={
                <>
                    <Button disabled={form.processing} onClick={close}>
                        {tc('console.cancel_19766ed6')}
                    </Button>
                    <Button
                        type="submit"
                        form="cbx-password-form"
                        variant="primary"
                        loading={form.processing}
                    >
                        {hasPassword
                            ? tc('console.update_password_fe45b401')
                            : tc('console.set_password_4e411687')}
                    </Button>
                </>
            }
        >
            <form
                id="cbx-password-form"
                className="grid gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(href, {
                        preserveScroll: true,
                        preserveState: true,
                        onSuccess: () => {
                            form.reset();
                            onClose();
                        },
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
            </form>
        </Dialog>
    );
}

function PasskeyGroup({ passkeys, enabled }: { passkeys: PasskeyRow[]; enabled: boolean }) {
    const { t: tc } = useTranslator();
    const dates = useDates();

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
     * THE LIST STAYS EVEN WHERE PASSKEYS DO NOT WORK — only the enrolment button goes.
     *
     * Somebody who enrolled a passkey on their phone must be able to SEE it, and remove it,
     * from a work desktop whose browser lacks the API. Revoking a credential is exactly what
     * you want to do from the machine that cannot use it. So the list is always here, and
     * what is withheld is the affordance that would fail: adding one.
     */
    const note = !enabled
        ? tc('console.passkeys_are_turned_off_here_so_you_cannot_a_9b3b4401')
        : !supported
          ? tc('console.this_browser_cannot_create_a_passkey_so_ther_2fac1ac0')
          : null;

    return (
        <ul className="acct-list" id="passkeys">
            <SettingRow
                icon="fingerprint"
                title={tc('console.passkeys_c87ccdb4')}
                badge={
                    passkeys.length > 0 ? (
                        <Pill tone="success">
                            {passkeys.length === 1
                                ? tc('console.1_passkey_02e9a8d3')
                                : tc('console.value0_passkeys_ab6cc0db', {
                                      value0: passkeys.length,
                                  })}
                        </Pill>
                    ) : (
                        <Pill tone="neutral">{tc('console.none_yet_adb85cf2')}</Pill>
                    )
                }
                meta={note ?? tc('console.sign_in_with_face_id_touch_id_windows_hello__97a33786')}
                actions={
                    supported &&
                    enabled && (
                        <Button size="sm" icon="plus" loading={busy} onClick={add}>
                            {tc('console.add_passkey_a323d84f')}
                        </Button>
                    )
                }
            />

            {message !== null && (
                <li className="acct-row acct-row--nested">
                    <p className="field-error" role="alert">
                        {message}
                    </p>
                </li>
            )}

            {passkeys.map((passkey) => (
                <SettingRow
                    key={passkey.id}
                    nested
                    title={passkey.name}
                    meta={joinMeta([dates.added(passkey.added), dates.lastUsed(passkey.lastUsed)])}
                    actions={
                        <RowMenu name={passkey.name}>
                            <DropdownMenuItem
                                onSelect={() =>
                                    setNaming({
                                        name: passkey.name,
                                        renameHref: passkey.renameHref,
                                        fresh: false,
                                    })
                                }
                            >
                                {tc('console.rename_3064d79a')}
                            </DropdownMenuItem>
                            <DropdownMenuItem destructive onSelect={() => setRemoving(passkey)}>
                                {tc('console.remove_c3812fc4')}
                            </DropdownMenuItem>
                        </RowMenu>
                    }
                />
            ))}

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
                apply — and a passkey can be added again from this very list.
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
        </ul>
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

/* ─── Two-step verification: authenticator apps, text messages, recovery ── */

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

function TwoStepPanel({
    twoFactor,
    smsFactor,
    urls,
}: {
    twoFactor: Props['twoFactor'];
    smsFactor: Props['smsFactor'];
    urls: Props['urls'];
}) {
    const { t: tc } = useTranslator();
    const on = twoFactor.enabled || smsFactor?.enrolled === true;

    return (
        <Panel
            id="two-factor"
            title={tc('console.account_two_step_title')}
            description={tc('console.account_two_step_description')}
            action={
                on ? (
                    <Pill tone="success">{tc('console.on_13001175')}</Pill>
                ) : (
                    <Pill tone="warning">{tc('console.off_ca7981b4')}</Pill>
                )
            }
            flush
        >
            <AuthenticatorGroup twoFactor={twoFactor} urls={urls} />

            {smsFactor !== null && (
                <SmsGroup sms={smsFactor} totpEnabled={twoFactor.enabled} urls={urls} />
            )}

            {twoFactor.enabled && (
                <RecoveryGroup remaining={twoFactor.recoveryRemaining} href={urls.recoveryCodes} />
            )}
        </Panel>
    );
}

function AuthenticatorGroup({
    twoFactor,
    urls,
}: {
    twoFactor: Props['twoFactor'];
    urls: Props['urls'];
}) {
    const { t: tc } = useTranslator();
    const dates = useDates();
    const flash = usePage().flash;
    const [setup, setSetup] = useState<TotpSetup | null>(() => setupFromFlash(flash));
    const [adding, setAdding] = useState(false);
    const [renaming, setRenaming] = useState<TotpRow | null>(null);
    const [removing, setRemoving] = useState<TotpRow | null>(null);
    const add = useForm({ name: '' });
    const form = useForm({ code: '' });
    const rename = useForm({ name: '' });
    const remove = useForm({ form: '' });
    const cancel = useForm({ form: '' });
    const last = twoFactor.devices.length === 1;
    const canAdd = twoFactor.offered && twoFactor.devices.length < 10 && setup === null && !adding;

    // Keep a pending QR in component memory after a mistyped code. It is never remembered
    // in Inertia history, local storage or page props; leaving this page discards it.

    return (
        <ul className="acct-list">
            <SettingRow
                icon="phone"
                title={tc('console.totp_apps')}
                badge={
                    twoFactor.devices.length > 0 ? (
                        <Pill tone="success">{tc('console.on_13001175')}</Pill>
                    ) : undefined
                }
                meta={
                    !twoFactor.offered
                        ? tc('console.your_administrator_has_turned_off_two_factor_e8143f3a')
                        : twoFactor.devices.length >= 10
                          ? tc('console.totp_limit_reached')
                          : tc('console.account_totp_description')
                }
                actions={
                    canAdd && (
                        <Button
                            size="sm"
                            icon="plus"
                            onClick={() => {
                                add.clearErrors();
                                setAdding(true);
                            }}
                        >
                            {tc('console.totp_add')}
                        </Button>
                    )
                }
            />

            {twoFactor.devices.map((device) => (
                <SettingRow
                    key={device.id}
                    nested
                    title={device.name}
                    meta={joinMeta([
                        dates.added(device.added),
                        last ? tc('console.totp_last_device') : null,
                    ])}
                    actions={
                        <RowMenu name={device.name}>
                            <DropdownMenuItem
                                onSelect={() => {
                                    rename.setData('name', device.name);
                                    rename.clearErrors();
                                    setRenaming(device);
                                }}
                            >
                                {tc('console.totp_rename')}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                destructive
                                disabled={last}
                                onSelect={() => {
                                    remove.clearErrors();
                                    setRemoving(device);
                                }}
                            >
                                {tc('console.totp_remove')}
                            </DropdownMenuItem>
                        </RowMenu>
                    }
                />
            ))}

            {setup !== null && (
                <li className="acct-row acct-row--nested acct-row--block">
                    <div className="acct-setup">
                        <h3 className="cbx-section-title break-words">
                            {tc('console.totp_setup_named', { name: setup.name })}
                        </h3>
                        <ol className="acct-hint space-y-1">
                            <li>
                                {tc(
                                    'console.1_scan_the_qr_code_with_your_authenticator_a_3150598a',
                                )}
                            </li>
                            <li>{tc('console.2_enter_the_6_digit_code_it_shows_3e5b7922')}</li>
                        </ol>
                        <div className="flex flex-col sm:flex-row gap-4 items-start">
                            <div className="acct-qr">
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
                                    width={180}
                                    height={180}
                                />
                            </div>

                            <div className="min-w-0 flex-1 w-full">
                                <span className="label">
                                    {tc('console.setup_key_manual_entry_39842f55')}
                                </span>
                                <div className="flex items-stretch gap-2">
                                    <p className="acct-secret mono select-all">{setup.secret}</p>
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
                                        onChange={(event) =>
                                            form.setData('code', event.target.value)
                                        }
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
                        <p className="acct-hint">{tc('console.totp_setup_expires')}</p>
                    </div>
                </li>
            )}

            {adding && setup === null && (
                <li className="acct-row acct-row--nested acct-row--block">
                    <form
                        className="acct-setup"
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
                        <Field
                            label={tc('console.totp_name')}
                            hint={tc('console.totp_name_hint')}
                            error={add.errors.name}
                        >
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
                </li>
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
        </ul>
    );
}

function RecoveryGroup({ remaining, href }: { remaining: number; href: string }) {
    const { t: tc, choice } = useTranslator();
    const { recoveryCodes } = usePage().flash;
    const [regenerating, setRegenerating] = useState(false);
    const codes = Array.isArray(recoveryCodes) ? (recoveryCodes as string[]) : null;

    return (
        <ul className="acct-list">
            <SettingRow
                icon="lock"
                title={tc('console.recovery_codes_36880d15')}
                badge={
                    remaining > 0 ? (
                        <Pill tone={remaining <= 3 ? 'warning' : 'neutral'} dot={remaining <= 3}>
                            {choice('console.account_recovery_codes_left', remaining, {
                                count: remaining,
                            })}
                        </Pill>
                    ) : (
                        <Pill tone="warning">{tc('console.account_recovery_codes_none')}</Pill>
                    )
                }
                meta={tc('console.single_use_codes_to_sign_in_if_you_lose_your_c578fbcf')}
                actions={
                    <Button size="sm" icon="refresh" onClick={() => setRegenerating(true)}>
                        {remaining > 0
                            ? tc('console.regenerate_codes_67b3a892')
                            : tc('console.generate_codes_b36ae7c7')}
                    </Button>
                }
            />

            {codes !== null && (
                <li className="acct-row acct-row--nested acct-row--block">
                    <RecoveryCodes codes={codes} />
                </li>
            )}

            <Dialog
                open={regenerating}
                onOpenChange={setRegenerating}
                title={tc('console.generate_new_recovery_codes_1b0cf5b6')}
                description={tc('console.your_existing_codes_stop_working_immediately_c2dbcb02')}
                footer={
                    <>
                        <Button onClick={() => setRegenerating(false)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="primary"
                            onClick={() => {
                                setRegenerating(false);
                                router.post(href, {}, { preserveScroll: true });
                            }}
                        >
                            {tc('console.generate_49e49bb4')}
                        </Button>
                    </>
                }
            />
        </ul>
    );
}

function RecoveryCodes({ codes }: { codes: string[] }) {
    const { t: tc } = useTranslator();

    return (
        <div className="w-full">
            <div className="acct-codes mono select-all">
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
        </div>
    );
}

/**
 * TEXT-MESSAGE CODES — a phone number as a second factor, where the environment offers it.
 *
 * Adding one is a ceremony like the authenticator app's: the number is texted a code and
 * the code proves it. The number is shown masked even here — it is stored sealed, and the
 * page has no reason to hold more of it than the person needs to recognise it.
 *
 * The trade-off is said on the row: this is the factor a SIM swap defeats, so it is
 * offered next to the stronger ones rather than instead of them.
 */
function SmsGroup({
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
    const [adding, setAdding] = useState(false);

    // The flash lives one render; a mistyped code must not lose the confirmation step.
    const awaitingCode = smsEnrolmentSentTo !== undefined || sms.pending;
    const codes = !totpEnabled && Array.isArray(recoveryCodes) ? (recoveryCodes as string[]) : null;
    const offerable = !sms.enrolled && !sms.blocked && sms.countries.length > 0;
    const showForm = offerable && (adding || awaitingCode);

    return (
        <ul className="acct-list">
            <SettingRow
                icon="message"
                title={tc('console.text_message_codes_9f1561f0')}
                badge={
                    sms.enrolled ? (
                        <Pill tone="success">{tc('console.on_13001175')}</Pill>
                    ) : undefined
                }
                meta={
                    sms.enrolled ? (
                        <>
                            {tc('console.codes_are_sent_to_fd74684d')}
                            <span className="mono">{sms.maskedNumber}</span>.
                        </>
                    ) : sms.blocked ? (
                        tc('console.as_an_administrator_set_up_an_authenticator__17d31053')
                    ) : sms.countries.length === 0 ? (
                        tc('console.text_message_codes_are_no_longer_offered_her_1a9ddf89')
                    ) : (
                        tc('console.text_messages_are_the_weakest_second_step_so_fad83e4a')
                    )
                }
                actions={
                    sms.enrolled ? (
                        <RowMenu name={tc('console.text_message_codes_9f1561f0')}>
                            <DropdownMenuItem destructive onSelect={() => setRemoving(true)}>
                                {tc('console.remove_phone_number_935b9204')}
                            </DropdownMenuItem>
                        </RowMenu>
                    ) : (
                        offerable &&
                        !showForm && (
                            <Button size="sm" icon="plus" onClick={() => setAdding(true)}>
                                {tc('console.account_add_phone_number')}
                            </Button>
                        )
                    )
                }
            />

            {sms.needsStrongerFactor && (
                <li className="acct-row acct-row--nested">
                    <p className="text-sm" style={{ color: 'var(--warning-strong)' }}>
                        {tc('console.as_an_administrator_add_an_authenticator_app_935b1e4e')}
                    </p>
                </li>
            )}

            {codes !== null && (
                <li className="acct-row acct-row--nested acct-row--block">
                    <RecoveryCodes codes={codes} />
                </li>
            )}

            {showForm && (
                <li className="acct-row acct-row--nested acct-row--block">
                    <div className="acct-setup">
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
                                        onChange={(event) =>
                                            enrol.setData('phone', event.target.value)
                                        }
                                    />
                                </Field>
                            </div>

                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="submit"
                                    variant={awaitingCode ? 'secondary' : 'primary'}
                                    loading={enrol.processing}
                                >
                                    {awaitingCode
                                        ? tc('console.send_a_new_code_9200fc2f')
                                        : tc('console.text_me_a_code_54b10aea')}
                                </Button>
                                {!awaitingCode && (
                                    <Button
                                        type="button"
                                        disabled={enrol.processing}
                                        onClick={() => setAdding(false)}
                                    >
                                        {tc('console.cancel_19766ed6')}
                                    </Button>
                                )}
                            </div>
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
                                <p className="acct-hint">
                                    {smsEnrolmentSentTo !== undefined ? (
                                        <>
                                            {tc('console.we_sent_a_code_to_a2377ce0')}{' '}
                                            <span className="mono">{smsEnrolmentSentTo}</span>.
                                        </>
                                    ) : (
                                        <>
                                            {tc('console.a_code_was_sent_to_522746c2')}{' '}
                                            <span className="mono">{sms.maskedNumber}</span>.
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
                                <Button
                                    type="submit"
                                    variant="primary"
                                    loading={confirm.processing}
                                >
                                    {tc('console.confirm_phone_number_3afac393')}
                                </Button>
                            </form>
                        )}
                    </div>
                </li>
            )}

            <Dialog
                open={removing}
                onOpenChange={setRemoving}
                title={tc('console.remove_your_phone_number_06f081f0')}
                description={tc('console.you_will_no_longer_be_able_to_sign_in_with_a_a575bedf')}
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
        </ul>
    );
}

/* ─── This session ───────────────────────────────────────────────────────── */

function SessionPanel({
    session,
    otherSessions,
    urls,
}: {
    session: Props['session'];
    otherSessions: number;
    urls: Props['urls'];
}) {
    const { t: tc, choice, locale } = useTranslator();

    const [signingOut, setSigningOut] = useState(false);

    return (
        <Panel
            title={tc('console.current_session_a32789d2')}
            description={tc('console.the_session_you_are_signed_in_with_right_now_258bbe06')}
            flush
        >
            <ul className="acct-list">
                {session === null ? (
                    <li className="acct-row">
                        <p className="acct-hint">
                            {tc('console.no_active_session_details_are_available_869756b3')}
                        </p>
                    </li>
                ) : (
                    <SettingRow
                        icon="device"
                        title={
                            session.signedIn === null
                                ? tc('console.signed_in_ca566c89')
                                : tc('console.account_signed_in_when', {
                                      when: relativeTime(session.signedIn, locale),
                                  })
                        }
                        badge={<Pill tone="success">{tc('console.this_device_d052579c')}</Pill>}
                        meta={joinMeta([
                            session.signedIn === null
                                ? null
                                : absoluteTime(session.signedIn, locale),
                            session.methods.length === 0
                                ? null
                                : tc('console.account_signed_in_with', {
                                      methods: session.methods.join(', '),
                                  }),
                        ])}
                    />
                )}
                <SettingRow
                    icon="layers"
                    title={
                        otherSessions > 0
                            ? choice('console.account_other_sessions', otherSessions, {
                                  count: otherSessions,
                              })
                            : tc('console.account_no_other_sessions')
                    }
                    actions={
                        <Link href={urls.activity} className="btn btn-ghost btn-sm">
                            {tc('console.account_see_all_sessions')}
                        </Link>
                    }
                />
            </ul>

            <div className="acct-footer">
                <form method="POST" action={urls.logout}>
                    <Button type="submit" size="sm" variant="danger" icon="logout">
                        {tc('console.sign_out_b4ea0920')}
                    </Button>
                </form>

                {otherSessions > 0 && (
                    <Button size="sm" onClick={() => setSigningOut(true)}>
                        {tc('console.sign_out_other_sessions_action_count', {
                            count: otherSessions,
                        })}
                    </Button>
                )}
            </div>

            {session !== null && (
                // For support: what identifies this session, out of the way of everyone else.
                <details className="acct-details">
                    <summary>
                        {tc('console.account_technical_details')}
                        <Icon name="chevron" className="w-3.5 h-3.5" />
                    </summary>
                    <div className="acct-details-body">
                        <span style={{ color: 'var(--muted-foreground)' }}>
                            {tc('console.session_id_cb9ac5c5')}
                        </span>
                        <code className="mono text-xs break-all">{session.id}</code>
                        <CopyButton
                            value={session.id}
                            size="sm"
                            aria-label={tc('console.account_copy_session_id')}
                        />
                    </div>
                </details>
            )}

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

/* ─── Organizations ──────────────────────────────────────────────────────── */

/**
 * The person's organizations, and leaving one. Their own act on their own membership, so it
 * is here, on the page every host serves them: the login host has no console around it and
 * a customer environment's host has no People page.
 *
 * Leaving is offered only where it can succeed. An owner hands the organization over
 * first (one owner, moved by transfer) and a customer's team is administered in its own
 * console; both are said on the row rather than refused after the click.
 */
function OrganizationsPanel({ organizations }: { organizations: OrganizationRow[] }) {
    const { t: tc } = useTranslator();
    const [leaving, setLeaving] = useState<OrganizationRow | null>(null);
    const leave = useForm({});

    return (
        <Panel
            title={tc('console.account_your_organizations')}
            description={tc('console.account_organizations_description')}
            data-test="account-organizations"
            flush
        >
            <ul className="acct-list">
                {organizations.map((organization) => (
                    <SettingRow
                        key={organization.id}
                        mark={
                            <span className="acct-org-chip">{initialsOf(organization.name)}</span>
                        }
                        title={organization.name}
                        badge={
                            <>
                                <Pill dot={false}>{organization.role}</Pill>
                                {organization.current && (
                                    <Pill tone="info" dot={false}>
                                        {tc('console.current_e0d1b682')}
                                    </Pill>
                                )}
                            </>
                        }
                        meta={
                            organization.leaveBlocked === 'owner'
                                ? tc('console.account_owner_cannot_leave')
                                : organization.leaveBlocked === 'managed'
                                  ? tc(
                                        'console.this_organization_has_its_own_cbox_id_console_its_team__94b76d94',
                                    )
                                  : undefined
                        }
                        actions={
                            organization.leaveBlocked === null ? (
                                <RowMenu name={organization.name}>
                                    <DropdownMenuItem
                                        destructive
                                        onSelect={() => {
                                            leave.clearErrors();
                                            setLeaving(organization);
                                        }}
                                    >
                                        {tc('console.leave_organization_ac8fd777')}
                                    </DropdownMenuItem>
                                </RowMenu>
                            ) : undefined
                        }
                    />
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

Security.layout = (page: React.ReactNode) => <AccountLayout>{page}</AccountLayout>;
