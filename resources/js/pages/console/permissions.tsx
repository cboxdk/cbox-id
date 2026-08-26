import { Link, router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    EmptyState,
    Field,
    Input,
    PageHeader,
    Panel,
} from '@/ui';

interface PermissionRow {
    id: string;
    name: string;
    description: string | null;
    /** The app that declares it — null for one authored here. */
    app: string | null;
    tenantAssignable: boolean;
    /** The app stopped declaring it. Kept rather than deleted, so roles keep resolving. */
    orphaned: boolean;
    /** How many of the roles in view grant it. */
    roleCount: number;
    urls: { update: string; destroy: string };
}

interface DeclaringApp {
    app: string;
    permissions: PermissionRow[];
}

type Props = PageProps<{
    help: HelpContent;
    mine: PermissionRow[];
    inherited: PermissionRow[];
    declared: DeclaringApp[];
    declaredTotal: number;
    declaredShown: number;
    /** True on the environment plane: what this page writes is shared with every tenant. */
    sharesEnvironment: boolean;
    search: string;
    clientsHref: string;
    urls: { store: string };
}>;

function listHref(search: string): string {
    return search === ''
        ? window.location.pathname
        : `${window.location.pathname}?q=${encodeURIComponent(search)}`;
}

export default function Permissions({
    help,
    mine,
    inherited,
    declared,
    declaredTotal,
    declaredShown,
    sharesEnvironment,
    search,
    clientsHref,
    urls,
}: Props) {
    const [term, setTerm] = useState(search);

    useEffect(() => {
        if (term === search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                listHref(term),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, search]);

    const matching = mine.length + inherited.length + declaredTotal;

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description="Everything a role can be allowed to do. Write your own below — no code needed — or let your apps register theirs automatically."
            />

            {/*
                The other half of the sentence, said where the confusion happens. A
                permission and a scope are different things sharing one word, and the page
                each lives on is the only place a reader can be told which they are looking
                at.
            */}
            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                A permission is what a <b>person</b> may do, once signed in. What an <b>app</b> may
                ask for is a scope, set when you register it under{' '}
                <Link href={clientsHref} className="underline">
                    Apps &amp; API keys
                </Link>
                .
            </p>

            <NewPermission sharesEnvironment={sharesEnvironment} storeHref={urls.store} />

            <div>
                <Input
                    type="search"
                    className="w-full"
                    aria-label="Search permissions by key or description"
                    placeholder="Search permissions by key or description"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the lists below are replaced on a debounced keystroke with no
                    focus change, so this is the only thing that can tell somebody using a
                    screen reader that the search found nothing.
                */}
                <output className="mt-1 block text-xs" style={{ color: 'var(--faint)' }}>
                    {search !== '' &&
                        `${matching} matching ${matching === 1 ? 'permission' : 'permissions'}`}
                </output>
            </div>

            <Panel
                title={sharesEnvironment ? 'Manual' : 'Yours'}
                description="Authored here. Editable and removable."
                action={<Badge>{mine.length}</Badge>}
            >
                {mine.length === 0 ? (
                    <EmptyState
                        icon="key"
                        title="You haven't written any yet"
                        description="Add one above to build your own roles — no integration required."
                    />
                ) : (
                    <div className="space-y-2">
                        {mine.map((permission) => (
                            <ManualRow
                                key={permission.id}
                                permission={permission}
                                sharesEnvironment={sharesEnvironment}
                            />
                        ))}
                    </div>
                )}
            </Panel>

            {/*
                Inherited from the environment — organization plane only. On the environment
                plane these ARE the list above, and drawing them twice would suggest two
                catalogues where there is one.

                Shown rather than hidden, and read-only rather than editable: a tenant
                composes roles out of these, so a page that omitted them would explain only
                half of what the roles editor offers.
            */}
            {!sharesEnvironment && (
                <Panel
                    title="From your environment"
                    description="Provided for every organization here. Yours to use in roles, not to change."
                    action={<Badge>{inherited.length}</Badge>}
                >
                    {inherited.length === 0 ? (
                        <EmptyState
                            icon="key"
                            title="Nothing shared with you yet"
                            description="Permissions your environment publishes for every organization will appear here."
                        />
                    ) : (
                        <div className="space-y-2">
                            {inherited.map((permission) => (
                                <ReadOnlyRow
                                    key={permission.id}
                                    permission={permission}
                                    tone="Shared"
                                    usageSuffix="of your"
                                />
                            ))}
                        </div>
                    )}
                </Panel>
            )}

            <Panel
                title="App-declared"
                description="Synced from each app's manifest over the SDK or API. Read-only — the app is their source of truth."
                action={<Badge>{declaredTotal}</Badge>}
            >
                {declared.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="key"
                            title={`No app permission matches “${search}”`}
                            description="Clear the search to see the whole catalog."
                        />
                    ) : (
                        <EmptyState
                            icon="key"
                            title="No app has registered a catalog yet"
                            description="Once an app declares its permissions through the SDK or API, they appear here."
                        />
                    )
                ) : (
                    <div className="space-y-4">
                        {declared.map((group) => (
                            <div key={group.app}>
                                <p
                                    className="text-xs font-semibold uppercase mb-1.5"
                                    style={{
                                        color: 'var(--muted-foreground)',
                                        letterSpacing: '0.05em',
                                    }}
                                >
                                    {group.app}
                                </p>
                                <div className="space-y-2">
                                    {group.permissions.map((permission) => (
                                        <ReadOnlyRow key={permission.id} permission={permission} />
                                    ))}
                                </div>
                            </div>
                        ))}

                        {declaredShown < declaredTotal && (
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                Showing {declaredShown} of {declaredTotal}. Search to reach the rest.
                            </p>
                        )}
                    </div>
                )}
            </Panel>
        </div>
    );
}

/** Author a manual permission. */
function NewPermission({
    sharesEnvironment,
    storeHref,
}: {
    sharesEnvironment: boolean;
    storeHref: string;
}) {
    const form = useForm({ name: '', description: '', tenantAssignable: true });

    return (
        <Panel
            title="New permission"
            description={
                <>
                    A <code className="mono">feature:action</code> key you can compose into roles —
                    e.g. <code className="mono">invoices:create</code>.{' '}
                    {/*
                        Says who gets it, before the button is pressed. The two planes write
                        into different tiers and the form is otherwise identical, which is
                        exactly the invisible difference that had a tenant admin editing the
                        whole environment's catalogue believing it was their own.
                    */}
                    <b>
                        {sharesEnvironment
                            ? 'Available to every organization in this environment.'
                            : 'Yours alone — other organizations never see it.'}
                    </b>
                </>
            }
        >
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, {
                        preserveScroll: true,
                        onSuccess: () => form.reset('name', 'description'),
                    });
                }}
            >
                <div className="grid sm:grid-cols-[1fr_1.4fr_auto] gap-2 items-start">
                    <Field label="Permission key" error={form.errors.name}>
                        <Input
                            name="name"
                            className="mono"
                            placeholder="invoices:create"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>
                    <Field label="Description" optional error={form.errors.description}>
                        <Input
                            name="description"
                            placeholder="Create invoices"
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    </Field>
                    <Button
                        type="submit"
                        variant="primary"
                        className="shrink-0 self-end"
                        loading={form.processing}
                    >
                        Add permission
                    </Button>
                </div>

                {/*
                    Only on the plane where it decides anything. On the organization plane
                    the row already belongs to the organization, so "may org admins use
                    this" has one possible answer, and offering the checkbox invites an
                    administrator to untick their own permission into uselessness.
                */}
                {sharesEnvironment && (
                    <Checkbox
                        checked={form.data.tenantAssignable}
                        onCheckedChange={(checked) => form.setData('tenantAssignable', checked)}
                        label="Tenant-assignable"
                        hint="Organization admins may compose this into their own roles. Untick to keep it internal."
                    />
                )}
            </form>
        </Panel>
    );
}

/** One permission this administrator owns: editable in place, and removable. */
function ManualRow({
    permission,
    sharesEnvironment,
}: {
    permission: PermissionRow;
    sharesEnvironment: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const [confirming, setConfirming] = useState(false);

    /*
     * FOCUS FOLLOWS THE FORM. The row is replaced in place when Edit is pressed, so the
     * button that had focus stops existing — and focus falls back to <body>, which for
     * anybody navigating by keyboard means the page silently rewound to the top. Moved
     * explicitly rather than with `autoFocus`, which would also fire on a re-render the
     * person did not ask for.
     */
    const description = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (editing) {
            description.current?.focus();
        }
    }, [editing]);

    const form = useForm({
        description: permission.description ?? '',
        tenantAssignable: permission.tenantAssignable,
    });

    return (
        <div className="rounded-lg border px-3 py-2" style={{ borderColor: 'var(--border)' }}>
            {editing ? (
                <form
                    className="space-y-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.patch(permission.urls.update, {
                            preserveScroll: true,
                            onSuccess: () => setEditing(false),
                        });
                    }}
                >
                    <p className="text-sm mono">{permission.name}</p>

                    <Field label="Description" optional error={form.errors.description}>
                        <Input
                            ref={description}
                            name="description"
                            value={form.data.description}
                            onChange={(event) => form.setData('description', event.target.value)}
                        />
                    </Field>

                    {sharesEnvironment && (
                        <Checkbox
                            checked={form.data.tenantAssignable}
                            onCheckedChange={(checked) => form.setData('tenantAssignable', checked)}
                            label="Tenant-assignable"
                        />
                    )}

                    <div className="flex items-center gap-2">
                        <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                            Save
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={() => {
                                form.reset();
                                setEditing(false);
                            }}
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            ) : (
                <div className="flex items-center gap-2">
                    <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-2 flex-wrap">
                            <span className="text-sm mono truncate">{permission.name}</span>
                            <Badge>Manual</Badge>
                            {!permission.tenantAssignable && <Badge tone="warn">Internal</Badge>}
                            <RoleCount count={permission.roleCount} />
                        </div>
                        {permission.description !== null && (
                            <p className="text-xs truncate" style={{ color: 'var(--faint)' }}>
                                {permission.description}
                            </p>
                        )}
                    </div>

                    <Button size="sm" className="shrink-0" onClick={() => setEditing(true)}>
                        Edit
                    </Button>
                    <Button
                        size="sm"
                        variant="danger"
                        className="shrink-0"
                        onClick={() => setConfirming(true)}
                    >
                        Delete
                    </Button>
                </div>
            )}

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={permission.name}
                consequence="This permission is removed from every role that currently grants it. This cannot be undone."
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(permission.urls.destroy, { preserveScroll: true });
                }}
            />
        </div>
    );
}

/** A permission somebody else owns — the environment's, or an app's. */
function ReadOnlyRow({
    permission,
    tone = 'App',
    usageSuffix,
}: {
    permission: PermissionRow;
    tone?: string;
    usageSuffix?: string;
}) {
    return (
        <div
            className="flex items-center gap-2 rounded-lg border px-3 py-2"
            style={{ borderColor: 'var(--border)' }}
        >
            <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                    <span className="text-sm mono truncate">{permission.name}</span>
                    <Badge tone={tone === 'App' ? 'info' : 'neutral'}>{tone}</Badge>
                    {permission.orphaned && <Badge tone="warn">Orphaned</Badge>}
                    {!permission.tenantAssignable && <Badge>Internal</Badge>}
                    <RoleCount count={permission.roleCount} suffix={usageSuffix} />
                </div>
                {permission.description !== null && (
                    <p className="text-xs truncate" style={{ color: 'var(--faint)' }}>
                        {permission.description}
                    </p>
                )}
            </div>
        </div>
    );
}

/** What deleting this would strip: how many of the roles in view grant it. */
function RoleCount({ count, suffix }: { count: number; suffix?: string }) {
    if (count === 0) {
        return null;
    }

    return (
        <span className="text-xs" style={{ color: 'var(--faint)' }}>
            in {count} {suffix !== undefined && `${suffix} `}
            {count === 1 ? 'role' : 'roles'}
        </span>
    );
}

Permissions.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
