import { Link, router, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Avatar,
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
    Pagination,
    Panel,
    Pill,
    Select,
    Table,
    Td,
    Th,
} from '@/ui';

interface AccessRole {
    id: string;
    name: string;
    key: string;
    /** "Org roles", or the app that declared it. */
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
    urls: { role: string; access: string; remove: string };
}

interface Invitation {
    id: string;
    email: string;
    role: string;
    expires: string | null;
    revokeHref: string;
}

type Props = PageProps<{
    isAdmin: boolean;
    members: Member[];
    pagination: PaginationState;
    invitations: Invitation[];
    accessRoles: AccessRole[];
    assignableRoles: { value: string; label: string; ownerOnly: boolean }[];
    isOwner: boolean;
    managedElsewhere: boolean;
    rolesHref: string;
    inviteHref: string;
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

export default function DirectoryMembers({
    isAdmin,
    members,
    pagination,
    invitations,
    accessRoles,
    assignableRoles,
    isOwner,
    managedElsewhere,
    rolesHref,
    inviteHref,
    help,
}: Props) {
    const [inviting, setInviting] = useState(false);

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
                            <b>This organization is a customer of this platform.</b> Its members are
                            administered under Identity platform → Members, by somebody holding an
                            organization capability rather than from here.
                        </p>
                    </div>
                )}

                {inviting && isAdmin && !managedElsewhere && (
                    <InviteForm
                        href={inviteHref}
                        accessRoles={accessRoles}
                        assignableRoles={assignableRoles}
                        isOwner={isOwner}
                        onDone={() => setInviting(false)}
                    />
                )}

                {isAdmin && invitations.length > 0 && <Invitations invitations={invitations} />}

                <Roster
                    isAdmin={isAdmin}
                    members={members}
                    pagination={pagination}
                    accessRoles={accessRoles}
                    assignableRoles={assignableRoles}
                    isOwner={isOwner}
                    managedElsewhere={managedElsewhere}
                    rolesHref={rolesHref}
                />
            </div>
        </>
    );
}

function InviteForm({
    href,
    accessRoles,
    assignableRoles,
    isOwner,
    onDone,
}: {
    href: string;
    accessRoles: AccessRole[];
    assignableRoles: Props['assignableRoles'];
    isOwner: boolean;
    onDone: () => void;
}) {
    const form = useForm({
        email: '',
        role: 'member',
        accessRoles: [] as string[],
    });

    // Only an owner may invite somebody straight to owner. Hidden here AND refused on the
    // write — the control not being drawn is not a guard.
    const roles = assignableRoles.filter((role) => isOwner || !role.ownerOnly);

    return (
        <form
            className="card p-4 space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, { preserveScroll: true, onSuccess: () => onDone() });
            }}
        >
            <div className="flex flex-wrap items-end gap-3">
                <div className="flex-1" style={{ minWidth: '14rem' }}>
                    <Field label="Email address" error={form.errors.email}>
                        <Input
                            name="email"
                            type="email"
                            placeholder="teammate@company.com"
                            value={form.data.email}
                            onChange={(event) => form.setData('email', event.target.value)}
                        />
                    </Field>
                </div>
                <Field label="Console access" error={form.errors.role}>
                    <Select
                        value={form.data.role}
                        onValueChange={(role) => form.setData('role', role)}
                        options={roles.map((role) => ({ value: role.value, label: role.label }))}
                    />
                </Field>
            </div>

            {accessRoles.length > 0 && (
                <Field
                    label="Access roles"
                    hint="Granted the moment they accept — optional."
                    error={form.errors.accessRoles}
                >
                    <div className="space-y-2">
                        {grouped(accessRoles).map(([group, inGroup]) => (
                            <div key={group}>
                                <p
                                    className="text-xs font-semibold uppercase mb-1.5"
                                    style={{
                                        color: 'var(--muted-foreground)',
                                        letterSpacing: '0.05em',
                                    }}
                                >
                                    {group}
                                </p>
                                <div className="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                                    {inGroup.map((role) => (
                                        <Checkbox
                                            key={role.id}
                                            checked={form.data.accessRoles.includes(role.id)}
                                            onCheckedChange={(checked) =>
                                                form.setData(
                                                    'accessRoles',
                                                    checked
                                                        ? [...form.data.accessRoles, role.id]
                                                        : form.data.accessRoles.filter(
                                                              (id) => id !== role.id,
                                                          ),
                                                )
                                            }
                                            label={role.name}
                                            hint={role.key}
                                        />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </Field>
            )}

            <div className="flex items-center gap-2">
                <Button type="submit" variant="primary" loading={form.processing}>
                    Send invite
                </Button>
                <Button type="button" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function Invitations({ invitations }: { invitations: Invitation[] }) {
    const [revoking, setRevoking] = useState<Invitation | null>(null);

    return (
        <Panel title="Pending invitations">
            <ul>
                {invitations.map((invitation, index) => (
                    <li
                        key={invitation.id}
                        className="py-3 flex items-center justify-between gap-4"
                        style={
                            index < invitations.length - 1
                                ? { borderBottom: '1px solid var(--border)' }
                                : undefined
                        }
                    >
                        <div className="min-w-0">
                            <p className="text-sm font-medium truncate">{invitation.email}</p>
                            <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                                Invited as {invitation.role}
                                {invitation.expires !== null && ` · expires ${invitation.expires}`}
                            </p>
                        </div>
                        <div className="flex items-center gap-2">
                            <Pill tone="warning">Pending</Pill>
                            <Button
                                size="sm"
                                variant="danger"
                                onClick={() => setRevoking(invitation)}
                            >
                                Revoke
                            </Button>
                        </div>
                    </li>
                ))}
            </ul>

            <ConfirmDelete
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                name={revoking?.email ?? ''}
                verb="Revoke the invitation for"
                consequence="The emailed link stops working, and any access roles it was carrying are withdrawn with it. You can invite them again at any time."
                onConfirm={() => {
                    const invitation = revoking;
                    setRevoking(null);

                    if (invitation !== null) {
                        router.delete(invitation.revokeHref, { preserveScroll: true });
                    }
                }}
            />
        </Panel>
    );
}

function Roster({
    isAdmin,
    members,
    pagination,
    accessRoles,
    assignableRoles,
    isOwner,
    managedElsewhere,
    rolesHref,
}: {
    isAdmin: boolean;
    members: Member[];
    pagination: PaginationState;
    accessRoles: AccessRole[];
    assignableRoles: Props['assignableRoles'];
    isOwner: boolean;
    managedElsewhere: boolean;
    rolesHref: string;
}) {
    const [managing, setManaging] = useState<string | null>(null);
    const [removing, setRemoving] = useState<Member | null>(null);

    const byId = useMemo(
        () => new Map(accessRoles.map((role) => [role.id, role] as const)),
        [accessRoles],
    );

    const roles = assignableRoles.filter((role) => isOwner || !role.ownerOnly);

    if (members.length === 0) {
        return (
            <EmptyState
                icon="members"
                title="No members yet"
                description="Everyone who can sign in to this organization appears here. Invite the first one to get started."
            />
        );
    }

    return (
        <div className="card overflow-hidden">
            <div className="overflow-x-auto">
                <Table caption="Everyone in this organization, what they may administer, and what their roles let them do in your apps">
                    <thead>
                        <tr>
                            <Th>Member</Th>
                            <Th>Console access</Th>
                            <Th>Roles in your apps</Th>
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
                                onRemove={() => setRemoving(member)}
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
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.email ?? removing?.name ?? ''}
                verb="Remove"
                consequence="They lose every role and application this organization grants them, immediately."
                onConfirm={() => {
                    const member = removing;
                    setRemoving(null);

                    if (member !== null) {
                        router.delete(member.urls.remove, { preserveScroll: true });
                    }
                }}
            />
        </div>
    );
}

function RosterRow({
    member,
    isAdmin,
    managedElsewhere,
    roles,
    accessRoles,
    byId,
    rolesHref,
    managing,
    onToggleManage,
    onRemove,
}: {
    member: Member;
    isAdmin: boolean;
    managedElsewhere: boolean;
    roles: Props['assignableRoles'];
    accessRoles: AccessRole[];
    byId: Map<string, AccessRole>;
    rolesHref: string;
    managing: boolean;
    onToggleManage: () => void;
    onRemove: () => void;
}) {
    const label = member.name ?? member.email ?? 'this member';
    const held = member.accessRoleIds;

    return (
        <>
            <tr>
                <Td>
                    <div className="flex items-center gap-3">
                        <Avatar name={label} />
                        <div className="min-w-0">
                            <p className="font-medium truncate">{member.name ?? '—'}</p>
                            <p
                                className="text-xs truncate"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {member.email ?? member.id}
                            </p>
                        </div>
                    </div>
                </Td>

                <Td>
                    {isAdmin && !managedElsewhere ? (
                        <Select
                            aria-label={`Console access for ${label}`}
                            value={member.role}
                            onValueChange={(role) =>
                                router.patch(member.urls.role, { role }, { preserveScroll: true })
                            }
                            options={roles.map((role) => ({
                                value: role.value,
                                label: role.label,
                            }))}
                        />
                    ) : (
                        <Pill>
                            {roles.find((role) => role.value === member.role)?.label ?? member.role}
                        </Pill>
                    )}
                </Td>

                <Td>
                    <div className="flex flex-wrap items-center gap-1">
                        {held.length === 0 ? (
                            isAdmin && accessRoles.length === 0 ? (
                                <Link
                                    href={rolesHref}
                                    className="text-xs"
                                    style={{ color: 'var(--accent-strong)' }}
                                >
                                    No roles defined yet →
                                </Link>
                            ) : (
                                <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                    None
                                </span>
                            )
                        ) : (
                            held.map((id) => {
                                const role = byId.get(id);

                                return role === undefined ? null : (
                                    <Badge key={id}>{role.name}</Badge>
                                );
                            })
                        )}

                        {isAdmin && accessRoles.length > 0 && (
                            <Button size="sm" aria-expanded={managing} onClick={onToggleManage}>
                                {managing ? 'Done' : 'Manage'}
                            </Button>
                        )}
                    </div>
                </Td>

                <Td className="text-sm mono" style={{ color: 'var(--muted-foreground)' }}>
                    {member.joined ?? '—'}
                </Td>

                <Td className="text-right">
                    {isAdmin && !member.isMe && !managedElsewhere && (
                        <Button size="sm" variant="danger" onClick={onRemove}>
                            Remove
                        </Button>
                    )}
                </Td>
            </tr>

            {managing && isAdmin && (
                <tr>
                    <td
                        colSpan={5}
                        style={{
                            background: 'color-mix(in oklch, var(--secondary) 55%, transparent)',
                            padding: '14px 20px',
                        }}
                    >
                        <p className="text-xs mb-3" style={{ color: 'var(--muted-foreground)' }}>
                            Access roles for <b>{label}</b> — these ride in the app tokens; the app
                            enforces what each one can do.
                        </p>

                        {grouped(accessRoles).map(([group, inGroup]) => (
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
                                <div className="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3 mb-3">
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
                                            /*
                                                WHAT THE ROLE ACTUALLY GRANTS, under its own
                                                name. A checkbox with a word and no
                                                consequence attached is how somebody grants
                                                more than they meant to.
                                            */
                                            hint={
                                                role.permissions.length === 0
                                                    ? 'No permissions'
                                                    : role.permissions.slice(0, 4).join(' · ') +
                                                      (role.permissions.length > 4
                                                          ? ` +${role.permissions.length - 4}`
                                                          : '')
                                            }
                                        />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </td>
                </tr>
            )}
        </>
    );
}

DirectoryMembers.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
