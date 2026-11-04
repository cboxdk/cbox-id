import type { Translator } from '@/i18n';
import { useTranslator } from '@/i18n';
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
    rolesHref: string | null;
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
function permissionHint(role: AccessRole, tc: Translator['t']): string {
    return role.permissions.length === 0
        ? tc('console.no_permissions_ef5bb514')
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
    const { t: tc } = useTranslator();

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
                description={tc('console.everyone_who_can_sign_in_to_this_organizatio_731c2bfe')}
                actions={
                    isAdmin &&
                    !managedElsewhere && (
                        <Button variant="primary" onClick={() => setInviting((open) => !open)}>
                            <Icon name="plus" className="w-4 h-4" />
                            {tc('console.invite_member_08aa7a0b')}
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
                            <b>{tc('console.this_organization_is_a_cbox_workspace_a78218a1')}</b>
                            {tc('console.its_team_is_administered_under_workspace_tea_108220bc')}
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
                        title={tc('console.invite_someone_9b7639e8')}
                        description={tc(
                            'console.they_get_an_email_with_a_link_nothing_change_e94c64b9',
                        )}
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
    rolesHref: string | null;
    leaveHref: string;
    organizationName: string;
}) {
    const { t: tc } = useTranslator();

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
                title={tc('console.no_members_yet_669a52e9')}
                description={tc('console.everyone_who_can_sign_in_to_this_organizatio_e47007d3')}
            />
        );
    }

    const label = (member: Member): string =>
        member.email ?? member.name ?? tc('console.this_member_a802a70b');

    return (
        <div className="card overflow-hidden">
            <div className="overflow-x-auto">
                <Table
                    caption={tc('console.everyone_in_this_organization_and_their_role_35d8fefb')}
                >
                    <thead>
                        <tr>
                            <Th>{tc('console.member_7c968fb7')}</Th>
                            <Th>{tc('console.roles_c2533705')}</Th>
                            <Th>{tc('console.joined_69318b0c')}</Th>
                            <Th>
                                <span className="sr-only">{tc('console.actions_ff8059dc')}</span>
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
                    noun={tc('console.member_e31ab643')}
                    pluralNoun={tc('console.members_17373ca1')}
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
                consequence={tc('console.they_lose_every_role_and_application_this_or_8c05da9e')}
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
                verb={tc('console.transfer_ownership_to_db1c7dbf')}
                actionLabel={tc('console.transfer_ownership_3667f939')}
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
                title={tc('console.leave_value0_81419493', { value0: organizationName })}
                description={
                    isOwner
                        ? tc('console.you_lose_access_immediately_as_its_owner_you_d51a2272')
                        : tc('console.you_lose_access_to_this_organization_and_its_47fdba25')
                }
                footer={
                    <>
                        <Button onClick={() => setPending(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                setPending(null);
                                router.post(leaveHref);
                            }}
                        >
                            {tc('console.leave_organization_ac8fd777')}
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
    rolesHref: string | null;
    managing: boolean;
    onToggleManage: () => void;
    onAsk: (kind: 'remove' | 'transfer' | 'leave') => void;
}) {
    const { t: tc } = useTranslator();

    const label = member.name ?? member.email ?? tc('console.this_member_a802a70b');
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
                                        {tc('console.you_08b04193')}
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
                            <span className="sr-only">{tc('console.built_in_role_ad05edaa')}</span>
                            {builtIn}
                        </Pill>

                        {held.map((id) => {
                            const role = byId.get(id);

                            return role === undefined ? null : <Badge key={id}>{role.name}</Badge>;
                        })}

                        {editable && (
                            <Button size="sm" aria-expanded={managing} onClick={onToggleManage}>
                                {managing
                                    ? tc('console.done_11a6767d')
                                    : tc('console.edit_roles_3b3489d0')}
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
                            {tc('console.leave_fc6e4a40')}
                        </Button>
                    ) : (
                        (mayRemove || mayTransfer) && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        size="sm"
                                        aria-label={tc('console.more_actions_for_value0_fab1edb9', {
                                            value0: label,
                                        })}
                                    >
                                        ⋯
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent>
                                    {mayTransfer && (
                                        <DropdownMenuItem onSelect={() => onAsk('transfer')}>
                                            {tc('console.transfer_ownership_3667f939')}
                                        </DropdownMenuItem>
                                    )}
                                    {mayRemove && (
                                        <DropdownMenuItem
                                            destructive
                                            onSelect={() => onAsk('remove')}
                                        >
                                            {tc('console.remove_from_organization_2416fd1f')}
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
                        <p className="text-sm font-medium mb-3">
                            {tc('console.roles_for_df0ef794')}
                            {label}
                        </p>

                        <div className="grid gap-4 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
                            <div>
                                <p className="label mb-1">{tc('console.built_in_role_9f680a16')}</p>
                                <p
                                    className="text-xs mb-2"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {tc(
                                        'console.exactly_one_it_decides_what_they_may_adminis_ebe76d54',
                                    )}
                                </p>
                                {roleEditable ? (
                                    <Select
                                        aria-label={tc(
                                            'console.built_in_role_for_value0_1ecbad27',
                                            { value0: label },
                                        )}
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
                                            {rowIsOwner
                                                ? tc('console.ownership_moves_by_transfer_ad7a21ea')
                                                : ''}
                                        </span>
                                    </p>
                                )}
                            </div>

                            <div>
                                <p className="label mb-1">
                                    {tc('console.app_and_custom_roles_ecb0207e')}
                                </p>
                                <p
                                    className="text-xs mb-2"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {tc(
                                        'console.any_number_they_ride_in_the_app_tokens_and_e_19a76c8d',
                                    )}
                                </p>

                                {accessRoles.length === 0 ? (
                                    rolesHref !== null ? (
                                        <Link
                                            href={rolesHref}
                                            className="text-xs"
                                            style={{ color: 'var(--accent-strong)' }}
                                        >
                                            {tc('console.no_roles_defined_yet_8e7688cb')}
                                        </Link>
                                    ) : (
                                        <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                            {tc('console.no_roles_defined_yet_99df1348')}
                                        </p>
                                    )
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
                                                        hint={permissionHint(role, tc)}
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
