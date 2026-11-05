import { useTranslator } from '@/i18n';
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
    invitationsHref,
}: Props) {
    return (
        <Members
            members={members}
            pagination={pagination}
            accessRoles={accessRoles}
            roleOptions={roleOptions}
            rosterRoleOptions={rosterRoleOptions}
            addHref={addMemberHref}
            invitationsHref={invitationsHref}
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
    invitationsHref,
}: {
    members: Member[];
    pagination: PaginationState;
    accessRoles: AccessRole[];
    roleOptions: RoleOption[];
    rosterRoleOptions: RoleOption[];
    addHref: string;
    invitationsHref: string;
}) {
    const { t: tc } = useTranslator();

    const [managing, setManaging] = useState<string | null>(null);
    const [removing, setRemoving] = useState<Member | null>(null);
    const [transferring, setTransferring] = useState<Member | null>(null);

    // Refusals from a roster write (the last owner, a role that is not offered) land in the
    // shared error bag; said here rather than lost.
    const { errors } = usePage<Props>().props;
    const refusal = errors.member ?? errors.role ?? null;

    return (
        <Panel
            title={tc('console.members_1044a4c0')}
            description={tc('console.who_belongs_to_this_organization_and_what_th_330bf97e')}
            // The way to bring in somebody NEW. "Add" below only takes people who already
            // have an account in this environment, and an administrator who came here to
            // invite a colleague found no invite on the page they expected it on.
            action={
                <Button asChild size="sm" icon="mail">
                    <Link href={invitationsHref}>{tc('console.invite_by_email_f728553b')}</Link>
                </Button>
            }
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
                        title={tc('console.nobody_yet_aca29464')}
                        description={tc(
                            'console.add_an_existing_user_of_this_environment_or__0eb3e414',
                        )}
                        actions={
                            <Button asChild variant="primary">
                                <Link href={invitationsHref}>
                                    {tc('console.invite_by_email_f728553b')}
                                </Link>
                            </Button>
                        }
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
                                            <span className="sr-only">
                                                {tc('console.built_in_role_ad05edaa')}
                                            </span>
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
                                        {managing === member.userId
                                            ? tc('console.done_11a6767d')
                                            : tc('console.edit_roles_3b3489d0')}
                                    </Button>

                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                size="sm"
                                                className="shrink-0"
                                                aria-label={tc(
                                                    'console.more_actions_for_value0_fab1edb9',
                                                    { value0: member.name },
                                                )}
                                            >
                                                ⋯
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent>
                                            {member.role !== 'owner' && (
                                                <DropdownMenuItem
                                                    onSelect={() => setTransferring(member)}
                                                >
                                                    {tc('console.make_owner_fdbbb37b')}
                                                </DropdownMenuItem>
                                            )}
                                            <DropdownMenuItem
                                                destructive
                                                onSelect={() => setRemoving(member)}
                                            >
                                                {tc('console.remove_from_organization_2416fd1f')}
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
                                                <p className="label mb-1">
                                                    {tc('console.built_in_role_9f680a16')}
                                                </p>
                                                <p
                                                    className="text-xs mb-2"
                                                    style={{ color: 'var(--muted-foreground)' }}
                                                >
                                                    {tc(
                                                        'console.exactly_one_it_decides_what_they_may_adminis_45ec2b16',
                                                    )}
                                                </p>
                                                <Select
                                                    aria-label={tc(
                                                        'console.built_in_role_for_value0_1ecbad27',
                                                        { value0: member.name },
                                                    )}
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
                                                <p className="label mb-1">
                                                    {tc('console.app_and_custom_roles_ecb0207e')}
                                                </p>
                                                <p
                                                    className="text-xs mb-2"
                                                    style={{ color: 'var(--muted-foreground)' }}
                                                >
                                                    {tc(
                                                        'console.any_number_they_ride_in_the_app_tokens_23cc6b17',
                                                    )}
                                                </p>
                                                {accessRoles.length === 0 ? (
                                                    <p
                                                        className="text-sm"
                                                        style={{ color: 'var(--muted-foreground)' }}
                                                    >
                                                        {tc(
                                                            'console.no_roles_are_defined_for_this_organization_y_a7f006b2',
                                                        )}
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
                    noun={tc('console.member_e31ab643')}
                    pluralNoun={tc('console.members_17373ca1')}
                    href={() => window.location.pathname}
                />
            </div>

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.name ?? ''}
                verb="Remove"
                consequence={tc('console.they_lose_access_to_this_organization_and_ev_984eedd8')}
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
                verb={tc('console.make_owner_350d86c6')}
                actionLabel={tc('console.make_owner_fdbbb37b')}
                consequence={tc('console.they_become_this_organization_s_only_owner_w_11374c6d')}
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
    const { t: tc } = useTranslator();

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
            <p className="text-sm font-medium">{tc('console.add_an_existing_user_e7d56d1f')}</p>

            <div className="flex flex-wrap items-end gap-2">
                <Field
                    label={tc('console.email_969ccbd3')}
                    hint={tc('console.they_must_already_exist_in_this_environment_67854676')}
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

                <Field label={tc('console.built_in_role_9f680a16')} error={form.errors.role}>
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
                    {tc('console.add_9fd728c6')}
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
    const { t: tc } = useTranslator();

    if (roles.length === 0) {
        return null;
    }

    return (
        <fieldset>
            <legend className="label">{tc('console.app_and_custom_roles_ecb0207e')}</legend>
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
