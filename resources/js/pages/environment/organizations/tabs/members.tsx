import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, Pagination as PaginationState } from '@/types';
import {
    AccessRoleHint,
    type AccessRoleOption,
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
    EmptyState,
    Field,
    Input,
    Pagination,
    Panel,
    Pill,
    type RoleOption,
    roleSelectOptions,
    Select,
} from '@/ui';

type AccessRole = AccessRoleOption;

interface Member {
    userId: string;
    name: string;
    email: string | null;
    role: string;
    accessRoleIds: string[];
    /** The person's own page under Users. */
    href: string;
    urls: { role: string; accessRole: string; remove: string; transfer: string };
}

type Props = PageProps<{
    members: Member[];
    pagination: PaginationState;
    accessRoles: AccessRole[];
    /** What an added member may be given — never Owner. */
    roleOptions: RoleOption[];
    /** The same plus Owner, disabled, so an owner's row names what it holds. */
    rosterRoleOptions: RoleOption[];
    addMemberHref: string;
    invitationsHref: string;
}>;

/**
 * AN ORGANIZATION › MEMBERS — who belongs to it, and what they can do. The organization's
 * header and tabs are drawn around it by the layout (`./frame.tsx`).
 */
export default function OrganizationMembers({
    members,
    pagination,
    accessRoles,
    roleOptions,
    rosterRoleOptions,
    addMemberHref,
}: Props) {
    return (
        <Members
            members={members}
            pagination={pagination}
            accessRoles={accessRoles}
            roleOptions={roleOptions}
            rosterRoleOptions={rosterRoleOptions}
            addHref={addMemberHref}
        />
    );
}

/**
 * The roster.
 *
 * TWO DIFFERENT KINDS OF ACCESS on every row, and they answer different questions: the
 * membership role governs who administers the organization, and the access roles are what
 * the person can do inside the apps. Conflating them is how somebody ends up an owner in
 * order to read a report.
 */
function Members({
    members,
    pagination,
    accessRoles,
    roleOptions,
    rosterRoleOptions,
    addHref,
}: {
    members: Member[];
    pagination: PaginationState;
    accessRoles: AccessRole[];
    roleOptions: RoleOption[];
    rosterRoleOptions: RoleOption[];
    addHref: string;
}) {
    const [managing, setManaging] = useState<string | null>(null);
    const [removing, setRemoving] = useState<Member | null>(null);
    const [transferring, setTransferring] = useState<Member | null>(null);

    // Refusals from a roster write (the last owner, a role that is not offered) land in the
    // shared error bag; said here rather than lost.
    const { errors } = usePage<Props>().props;
    const refusal = errors.member ?? errors.role ?? null;

    return (
        <Panel
            title="Members"
            description="Who belongs to this organization, and what they can do."
        >
            <div className="space-y-4">
                {refusal !== null && (
                    <p
                        role="alert"
                        className="rounded-lg p-3 text-sm"
                        style={{
                            background: 'var(--destructive-soft)',
                            border: '1px solid var(--destructive)',
                        }}
                    >
                        {refusal}
                    </p>
                )}

                <AddMember accessRoles={accessRoles} roleOptions={roleOptions} href={addHref} />

                {members.length === 0 ? (
                    <EmptyState
                        icon="members"
                        title="Nobody yet"
                        description="Add an existing user of this environment, or invite somebody by email."
                    />
                ) : (
                    <div
                        className="rounded-xl border overflow-hidden"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        {members.map((member, index) => (
                            <div
                                key={member.userId}
                                style={
                                    index === members.length - 1
                                        ? undefined
                                        : { borderBottom: '1px solid var(--border)' }
                                }
                            >
                                <div className="flex items-center gap-3 flex-wrap p-4">
                                    <div className="min-w-0 flex-1">
                                        <Link
                                            href={member.href}
                                            className="font-medium truncate block"
                                        >
                                            {member.name}
                                        </Link>
                                        {member.email !== null && (
                                            <p
                                                className="text-xs truncate"
                                                style={{ color: 'var(--faint)' }}
                                            >
                                                {member.email}
                                            </p>
                                        )}
                                    </div>

                                    {/*
                                        ONE ROLES CONTROL: the built-in role (exactly one)
                                        and the app and custom roles (any number) are read
                                        together, and edited together below.
                                    */}
                                    <div className="flex flex-wrap items-center gap-1">
                                        <Pill>
                                            <span className="sr-only">Built-in role: </span>
                                            {rosterRoleOptions.find(
                                                (option) => option.value === member.role,
                                            )?.label ?? member.role}
                                        </Pill>
                                        {accessRoles
                                            .filter((role) =>
                                                member.accessRoleIds.includes(role.id),
                                            )
                                            .map((role) => (
                                                <Badge key={role.id}>{role.name}</Badge>
                                            ))}
                                    </div>

                                    <Button
                                        size="sm"
                                        className="shrink-0"
                                        aria-expanded={managing === member.userId}
                                        onClick={() =>
                                            setManaging((current) =>
                                                current === member.userId ? null : member.userId,
                                            )
                                        }
                                    >
                                        {managing === member.userId ? 'Done' : 'Edit roles'}
                                    </Button>

                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                size="sm"
                                                className="shrink-0"
                                                aria-label={`More actions for ${member.name}`}
                                            >
                                                ⋯
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent>
                                            {member.role !== 'owner' && (
                                                <DropdownMenuItem
                                                    onSelect={() => setTransferring(member)}
                                                >
                                                    Make owner
                                                </DropdownMenuItem>
                                            )}
                                            <DropdownMenuItem
                                                destructive
                                                onSelect={() => setRemoving(member)}
                                            >
                                                Remove from organization
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>

                                {managing === member.userId && (
                                    <div
                                        className="px-4 pb-4"
                                        style={{ background: 'var(--surface-2)' }}
                                    >
                                        <div className="pt-3 grid gap-4 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]">
                                            <div>
                                                <p className="label mb-1">Built-in role</p>
                                                <p
                                                    className="text-xs mb-2"
                                                    style={{ color: 'var(--muted-foreground)' }}
                                                >
                                                    Exactly one. It decides what they may administer
                                                    in this organization.
                                                </p>
                                                <Select
                                                    aria-label={`Built-in role for ${member.name}`}
                                                    value={member.role}
                                                    onValueChange={(role) =>
                                                        router.patch(
                                                            member.urls.role,
                                                            { role },
                                                            { preserveScroll: true },
                                                        )
                                                    }
                                                    options={roleSelectOptions(rosterRoleOptions)}
                                                />
                                            </div>

                                            <div>
                                                <p className="label mb-1">App and custom roles</p>
                                                <p
                                                    className="text-xs mb-2"
                                                    style={{ color: 'var(--muted-foreground)' }}
                                                >
                                                    Any number. They ride in the app tokens.
                                                </p>
                                                {accessRoles.length === 0 ? (
                                                    <p
                                                        className="text-sm"
                                                        style={{ color: 'var(--muted-foreground)' }}
                                                    >
                                                        No roles are defined for this organization
                                                        yet.
                                                    </p>
                                                ) : (
                                                    <div className="grid gap-2 sm:grid-cols-2">
                                                        {accessRoles.map((role) => (
                                                            <Checkbox
                                                                key={role.id}
                                                                checked={member.accessRoleIds.includes(
                                                                    role.id,
                                                                )}
                                                                onCheckedChange={(granted) =>
                                                                    router.post(
                                                                        member.urls.accessRole,
                                                                        { role: role.id, granted },
                                                                        { preserveScroll: true },
                                                                    )
                                                                }
                                                                label={role.name}
                                                                hint={
                                                                    <AccessRoleHint role={role} />
                                                                }
                                                            />
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}

                <Pagination
                    pagination={pagination}
                    noun="member"
                    href={() => window.location.pathname}
                />
            </div>

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.name ?? ''}
                verb="Remove"
                consequence="They lose access to this organization and everything it grants. Their account itself is untouched."
                onConfirm={() => {
                    const member = removing;
                    setRemoving(null);

                    if (member !== null) {
                        router.delete(member.urls.remove, { preserveScroll: true });
                    }
                }}
            />

            {/*
                An organization has ONE owner, and it moves by transfer: whoever owns it now
                steps down to admin. It is also how an organization created here — which
                starts with nobody owning it — gets its first owner.
            */}
            <ConfirmDelete
                open={transferring !== null}
                onOpenChange={(open) => !open && setTransferring(null)}
                name={transferring?.email ?? transferring?.name ?? ''}
                verb="Make owner:"
                actionLabel="Make owner"
                consequence="They become this organization's only owner. Whoever owns it now becomes an admin."
                onConfirm={() => {
                    const member = transferring;
                    setTransferring(null);

                    if (member !== null) {
                        router.post(member.urls.transfer, {}, { preserveScroll: true });
                    }
                }}
            />
        </Panel>
    );
}

function AddMember({
    accessRoles,
    roleOptions,
    href,
}: {
    accessRoles: AccessRole[];
    roleOptions: RoleOption[];
    href: string;
}) {
    const form = useForm({
        email: '',
        role: 'member',
        accessRoles: [] as string[],
    });

    return (
        <form
            className="rounded-xl border p-4 space-y-3"
            style={{ borderColor: 'var(--border)' }}
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <p className="text-sm font-medium">Add an existing user</p>

            <div className="flex flex-wrap items-end gap-2">
                <Field
                    label="Email"
                    hint="They must already exist in this environment."
                    className="flex-1"
                    error={form.errors.email}
                >
                    <Input
                        name="email"
                        type="email"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                    />
                </Field>

                <Field label="Built-in role" error={form.errors.role}>
                    <Select
                        name="role"
                        value={form.data.role}
                        onValueChange={(role) => form.setData('role', role)}
                        options={roleSelectOptions(roleOptions)}
                    />
                </Field>

                <Button
                    type="submit"
                    variant="primary"
                    className="shrink-0"
                    loading={form.processing}
                >
                    Add
                </Button>
            </div>

            <AccessRolePicker
                roles={accessRoles}
                selected={form.data.accessRoles}
                onChange={(next) => form.setData('accessRoles', next)}
            />
        </form>
    );
}

/** The app and custom roles to grant alongside a membership. */
function AccessRolePicker({
    roles,
    selected,
    onChange,
    hint,
}: {
    roles: AccessRole[];
    selected: string[];
    onChange: (roles: string[]) => void;
    hint?: string;
}) {
    if (roles.length === 0) {
        return null;
    }

    return (
        <fieldset>
            <legend className="label">App and custom roles</legend>
            {hint !== undefined && (
                <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {hint}
                </p>
            )}
            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                {roles.map((role) => (
                    <Checkbox
                        key={role.id}
                        checked={selected.includes(role.id)}
                        onCheckedChange={(checked) =>
                            onChange(
                                checked
                                    ? [...selected, role.id]
                                    : selected.filter((id) => id !== role.id),
                            )
                        }
                        label={role.name}
                        hint={<AccessRoleHint role={role} />}
                    />
                ))}
            </div>
        </fieldset>
    );
}

OrganizationMembers.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
