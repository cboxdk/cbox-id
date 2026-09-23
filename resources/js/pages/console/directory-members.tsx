import { Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Avatar,
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    Dialog,
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    EmptyState,
    Icon,
    type InviteAccessRole,
    InviteForm,
    PageHeader,
    Pagination,
    Panel,
    type PendingInvitation,
    PendingInvitations,
    Pill,
    type ReturnApp,
    type RoleOption,
    roleSelectOptions,
    Select,
    Table,
    Td,
    Th,
} from '@/ui';

interface AccessRole {
    id: string;
    name: string;
    key: string;
    /** "Custom roles", or the app that declared it. */
    group: string;
    /** What holding it actually lets a member do. */
    permissions: string[];
}

interface Member {
    id: string;
    name: string | null;
    email: string | null;
    role: string;
    accessRoleIds: string[];
    joined: string | null;
    isMe: boolean;
    urls: { role: string; access: string; remove: string; transfer: string };
}

type Props = PageProps<{
    isAdmin: boolean;
    members: Member[];
    pagination: PaginationState;
    invitations: PendingInvitation[];
    accessRoles: AccessRole[];
    /** What an invitation may offer — never Owner. */
    roleOptions: RoleOption[];
    /** The same list plus Owner, disabled, so an owner's row can say what it holds. */
    rosterRoleOptions: RoleOption[];
    apps: ReturnApp[];
    isOwner: boolean;
    managedElsewhere: boolean;
    rolesHref: string;
    inviteHref: string;
    leaveHref: string;
    organizationName: string;
    help: HelpContent;
}>;

/** The roles, in the groups the picker reads in: org-wide first, then app by app. */
function grouped(roles: AccessRole[]): [string, AccessRole[]][] {
    const groups = new Map<string, AccessRole[]>();

    for (const role of roles) {
        groups.set(role.group, [...(groups.get(role.group) ?? []), role]);
    }

    return [...groups.entries()];
}

/** WHAT THE ROLE ACTUALLY GRANTS, under its own name. */
function permissionHint(role: AccessRole): string {
    return role.permissions.length === 0
        ? 'No permissions'
        : role.permissions.slice(0, 4).join(' · ') +
              (role.permissions.length > 4 ? ` +${role.permissions.length - 4}` : '');
}

export default function DirectoryMembers({
    isAdmin,
    members,
    pagination,
    invitations,
    accessRoles,
    roleOptions,
    rosterRoleOptions,
    apps,
    isOwner,
    managedElsewhere,
    rolesHref,
    inviteHref,
    leaveHref,
    organizationName,
    help,
}: Props) {
    const [inviting, setInviting] = useState(false);

    // A refused roster write — the last owner, a role that is not offered — used to be
    // posted into an error bag nothing on this page read, so the button simply did nothing.
    const { errors } = usePage<Props>().props;
    const refusal = errors.member ?? errors.role ?? null;

    const inviteRoles: InviteAccessRole[] = accessRoles.map((role) => ({
        id: role.id,
        name: role.name,
        group: role.group,
        hint: role.key,
    }));

    return (
        <>
            <PageHeader
                help={help}
                description="Everyone who can sign in to this organization, and the invitations nobody has accepted yet."
                actions={
                    isAdmin &&
                    !managedElsewhere && (
                        <Button variant="primary" onClick={() => setInviting((open) => !open)}>
                            <Icon name="plus" className="w-4 h-4" />
                            Invite member
                        </Button>
                    )
                }
            />

            <div className="mt-8 space-y-6">
                {/*
                    SAID ON THE PAGE, not only enforced on the write. This organization owns
                    products, which makes it a customer of this platform — and a customer's
                    roster is administered from the management console by somebody holding an
                    organization capability. An admin told this up front is not left clicking
                    controls that refuse.
                */}
                {managedElsewhere && (
                    <div
                        className="card p-4"
                        style={{
                            background: 'var(--accent-soft)',
                            borderColor: 'var(--accent-edge)',
                        }}
                    >
                        <p className="text-sm">
                            <b>This organization is a Cbox workspace.</b> Its team is administered
                            under Workspace › Team, by somebody holding a workspace role, rather
                            than from here.
                        </p>
                    </div>
                )}

                {refusal !== null && (
                    <div
                        role="alert"
                        className="card p-4 text-sm"
                        style={{
                            background: 'var(--destructive-soft)',
                            borderColor: 'var(--destructive)',
                        }}
                    >
                        {refusal}
                    </div>
                )}

                {inviting && isAdmin && !managedElsewhere && (
                    <Panel
                        title="Invite someone"
                        description="They get an email with a link. Nothing changes until they accept."
                    >
                        <InviteForm
                            href={inviteHref}
                            roles={roleOptions}
                            accessRoles={inviteRoles}
                            apps={apps}
                            onDone={() => setInviting(false)}
                            onCancel={() => setInviting(false)}
                        />
                    </Panel>
                )}

                {isAdmin && <PendingInvitations invitations={invitations} />}

                <Roster
                    isAdmin={isAdmin}
                    members={members}
                    pagination={pagination}
                    accessRoles={accessRoles}
                    roles={rosterRoleOptions}
                    isOwner={isOwner}
                    managedElsewhere={managedElsewhere}
                    rolesHref={rolesHref}
                    leaveHref={leaveHref}
                    organizationName={organizationName}
                />
            </div>
        </>
    );
}

/** Which confirmation is open, and about whom. */
type Pending = { kind: 'remove' | 'transfer' | 'leave'; member: Member } | null;

function Roster({
    isAdmin,
    members,
    pagination,
    accessRoles,
    roles,
    isOwner,
    managedElsewhere,
    rolesHref,
    leaveHref,
    organizationName,
}: {
    isAdmin: boolean;
    members: Member[];
    pagination: PaginationState;
    accessRoles: AccessRole[];
    roles: RoleOption[];
    isOwner: boolean;
    managedElsewhere: boolean;
    rolesHref: string;
    leaveHref: string;
    organizationName: string;
}) {
    const [managing, setManaging] = useState<string | null>(null);
    const [pending, setPending] = useState<Pending>(null);

    const byId = useMemo(
        () => new Map(accessRoles.map((role) => [role.id, role] as const)),
        [accessRoles],
    );

    if (members.length === 0) {
        return (
            <EmptyState
                icon="members"
                title="No members yet"
                description="Everyone who can sign in to this organization appears here. Invite the first one to get started."
            />
        );
    }

    const label = (member: Member): string => member.email ?? member.name ?? 'this member';

    return (
        <div className="card overflow-hidden">
            <div className="overflow-x-auto">
                <Table caption="Everyone in this organization and their roles: one built-in role each, and any app or custom roles">
                    <thead>
                        <tr>
                            <Th>Member</Th>
                            <Th>Roles</Th>
                            <Th>Joined</Th>
                            <Th>
                                <span className="sr-only">Actions</span>
                            </Th>
                        </tr>
                    </thead>
                    <tbody>
                        {members.map((member) => (
                            <RosterRow
                                key={member.id}
                                member={member}
                                isAdmin={isAdmin}
                                isOwner={isOwner}
                                managedElsewhere={managedElsewhere}
                                roles={roles}
                                accessRoles={accessRoles}
                                byId={byId}
                                rolesHref={rolesHref}
                                managing={managing === member.id}
                                onToggleManage={() =>
                                    setManaging((current) =>
                                        current === member.id ? null : member.id,
                                    )
                                }
                                onAsk={(kind) => setPending({ kind, member })}
                            />
                        ))}
                    </tbody>
                </Table>
            </div>

            <div className="p-4">
                <Pagination
                    pagination={pagination}
                    noun="member"
                    href={(page) =>
                        page > 1
                            ? `${window.location.pathname}?page=${page}`
                            : window.location.pathname
                    }
                />
            </div>

            <ConfirmDelete
                open={pending?.kind === 'remove'}
                onOpenChange={(open) => !open && setPending(null)}
                name={pending === null ? '' : label(pending.member)}
                verb="Remove"
                consequence="They lose every role and application this organization grants them, immediately."
                onConfirm={() => {
                    const target = pending;
                    setPending(null);

                    if (target !== null) {
                        router.delete(target.member.urls.remove, { preserveScroll: true });
                    }
                }}
            />

            {/*
                TYPE-TO-CONFIRM, because this is the one change here that cannot be undone
                from this side: once they own it, only they can hand it back.
            */}
            <ConfirmDelete
                open={pending?.kind === 'transfer'}
                onOpenChange={(open) => !open && setPending(null)}
                name={pending === null ? '' : label(pending.member)}
                verb="Transfer ownership to"
                actionLabel="Transfer ownership"
                consequence={`They become the owner of ${organizationName} and you become an admin. Only the new owner can hand it back.`}
                onConfirm={() => {
                    const target = pending;
                    setPending(null);

                    if (target !== null) {
                        router.post(target.member.urls.transfer, {}, { preserveScroll: true });
                    }
                }}
            />

            {/* A plain confirm: leaving is your own decision about your own access. */}
            <Dialog
                open={pending?.kind === 'leave'}
                onOpenChange={(open) => !open && setPending(null)}
                title={`Leave ${organizationName}?`}
                description={
                    isOwner
                        ? 'You lose access immediately. As its owner, you can only leave once someone else owns it — transfer ownership first, or delete the organization under Settings.'
                        : 'You lose access to this organization and its apps immediately. An admin can invite you back.'
                }
                footer={
                    <>
                        <Button onClick={() => setPending(null)}>Cancel</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                setPending(null);
                                router.post(leaveHref);
                            }}
                        >
                            Leave organization
                        </Button>
                    </>
                }
            />
        </div>
    );
}

function RosterRow({
    member,
    isAdmin,
    isOwner,
    managedElsewhere,
    roles,
    accessRoles,
    byId,
    rolesHref,
    managing,
    onToggleManage,
    onAsk,
}: {
    member: Member;
    isAdmin: boolean;
    isOwner: boolean;
    managedElsewhere: boolean;
    roles: RoleOption[];
    accessRoles: AccessRole[];
    byId: Map<string, AccessRole>;
    rolesHref: string;
    managing: boolean;
    onToggleManage: () => void;
    onAsk: (kind: 'remove' | 'transfer' | 'leave') => void;
}) {
    const label = member.name ?? member.email ?? 'this member';
    const held = member.accessRoleIds;
    const rowIsOwner = member.role === 'owner';

    // Ownership moves by transfer, so an owner's row is never a picker for the owner
    // themselves, and never for an admin (who may not act on an owner at all).
    const roleEditable = isAdmin && !managedElsewhere && !(rowIsOwner && (member.isMe || !isOwner));

    const mayRemove = isAdmin && !managedElsewhere && !member.isMe && (!rowIsOwner || isOwner);
    const mayTransfer = isOwner && !managedElsewhere && !member.isMe && !rowIsOwner;

    const builtIn = roles.find((role) => role.value === member.role)?.label ?? member.role;
    const editable = isAdmin && !managedElsewhere && (roleEditable || accessRoles.length > 0);

    return (
        <>
            <tr>
                <Td>
                    <div className="flex items-center gap-3">
                        <Avatar name={label} />
                        <div className="min-w-0">
                            <p className="font-medium truncate">
                                {member.name ?? '—'}
                                {member.isMe && (
                                    <Badge className="ml-2" tone="info">
                                        You
                                    </Badge>
                                )}
                            </p>
                            <p
                                className="text-xs truncate"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {member.email ?? member.id}
                            </p>
                        </div>
                    </div>
                </Td>

                {/*
                    ONE ROLES COLUMN. It was two — "Console access" and "Roles in your apps" —
                    for what a person reads as one question: what may they do here. The
                    built-in role comes first because there is always exactly one; the
                    roles after it are any number, and the app decides what each allows.
                */}
                <Td>
                    <div className="flex flex-wrap items-center gap-1">
                        <Pill>
                            <span className="sr-only">Built-in role: </span>
                            {builtIn}
                        </Pill>

                        {held.map((id) => {
                            const role = byId.get(id);

                            return role === undefined ? null : <Badge key={id}>{role.name}</Badge>;
                        })}

                        {editable && (
                            <Button size="sm" aria-expanded={managing} onClick={onToggleManage}>
                                {managing ? 'Done' : 'Edit roles'}
                            </Button>
                        )}
                    </div>
                </Td>

                <Td className="text-sm mono" style={{ color: 'var(--muted-foreground)' }}>
                    {member.joined ?? '—'}
                </Td>

                <Td className="text-right">
                    {member.isMe && !managedElsewhere ? (
                        <Button size="sm" onClick={() => onAsk('leave')}>
                            Leave
                        </Button>
                    ) : (
                        (mayRemove || mayTransfer) && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button size="sm" aria-label={`More actions for ${label}`}>
                                        ⋯
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent>
                                    {mayTransfer && (
                                        <DropdownMenuItem onSelect={() => onAsk('transfer')}>
                                            Transfer ownership
                                        </DropdownMenuItem>
                                    )}
                                    {mayRemove && (
                                        <DropdownMenuItem
                                            destructive
                                            onSelect={() => onAsk('remove')}
                                        >
                                            Remove from organization
                                        </DropdownMenuItem>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )
                    )}
                </Td>
            </tr>

            {managing && editable && (
                <tr>
                    <td
                        colSpan={4}
                        style={{
                            background: 'color-mix(in oklch, var(--secondary) 55%, transparent)',
                            padding: '14px 20px',
                        }}
                    >
                        <p className="text-sm font-medium mb-3">Roles for {label}</p>

                        <div className="grid gap-4 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
                            <div>
                                <p className="label mb-1">Built-in role</p>
                                <p
                                    className="text-xs mb-2"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    Exactly one. It decides what they may administer here.
                                </p>
                                {roleEditable ? (
                                    <Select
                                        aria-label={`Built-in role for ${label}`}
                                        value={member.role}
                                        onValueChange={(role) =>
                                            router.patch(
                                                member.urls.role,
                                                { role },
                                                { preserveScroll: true },
                                            )
                                        }
                                        options={roleSelectOptions(roles)}
                                    />
                                ) : (
                                    <p className="text-sm">
                                        <Pill>{builtIn}</Pill>{' '}
                                        <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                            {rowIsOwner ? 'Ownership moves by transfer.' : ''}
                                        </span>
                                    </p>
                                )}
                            </div>

                            <div>
                                <p className="label mb-1">App and custom roles</p>
                                <p
                                    className="text-xs mb-2"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    Any number. They ride in the app tokens, and each app decides
                                    what its roles allow.
                                </p>

                                {accessRoles.length === 0 ? (
                                    <Link
                                        href={rolesHref}
                                        className="text-xs"
                                        style={{ color: 'var(--accent-strong)' }}
                                    >
                                        No roles defined yet →
                                    </Link>
                                ) : (
                                    grouped(accessRoles).map(([group, inGroup]) => (
                                        <div key={group}>
                                            <p
                                                className="text-xs font-semibold uppercase mb-1.5 mt-1"
                                                style={{
                                                    color: 'var(--muted-foreground)',
                                                    letterSpacing: '0.05em',
                                                }}
                                            >
                                                {group}
                                            </p>
                                            <div className="grid gap-1.5 sm:grid-cols-2 mb-3">
                                                {inGroup.map((role) => (
                                                    <Checkbox
                                                        key={role.id}
                                                        checked={held.includes(role.id)}
                                                        onCheckedChange={(granted) =>
                                                            router.post(
                                                                member.urls.access,
                                                                { role: role.id, granted },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                        label={role.name}
                                                        hint={permissionHint(role)}
                                                    />
                                                ))}
                                            </div>
                                        </div>
                                    ))
                                )}
                            </div>
                        </div>
                    </td>
                </tr>
            )}
        </>
    );
}

DirectoryMembers.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
