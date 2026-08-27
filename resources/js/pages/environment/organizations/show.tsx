import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, Pagination as PaginationState } from '@/types';
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
    type MetadataRow,
    MetadataRows,
    Pagination,
    Panel,
    Pill,
    Select,
} from '@/ui';

interface AccessRole {
    id: string;
    name: string;
    /** The app it is scoped to, or null when it applies across all of them. */
    app: string | null;
}

interface Member {
    userId: string;
    name: string;
    email: string | null;
    role: string;
    accessRoleIds: string[];
    urls: { role: string; accessRole: string; remove: string };
}

interface Invitation {
    id: string;
    email: string;
    role: string;
    revokeHref: string;
}

interface Domain {
    id: string;
    domain: string;
    verified: boolean;
    capture: boolean;
    /** The DNS TXT value to publish — the one thing somebody copies into another tab. */
    token: string;
    urls: { verify: string; capture: string; remove: string };
}

type Props = PageProps<{
    organization: {
        id: string;
        name: string;
        slug: string;
        status: string;
        metadata: MetadataRow[];
    };
    members: Member[];
    pagination: PaginationState;
    invitations: Invitation[];
    domains: Domain[];
    accessRoles: AccessRole[];
    assignableRoles: { value: string; label: string }[];
    indexHref: string;
    urls: {
        update: string;
        suspend: string;
        reactivate: string;
        destroy: string;
        addMember: string;
        invite: string;
        addDomain: string;
    };
}>;

export default function OrganizationDetail({
    organization,
    members,
    pagination,
    invitations,
    domains,
    accessRoles,
    assignableRoles,
    indexHref,
    urls,
}: Props) {
    const [deleting, setDeleting] = useState(false);

    const suspended = organization.status === 'suspended';

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
                    Organizations
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{organization.name}</h1>
                    <Pill tone={suspended ? 'warning' : 'success'}>{organization.status}</Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {organization.id}
                </p>
            </div>

            <Details organization={organization} href={urls.update} />

            <Members
                members={members}
                pagination={pagination}
                accessRoles={accessRoles}
                assignableRoles={assignableRoles}
                addHref={urls.addMember}
            />

            <Invitations
                invitations={invitations}
                accessRoles={accessRoles}
                assignableRoles={assignableRoles}
                inviteHref={urls.invite}
            />

            <Domains domains={domains} addHref={urls.addDomain} />

            <Panel
                title={suspended ? 'Reactivate organization' : 'Suspend organization'}
                description={
                    suspended
                        ? 'Its people can sign in again, with the access they had.'
                        : 'Its people are refused at every door — sign-in, the device flow, the consent screen — until it is reactivated. Nothing is deleted.'
                }
            >
                <Button
                    size="sm"
                    onClick={() =>
                        router.post(
                            suspended ? urls.reactivate : urls.suspend,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    {suspended ? 'Reactivate' : 'Suspend'}
                </Button>
            </Panel>

            <Panel
                title="Delete organization"
                description="It disappears from every list and its people are refused everywhere, exactly as a suspension does. The records stay."
            >
                <Button size="sm" variant="danger" onClick={() => setDeleting(true)}>
                    Delete organization
                </Button>
            </Panel>

            <ConfirmDelete
                open={deleting}
                onOpenChange={setDeleting}
                name={organization.name}
                consequence="Everyone in this organization is refused at every door immediately, and it disappears from every list. This cannot be undone from the console."
                onConfirm={() => {
                    setDeleting(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

function Details({ organization, href }: { organization: Props['organization']; href: string }) {
    const form = useForm({
        name: organization.name,
        slug: organization.slug,
        metadata: organization.metadata,
    });

    return (
        <Panel title="Details">
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, { preserveScroll: true });
                }}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <Field label="Name" error={form.errors.name}>
                        <Input
                            name="name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>

                    <Field
                        label="URL handle"
                        hint="Other systems may be using this — changing it changes the URLs they hold."
                        error={form.errors.slug}
                    >
                        <Input
                            name="slug"
                            className="mono"
                            value={form.data.slug}
                            onChange={(event) => form.setData('slug', event.target.value)}
                        />
                    </Field>
                </div>

                <MetadataRows
                    rows={form.data.metadata}
                    onChange={(rows) => form.setData('metadata', rows)}
                    hint="Anything your own systems need to keep against this tenant. Rows with no key are dropped."
                />

                <Button type="submit" variant="primary" loading={form.processing}>
                    Save changes
                </Button>
            </form>
        </Panel>
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
    assignableRoles,
    addHref,
}: {
    members: Member[];
    pagination: PaginationState;
    accessRoles: AccessRole[];
    assignableRoles: { value: string; label: string }[];
    addHref: string;
}) {
    const [managing, setManaging] = useState<string | null>(null);
    const [removing, setRemoving] = useState<Member | null>(null);

    return (
        <Panel
            title="Members"
            description="Who belongs to this organization, and what they can do."
        >
            <div className="space-y-4">
                <AddMember
                    accessRoles={accessRoles}
                    assignableRoles={assignableRoles}
                    href={addHref}
                />

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
                                        <p className="font-medium truncate">{member.name}</p>
                                        {member.email !== null && (
                                            <p
                                                className="text-xs truncate"
                                                style={{ color: 'var(--faint)' }}
                                            >
                                                {member.email}
                                            </p>
                                        )}
                                    </div>

                                    <Select
                                        aria-label={`Organization access for ${member.name}`}
                                        value={member.role}
                                        onValueChange={(role) =>
                                            router.patch(
                                                member.urls.role,
                                                { role },
                                                { preserveScroll: true },
                                            )
                                        }
                                        options={assignableRoles.map((role) => ({
                                            value: role.value,
                                            label: role.label,
                                        }))}
                                    />

                                    <Button
                                        size="sm"
                                        aria-expanded={managing === member.userId}
                                        onClick={() =>
                                            setManaging((current) =>
                                                current === member.userId ? null : member.userId,
                                            )
                                        }
                                    >
                                        {member.accessRoleIds.length} app{' '}
                                        {member.accessRoleIds.length === 1 ? 'role' : 'roles'}
                                    </Button>

                                    <Button
                                        size="sm"
                                        variant="danger"
                                        className="shrink-0"
                                        onClick={() => setRemoving(member)}
                                    >
                                        Remove
                                    </Button>
                                </div>

                                {managing === member.userId && (
                                    <div
                                        className="px-4 pb-4"
                                        style={{ background: 'var(--surface-2)' }}
                                    >
                                        {accessRoles.length === 0 ? (
                                            <p
                                                className="pt-3 text-sm"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                No app roles are defined for this organization yet.
                                            </p>
                                        ) : (
                                            <div className="pt-3 grid gap-2 sm:grid-cols-2">
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
                                                        hint={role.app ?? 'All apps'}
                                                    />
                                                ))}
                                            </div>
                                        )}
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
        </Panel>
    );
}

function AddMember({
    accessRoles,
    assignableRoles,
    href,
}: {
    accessRoles: AccessRole[];
    assignableRoles: { value: string; label: string }[];
    href: string;
}) {
    const form = useForm({
        email: '',
        role: assignableRoles[0]?.value ?? 'member',
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

                <Field label="Organization access" error={form.errors.role}>
                    <Select
                        name="role"
                        value={form.data.role}
                        onValueChange={(role) => form.setData('role', role)}
                        options={assignableRoles.map((role) => ({
                            value: role.value,
                            label: role.label,
                        }))}
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

function Invitations({
    invitations,
    accessRoles,
    assignableRoles,
    inviteHref,
}: {
    invitations: Invitation[];
    accessRoles: AccessRole[];
    assignableRoles: { value: string; label: string }[];
    inviteHref: string;
}) {
    const form = useForm({
        email: '',
        role: assignableRoles[0]?.value ?? 'member',
        accessRoles: [] as string[],
    });

    return (
        <Panel
            title="Invitations"
            description="The invitee accepts by email — nobody is added to an organization without saying yes."
        >
            <div className="space-y-4">
                <form
                    className="rounded-xl border p-4 space-y-3"
                    style={{ borderColor: 'var(--border)' }}
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(inviteHref, {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <div className="flex flex-wrap items-end gap-2">
                        <Field label="Email" className="flex-1" error={form.errors.email}>
                            <Input
                                name="email"
                                type="email"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                            />
                        </Field>

                        <Field label="Organization access" error={form.errors.role}>
                            <Select
                                name="role"
                                value={form.data.role}
                                onValueChange={(role) => form.setData('role', role)}
                                options={assignableRoles.map((role) => ({
                                    value: role.value,
                                    label: role.label,
                                }))}
                            />
                        </Field>

                        <Button
                            type="submit"
                            variant="primary"
                            className="shrink-0"
                            loading={form.processing}
                        >
                            Send invitation
                        </Button>
                    </div>

                    <AccessRolePicker
                        roles={accessRoles}
                        selected={form.data.accessRoles}
                        onChange={(next) => form.setData('accessRoles', next)}
                        hint="Applied when they accept, so they arrive already holding them."
                    />
                </form>

                {invitations.length > 0 && (
                    <div
                        className="rounded-xl border overflow-hidden"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        {invitations.map((invitation, index) => (
                            <div
                                key={invitation.id}
                                className="flex items-center gap-3 flex-wrap px-4 py-3"
                                style={
                                    index === invitations.length - 1
                                        ? undefined
                                        : { borderBottom: '1px solid var(--border)' }
                                }
                            >
                                <span className="min-w-0 flex-1 truncate">{invitation.email}</span>
                                <Badge>{invitation.role}</Badge>
                                <Button
                                    size="sm"
                                    variant="danger"
                                    className="shrink-0"
                                    onClick={() =>
                                        router.delete(invitation.revokeHref, {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    Revoke
                                </Button>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </Panel>
    );
}

/** The app roles to grant alongside a membership. */
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
            <legend className="label">App roles</legend>
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
                        hint={role.app ?? 'All apps'}
                    />
                ))}
            </div>
        </fieldset>
    );
}

/**
 * The email domains this organization claims.
 *
 * CAPTURE IS THE CONSEQUENTIAL SWITCH: it routes everyone on the domain to this
 * organization's SSO connection, so it stays off until the domain is proven — otherwise an
 * organization could claim addresses it does not own.
 */
function Domains({ domains, addHref }: { domains: Domain[]; addHref: string }) {
    const form = useForm({ domain: '' });
    const [removing, setRemoving] = useState<Domain | null>(null);

    return (
        <Panel
            title="Email domains"
            description="Prove the organization owns a domain, then route everyone on it to their own sign-in."
        >
            <div className="space-y-4">
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(addHref, {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <Field label="Domain" className="flex-1" error={form.errors.domain}>
                        <Input
                            name="domain"
                            className="mono"
                            placeholder="acme.com"
                            value={form.data.domain}
                            onChange={(event) => form.setData('domain', event.target.value)}
                        />
                    </Field>
                    <Button type="submit" className="shrink-0" loading={form.processing}>
                        Add domain
                    </Button>
                </form>

                {domains.map((domain) => (
                    <div
                        key={domain.id}
                        className="rounded-xl border p-4 space-y-3"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-medium mono">{domain.domain}</span>
                            <Pill tone={domain.verified ? 'success' : 'warning'}>
                                {domain.verified ? 'Verified' : 'Unverified'}
                            </Pill>
                            {domain.capture && <Badge>Capturing sign-ins</Badge>}
                        </div>

                        {!domain.verified && (
                            <div
                                className="rounded-lg p-3 space-y-2"
                                style={{
                                    background: 'var(--surface-2)',
                                    border: '1px solid var(--border)',
                                }}
                            >
                                <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                                    Add this DNS <b>TXT</b> record at{' '}
                                    <span className="mono">{domain.domain}</span>, then verify.
                                </p>
                                {/*
                                    Its own copy button: somebody is about to paste this
                                    into a DNS panel in another tab, and selecting a value
                                    out of a sentence by hand is where a truncated record
                                    comes from.
                                */}
                                <div className="flex items-start gap-2">
                                    <code className="mono text-xs break-all select-all flex-1">
                                        {domain.token}
                                    </code>
                                    <CopyButton value={domain.token} />
                                </div>
                            </div>
                        )}

                        <div className="flex items-center gap-2 flex-wrap">
                            {!domain.verified && (
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        router.post(
                                            domain.urls.verify,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Verify
                                </Button>
                            )}

                            <Button
                                size="sm"
                                disabled={!domain.verified && !domain.capture}
                                onClick={() =>
                                    router.post(domain.urls.capture, {}, { preserveScroll: true })
                                }
                            >
                                {domain.capture ? 'Stop capturing' : 'Capture sign-ins'}
                            </Button>

                            <Button size="sm" variant="danger" onClick={() => setRemoving(domain)}>
                                Remove
                            </Button>
                        </div>

                        {!domain.verified && !domain.capture && (
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                Verify the domain before turning capture on — until then, this
                                organization has not proved it owns those addresses.
                            </p>
                        )}
                    </div>
                ))}
            </div>

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.domain ?? ''}
                verb="Remove"
                consequence="The claim is dropped. If capture was on, people on this domain go back to the ordinary sign-in."
                onConfirm={() => {
                    const domain = removing;
                    setRemoving(null);

                    if (domain !== null) {
                        router.delete(domain.urls.remove, { preserveScroll: true });
                    }
                }}
            />
        </Panel>
    );
}

OrganizationDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
