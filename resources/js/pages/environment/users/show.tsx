import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    EmptyState,
    Field,
    Icon,
    Input,
    Panel,
    Pill,
    type PillTone,
    Select,
} from '@/ui';

interface AccessRole {
    id: string;
    name: string;
    /** The app it is scoped to, or null when it applies across all of them. */
    app: string | null;
}

interface MembershipRow {
    organizationId: string;
    organizationName: string;
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

type Props = PageProps<{
    user: {
        id: string;
        name: string | null;
        email: string;
        status: string;
        verified: boolean;
        hasMfa: boolean;
        requiresPasswordChange: boolean;
    };
    memberships: MembershipRow[];
    joinableOrganizations: { value: string; label: string }[];
    joiningOrganization: string;
    joiningAccessRoles: AccessRole[];
    everywhereRoles: AccessRole[];
    heldEverywhere: string[];
    sessions: SessionRow[];
    assignableRoles: { value: string; label: string }[];
    indexHref: string;
    urls: {
        update: string;
        password: string;
        passwordReset: string;
        resendVerification: string;
        markVerified: string;
        resetMfa: string;
        deactivate: string;
        reactivate: string;
        revokeAllSessions: string;
        assignOrganization: string;
        environmentRole: string;
        impersonate: string;
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
    everywhereRoles,
    heldEverywhere,
    sessions,
    assignableRoles,
    indexHref,
    urls,
}: Props) {
    const label = user.name ?? user.email;

    return (
        <div className="space-y-6">
            <div>
                <Link
                    href={indexHref}
                    className="text-sm inline-flex items-center gap-1"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    <Icon
                        name="chevron"
                        className="w-3.5 h-3.5"
                        style={{ transform: 'rotate(90deg)' }}
                    />
                    Users
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{label}</h1>
                    {!user.verified && <Pill tone="warning">Unverified</Pill>}
                    <Pill tone={statusTone(user.status)}>{user.status}</Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {user.id}
                </p>
            </div>

            <Profile user={user} href={urls.update} />

            <Security user={user} urls={urls} />

            <Sessions
                sessions={sessions}
                email={user.email}
                revokeAllHref={urls.revokeAllSessions}
            />

            <Organizations
                user={user}
                memberships={memberships}
                joinableOrganizations={joinableOrganizations}
                joiningOrganization={joiningOrganization}
                joiningAccessRoles={joiningAccessRoles}
                everywhereRoles={everywhereRoles}
                heldEverywhere={heldEverywhere}
                assignableRoles={assignableRoles}
                urls={urls}
            />

            <Impersonation memberships={memberships} href={urls.impersonate} />
        </div>
    );
}

function Profile({ user, href }: { user: Props['user']; href: string }) {
    const form = useForm({
        name: user.name ?? '',
        email: user.email,
    });

    return (
        <Panel title="Profile">
            <form
                className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] items-start"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, { preserveScroll: true });
                }}
            >
                <Field label="Name" optional error={form.errors.name}>
                    <Input
                        name="name"
                        placeholder="Full name"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>

                <Field
                    label="Email"
                    hint="Also their recovery channel — changing it clears the verification."
                    error={form.errors.email}
                >
                    <Input
                        name="email"
                        type="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                    />
                </Field>

                <Button
                    type="submit"
                    variant="primary"
                    loading={form.processing}
                    className="shrink-0 self-end"
                >
                    Save
                </Button>
            </form>
        </Panel>
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
    // Shown once: only a hash is stored, and props are written into the browser's history
    // entry, so the plaintext rides the flash channel and nowhere else.
    const issued = usePage().flash.issuedPassword;

    const [dismissed, setDismissed] = useState(false);
    const [setting, setSetting] = useState(false);
    const [resettingMfa, setResettingMfa] = useState(false);
    const [deactivating, setDeactivating] = useState(false);

    return (
        <Panel title="Security & lifecycle">
            <div className="space-y-4">
                {user.requiresPasswordChange && (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        This user is held at a password change — they cannot reach anything until
                        they replace the one you issued.
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
                                    Copy this password now — it won't be shown again.
                                </p>
                                <p className="mt-3 select-all break-all mono text-sm">{issued}</p>
                                <p className="mt-3 text-xs" style={{ color: 'var(--faint)' }}>
                                    Hand it to {user.email} over a channel you trust. We never store
                                    it in readable form.
                                </p>
                            </div>
                            <div className="flex items-center gap-2 shrink-0">
                                <CopyButton value={issued} variant="primary" />
                                <Button size="sm" onClick={() => setDismissed(true)}>
                                    Dismiss
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
                    <SendMail href={urls.passwordReset} label="Send password reset" />

                    {!setting && (
                        <Button size="sm" onClick={() => setSetting(true)}>
                            Set password…
                        </Button>
                    )}

                    {!user.verified && (
                        <>
                            <SendMail href={urls.resendVerification} label="Resend verification" />
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(urls.markVerified, {}, { preserveScroll: true })
                                }
                            >
                                Mark verified
                            </Button>
                        </>
                    )}

                    {user.hasMfa && (
                        <Button size="sm" onClick={() => setResettingMfa(true)}>
                            Reset 2FA
                        </Button>
                    )}

                    {user.status === 'active' ? (
                        <Button size="sm" variant="danger" onClick={() => setDeactivating(true)}>
                            Deactivate
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            onClick={() =>
                                router.post(urls.reactivate, {}, { preserveScroll: true })
                            }
                        >
                            Reactivate
                        </Button>
                    )}
                </div>

                <p className="text-xs" style={{ color: 'var(--faint)' }}>
                    Two-factor: {user.hasMfa ? 'enabled' : 'not enrolled'}.
                </p>

                {/*
                    SAYS WHAT THE CONSOLE DOES NOT DO. A delete button used to sit above and
                    reported success without erasing anything; an administrator who believes
                    an erasure happened stops pursuing it, which is the worse failure.
                */}
                <div
                    className="rounded-lg p-3 text-xs"
                    style={{ border: '1px solid var(--border)', color: 'var(--muted-foreground)' }}
                >
                    <p>
                        <b>Deactivation is the only off-switch here — there is no delete.</b>
                    </p>
                    <p className="mt-1.5">
                        Deactivating stops all sign-in but keeps the person's records: sessions,
                        passkeys and second factors, identity-provider profiles, directory data,
                        issued tokens, role assignments and audit history all remain.
                    </p>
                    <p className="mt-1.5">
                        Erasing a person is not implemented in this platform. A right-to-erasure
                        request has to be handled outside the console until it is.
                    </p>
                </div>
            </div>

            <ConfirmDelete
                open={resettingMfa}
                onOpenChange={setResettingMfa}
                name={user.email}
                verb="Reset 2FA for"
                consequence="This destroys the user's enrolled second factor and their recovery codes. Until they enrol again the account is protected by its password alone."
                onConfirm={() => {
                    setResettingMfa(false);
                    router.post(urls.resetMfa, {}, { preserveScroll: true });
                }}
            />

            <ConfirmDelete
                open={deactivating}
                onOpenChange={setDeactivating}
                name={user.email}
                verb="Deactivate"
                consequence="This user can no longer sign in to any application in this environment, and their existing sessions stop working on their next request. Their records are kept and you can reactivate them at any time."
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
            {sending ? 'Sending…' : label}
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
            <Field label="New password" error={form.errors.password}>
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
                        Generate
                    </Button>
                </div>
            </Field>

            <div className="grid sm:grid-cols-2 gap-4">
                <Field label="Type" error={form.errors.mode}>
                    <Select
                        value={form.data.mode}
                        onValueChange={(mode) => form.setData('mode', mode)}
                        options={[
                            {
                                value: 'temporary',
                                label: 'Temporary — they must change it at next sign-in',
                            },
                            {
                                value: 'permanent',
                                label: 'Permanent — stands until they change it',
                            },
                        ]}
                    />
                </Field>

                {form.data.mode === 'temporary' && (
                    <Field label="Valid for" error={form.errors.expiryHours}>
                        <Select
                            value={String(form.data.expiryHours)}
                            onValueChange={(hours) => form.setData('expiryHours', Number(hours))}
                            options={[
                                { value: '1', label: '1 hour' },
                                { value: '24', label: '24 hours' },
                                { value: '72', label: '3 days' },
                                { value: '0', label: 'Until they change it' },
                            ]}
                        />
                    </Field>
                )}
            </div>

            <div className="grid sm:grid-cols-2 gap-4">
                <Field label="How they get it" error={form.errors.delivery}>
                    <Select
                        value={form.data.delivery}
                        onValueChange={(delivery) => form.setData('delivery', delivery)}
                        options={[
                            { value: 'reveal', label: "Show me once — I'll pass it on" },
                            { value: 'email', label: `Email it to ${email}` },
                        ]}
                    />
                </Field>

                <Field label="Existing access" error={form.errors.revoke}>
                    <Select
                        value={form.data.revoke}
                        onValueChange={(revoke) => form.setData('revoke', revoke)}
                        options={[
                            {
                                value: 'sessions_and_tokens',
                                label: 'Sign out everywhere and revoke API tokens',
                            },
                            {
                                value: 'sessions_only',
                                label: 'Sign out everywhere, keep API tokens',
                            },
                            { value: 'nothing', label: 'Leave existing sessions alone' },
                        ]}
                    />
                </Field>
            </div>

            <Field
                label="Reason"
                hint="Recorded on the audit trail alongside your name."
                error={form.errors.reason}
            >
                <Input
                    name="reason"
                    maxLength={200}
                    placeholder="e.g. Locked out after losing their phone"
                    value={form.data.reason}
                    onChange={(event) => form.setData('reason', event.target.value)}
                />
            </Field>

            <div className="flex items-center gap-2">
                <Button type="submit" size="sm" variant="primary" loading={form.processing}>
                    Set password
                </Button>
                <Button type="button" size="sm" onClick={onCancel}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function Sessions({
    sessions,
    email,
    revokeAllHref,
}: {
    sessions: SessionRow[];
    email: string;
    revokeAllHref: string;
}) {
    const [revokingAll, setRevokingAll] = useState(false);

    return (
        <Panel
            title="Active sessions"
            action={
                sessions.length > 0 ? (
                    <Button size="sm" variant="danger" onClick={() => setRevokingAll(true)}>
                        Revoke all
                    </Button>
                ) : undefined
            }
        >
            <div className="space-y-2">
                {sessions.length === 0 ? (
                    <EmptyState
                        icon="shield"
                        title="No active sessions"
                        description="This user has no signed-in sessions right now. They appear here once the user signs in."
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
                                    {session.device ?? 'Unknown device'}
                                </p>
                                <p className="text-xs truncate" style={{ color: 'var(--faint)' }}>
                                    {session.ip ?? '—'} · {session.lastActive ?? 'never'}
                                    {session.impersonation && (
                                        <>
                                            {' · '}
                                            <span style={{ color: 'var(--accent-strong)' }}>
                                                impersonation
                                            </span>
                                        </>
                                    )}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="danger"
                                className="shrink-0"
                                onClick={() =>
                                    router.delete(session.revokeHref, { preserveScroll: true })
                                }
                            >
                                Revoke
                            </Button>
                        </div>
                    ))
                )}
            </div>

            <ConfirmDelete
                open={revokingAll}
                onOpenChange={setRevokingAll}
                name={email}
                verb="Revoke all sessions for"
                consequence="Every one of this user's sessions is terminated immediately and they are signed out on all devices."
                onConfirm={() => {
                    setRevokingAll(false);
                    router.delete(revokeAllHref, { preserveScroll: true });
                }}
            />
        </Panel>
    );
}

/**
 * WHERE THIS PERSON BELONGS, and what they can do there.
 *
 * Two different questions on every row: the membership role governs who administers the
 * organization, and the access roles are what the person can do inside its apps.
 */
function Organizations({
    user,
    memberships,
    joinableOrganizations,
    joiningOrganization,
    joiningAccessRoles,
    everywhereRoles,
    heldEverywhere,
    assignableRoles,
    urls,
}: {
    user: Props['user'];
    memberships: MembershipRow[];
    joinableOrganizations: { value: string; label: string }[];
    joiningOrganization: string;
    joiningAccessRoles: AccessRole[];
    everywhereRoles: AccessRole[];
    heldEverywhere: string[];
    assignableRoles: { value: string; label: string }[];
    urls: Props['urls'];
}) {
    const [managing, setManaging] = useState<string | null>(null);
    const [removing, setRemoving] = useState<MembershipRow | null>(null);

    return (
        <Panel
            title="Organizations"
            description={
                <>
                    <b>Org access</b> is the user's administration level; <b>access roles</b> are
                    what they can do inside that org's apps.
                </>
            }
        >
            <div className="space-y-4">
                <div className="space-y-2">
                    {memberships.length === 0 ? (
                        <EmptyState
                            icon="layers"
                            title="Not a member of any organization"
                            description="Add them to one below to grant access inside it — or, if your apps have no tenancy of their own, give them a role that applies everywhere in this environment."
                        />
                    ) : (
                        memberships.map((membership) => (
                            <div
                                key={membership.organizationId}
                                className="rounded-lg border px-3 py-2"
                                style={{ borderColor: 'var(--border)' }}
                            >
                                <div className="flex items-center gap-2 flex-wrap">
                                    <Link
                                        href={membership.href}
                                        className="min-w-0 flex-1 truncate text-sm font-medium"
                                        style={{ color: 'var(--accent-strong)' }}
                                    >
                                        {membership.organizationName}
                                    </Link>

                                    <Select
                                        aria-label={`Org access in ${membership.organizationName}`}
                                        value={membership.role}
                                        onValueChange={(role) =>
                                            router.patch(
                                                membership.urls.role,
                                                { role },
                                                { preserveScroll: true },
                                            )
                                        }
                                        options={assignableRoles}
                                    />

                                    <Button
                                        size="sm"
                                        variant="danger"
                                        className="shrink-0"
                                        onClick={() => setRemoving(membership)}
                                    >
                                        Remove
                                    </Button>
                                </div>

                                <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                        Access roles:
                                    </span>

                                    {membership.accessRoleIds.length === 0 ? (
                                        <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                            None
                                        </span>
                                    ) : (
                                        membership.accessRoles
                                            .filter((role) =>
                                                membership.accessRoleIds.includes(role.id),
                                            )
                                            .map((role) => <Badge key={role.id}>{role.name}</Badge>)
                                    )}

                                    {membership.accessRoles.length > 0 && (
                                        <Button
                                            size="sm"
                                            aria-expanded={managing === membership.organizationId}
                                            onClick={() =>
                                                setManaging((current) =>
                                                    current === membership.organizationId
                                                        ? null
                                                        : membership.organizationId,
                                                )
                                            }
                                        >
                                            {managing === membership.organizationId
                                                ? 'Done'
                                                : 'Manage'}
                                        </Button>
                                    )}
                                </div>

                                {managing === membership.organizationId && (
                                    <div
                                        className="mt-3 rounded-lg p-3 grid gap-2 sm:grid-cols-2"
                                        style={{
                                            background:
                                                'color-mix(in oklch, var(--secondary) 55%, transparent)',
                                        }}
                                    >
                                        {membership.accessRoles.map((role) => (
                                            <Checkbox
                                                key={role.id}
                                                checked={membership.accessRoleIds.includes(role.id)}
                                                onCheckedChange={(granted) =>
                                                    router.post(
                                                        membership.urls.accessRole,
                                                        { role: role.id, granted },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                label={role.name}
                                                hint={role.app ?? 'All apps'}
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>
                        ))
                    )}
                </div>

                {/*
                    GRANTS THAT NAME NO ORGANIZATION. Every grant above is scoped to one
                    tenant, which cannot describe a support agent acting across all of
                    them, somebody who has joined none, or an app with no tenancy of its own
                    to hang a grant on. Those people used to get a token with no roles and
                    no permissions, and there was no way to give them any.
                */}
                {everywhereRoles.length > 0 && (
                    <div className="rounded-lg border p-3" style={{ borderColor: 'var(--border)' }}>
                        <p className="text-sm font-medium">Roles everywhere in this environment</p>
                        <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            Applied in <b>every</b> organization, and to this person even when they
                            belong to none. Only roles you defined for the whole environment can be
                            granted this way — one organization's own role is their policy, not
                            everyone's.
                        </p>
                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                            {everywhereRoles.map((role) => (
                                <Checkbox
                                    key={role.id}
                                    checked={heldEverywhere.includes(role.id)}
                                    onCheckedChange={(granted) =>
                                        router.post(
                                            urls.environmentRole,
                                            { role: role.id, granted },
                                            { preserveScroll: true },
                                        )
                                    }
                                    label={role.name}
                                    hint={role.app ?? 'All apps'}
                                />
                            ))}
                        </div>
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
                verb="Remove membership for"
                consequence="They lose every role this organization grants them, immediately."
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
    assignableRoles: { value: string; label: string }[];
    href: string;
}) {
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
            <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto] items-start">
                <Field label="Add to organization" error={form.errors.organization}>
                    <Select
                        value={form.data.organization === '' ? undefined : form.data.organization}
                        onValueChange={choose}
                        placeholder="Choose an organization…"
                        options={joinable}
                    />
                </Field>

                <Field label="Org access" error={form.errors.role}>
                    <Select
                        value={form.data.role}
                        onValueChange={(role) => form.setData('role', role)}
                        options={assignableRoles}
                    />
                </Field>

                <Button
                    type="submit"
                    variant="primary"
                    loading={form.processing}
                    className="shrink-0 self-end"
                >
                    Add
                </Button>
            </div>

            {form.data.organization !== '' && accessRoles.length > 0 && (
                <div>
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        Access roles — granted immediately (optional)
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
                                hint={role.app ?? 'All apps'}
                            />
                        ))}
                    </div>
                </div>
            )}
        </form>
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
    const [confirming, setConfirming] = useState(false);

    const impersonatable = memberships.filter((membership) => !membership.managesOrganization);
    const allowed = memberships.length === 0 || impersonatable.length > 0;

    const form = useForm({
        organization: impersonatable[0]?.organizationId ?? '',
        reason: '',
    });

    if (!allowed) {
        return (
            <Panel title="Support impersonation">
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    This user owns or administers an organization, and those cannot be impersonated
                    — stepping into one would hand durable control of a tenant to whoever did it.
                </p>
            </Panel>
        );
    }

    return (
        <Panel
            title="Support impersonation"
            description="Time-boxed to 30 minutes and recorded on the audit trail."
        >
            <form
                className="grid gap-2 sm:grid-cols-[1fr_1fr_auto] items-start"
                onSubmit={(event) => {
                    event.preventDefault();
                    setConfirming(true);
                }}
            >
                {impersonatable.length > 0 ? (
                    <Field label="Organization" error={form.errors.organization}>
                        <Select
                            value={form.data.organization}
                            onValueChange={(organization) =>
                                form.setData('organization', organization)
                            }
                            options={impersonatable.map((membership) => ({
                                value: membership.organizationId,
                                label: membership.organizationName,
                            }))}
                        />
                    </Field>
                ) : (
                    <p className="text-sm self-center" style={{ color: 'var(--muted-foreground)' }}>
                        Signed in as themselves, in no organization.
                    </p>
                )}

                <Field label="Reason" error={form.errors.reason}>
                    <Input
                        name="reason"
                        maxLength={200}
                        required
                        placeholder="Why you need to step in"
                        value={form.data.reason}
                        onChange={(event) => form.setData('reason', event.target.value)}
                    />
                </Field>

                <Button type="submit" className="shrink-0 self-end" loading={form.processing}>
                    Impersonate
                </Button>
            </form>

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={
                    impersonatable.find(
                        (membership) => membership.organizationId === form.data.organization,
                    )?.organizationName ?? 'impersonate'
                }
                verb="Impersonate in"
                consequence="You act as this person, in their session, with their access. It is time-boxed to 30 minutes, read-only where the console can enforce it, and every request is recorded against your name."
                onConfirm={() => {
                    setConfirming(false);
                    form.post(href);
                }}
            />
        </Panel>
    );
}

UserDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
