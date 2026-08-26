import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Badge,
    Button,
    Combobox,
    EmptyState,
    Icon,
    Input,
    PageHeader,
    Pagination,
} from '@/ui';

interface Offerable {
    id: string;
    name: string;
    description: string | null;
}

interface RoleRow {
    id: string;
    name: string;
    description: string | null;
    /** Declared by an application, which is its source of truth — read-only here. */
    declaredByApp: boolean;
    /** The app whose tokens it is stamped into, or null for every app. */
    app: string | null;
    environmentWide: boolean;
    permissions: string[];
    moreCount: number;
    offerable: Offerable[];
    mayCompose: boolean;
    href: string;
    grantHref: string;
}

type Props = PageProps<{
    help: HelpContent;
    roles: RoleRow[];
    pagination: PaginationState;
    search: string;
    mayAdminister: boolean;
    organizationChosen: boolean;
    sample: { role: string; permissions: string[] } | null;
    createHref: string;
    consoleAccessHref: string | null;
}>;

function listHref(search: string, page?: number): string {
    const query = new URLSearchParams();

    if (search !== '') {
        query.set('q', search);
    }

    if (page !== undefined && page > 1) {
        query.set('page', String(page));
    }

    const rest = query.toString();

    return rest === '' ? window.location.pathname : `${window.location.pathname}?${rest}`;
}

export default function RolesIndex({
    help,
    roles,
    pagination,
    search,
    mayAdminister,
    organizationChosen,
    sample,
    createHref,
    consoleAccessHref,
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

    return (
        <>
            <PageHeader
                help={help}
                description="What people can do inside your apps. You assign roles here; each app decides what its roles are allowed to do."
                actions={
                    mayAdminister ? (
                        <Button asChild variant="primary" className="shrink-0">
                            <Link href={createHref}>
                                <Icon name="plus" className="w-4 h-4" />
                                New role
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            {/* The one paragraph that removes the confusion this page arrives with. */}
            <div
                className="card p-4 mt-6"
                style={{ background: 'var(--accent-soft)', borderColor: 'var(--accent-edge)' }}
            >
                <div className="flex items-start gap-3">
                    <span
                        className="grid place-items-center rounded-lg shrink-0"
                        style={{
                            width: '2rem',
                            height: '2rem',
                            background: 'var(--card)',
                            color: 'var(--primary)',
                        }}
                    >
                        <Icon name="key" className="w-4 h-4" />
                    </span>
                    <p className="text-sm">
                        <b>Cbox ID assigns roles; your app decides what they can do.</b> A role is a
                        label stamped into the token — <b>app roles</b> are declared by each app and
                        read-only here, and the rest are ones you define. This is different from{' '}
                        {consoleAccessHref !== null ? (
                            <Link
                                href={consoleAccessHref}
                                className="underline"
                                style={{ color: 'var(--accent-strong)' }}
                            >
                                console access
                            </Link>
                        ) : (
                            'console access'
                        )}{' '}
                        (owner/admin/member), which is who can run this console.
                    </p>
                </div>
            </div>

            {sample !== null && <TokenShape sample={sample} />}

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name"
                    aria-label="Search roles"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list re-renders on a debounced keystroke with no focus
                    change, so the result count is the only thing that can report the
                    filter narrowed to nothing.
                */}
                <output aria-live="polite" className="sr-only">
                    {pagination.total} {pagination.total === 1 ? 'role' : 'roles'} found.
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {roles.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title="No matches"
                            description={`No roles match “${search}”. Try a different name.`}
                        />
                    ) : (
                        <EmptyState
                            icon="shield"
                            title="No roles yet"
                            help={help}
                            description="Without roles, everyone who can sign in gets whatever an app gives a plain user. A role is how you say “these people are editors, those are support” once, and have every connected app honour it."
                            steps={[
                                'Let your apps declare the roles they understand — those arrive here on their own.',
                                'Or create a role here and compose it from the permissions your apps have declared.',
                                'Assign it to people; the role travels with them into every connected app.',
                            ]}
                            actions={
                                mayAdminister ? (
                                    <Button asChild variant="primary">
                                        <Link href={createHref}>
                                            <Icon name="plus" className="w-4 h-4" />
                                            New role
                                        </Link>
                                    </Button>
                                ) : undefined
                            }
                        />
                    )
                ) : (
                    roles.map((role, index) => (
                        <Row
                            key={role.id}
                            role={role}
                            mayAdminister={mayAdminister}
                            last={index === roles.length - 1}
                        />
                    ))
                )}
            </div>

            <Legend roles={roles} />

            {!organizationChosen && (
                // The distinction that matters: nothing is wrong with this administrator,
                // they simply have not said which organization they are acting for.
                <p className="mt-4 text-sm" style={{ color: 'var(--faint)' }}>
                    Showing every role in this environment. Choose an organization in the console
                    header to compose one of its roles.
                </p>
            )}

            <Pagination
                pagination={pagination}
                noun="role"
                href={(page) => listHref(search, page)}
            />
        </>
    );
}

/**
 * What the badges on the rows above mean, said once.
 *
 * These were `title` attributes on each badge, which is the one place they could not do
 * their job: a tooltip is unreachable by touch, invisible to most screen readers, and gone
 * the moment the pointer moves — and "Environment-wide" is not decoration, it is the
 * difference between a role you may compose and one you may only hold.
 *
 * IT SAYS DIFFERENT THINGS ON THE TWO PLANES. From an organization, an environment-wide
 * role means "you may use this but not change it". From the environment it means the
 * opposite, and something more: it is the only kind that can be granted to a person across
 * every organization at once. Which sentence applies is exactly whether this administrator
 * may compose one, so the rows already carry the answer.
 */
function Legend({ roles }: { roles: RoleRow[] }) {
    const declared = roles.some((role) => role.declaredByApp);
    const wide = roles.filter((role) => role.environmentWide);

    if (!declared && wide.length === 0) {
        return null;
    }

    return (
        <dl className="mt-4 space-y-1.5 text-sm" style={{ color: 'var(--muted-foreground)' }}>
            {declared && (
                <div className="flex items-start gap-2">
                    <dt className="shrink-0">
                        <Badge>App</Badge>
                    </dt>
                    <dd>Declared by the application, which is its source of truth.</dd>
                </div>
            )}
            {wide.length > 0 && (
                <div className="flex items-start gap-2">
                    <dt className="shrink-0">
                        <Badge>Environment-wide</Badge>
                    </dt>
                    <dd>
                        {wide.some((role) => role.mayCompose)
                            ? 'Defined for the whole environment: every organization can use it, and it is the only kind that can be granted to a person in all of them at once.'
                            : 'Owned by the environment — it applies here, but is not yours to change.'}
                    </dd>
                </div>
            )}
        </dl>
    );
}

/**
 * One role.
 *
 * A row rather than one big link: the permission picker lives inside it, and a combobox
 * nested in an anchor is neither valid markup nor operable by keyboard.
 */
function Row({
    role,
    mayAdminister,
    last,
}: {
    role: RoleRow;
    mayAdminister: boolean;
    last: boolean;
}) {
    return (
        <div
            className="p-4"
            style={last ? undefined : { borderBottom: '1px solid var(--border)' }}
        >
            <div className="flex items-center gap-3 flex-wrap">
                <div className="min-w-0 flex-1">
                    <Link href={role.href} className="font-medium truncate">
                        {role.name}
                    </Link>
                    {role.description !== null && (
                        <p className="text-sm truncate" style={{ color: 'var(--muted-foreground)' }}>
                            {role.description}
                        </p>
                    )}
                </div>

                {role.declaredByApp && <Badge>App</Badge>}

                {role.app !== null ? (
                    <Badge>
                        <Icon name="clients" className="w-3 h-3" />
                        {role.app} only
                    </Badge>
                ) : (
                    <Badge>All apps</Badge>
                )}

                {role.environmentWide && <Badge>Environment-wide</Badge>}

                <Icon
                    name="chevron"
                    className="w-4 h-4 shrink-0"
                    style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
                />
            </div>

            <div className="flex flex-wrap items-center gap-1.5 mt-3">
                {role.permissions.length === 0 ? (
                    <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        No permissions yet.
                    </span>
                ) : (
                    role.permissions.map((permission) => (
                        <Badge key={permission}>
                            <Icon name="key" className="w-3 h-3" />
                            <span className="mono">{permission}</span>
                        </Badge>
                    ))
                )}
                {role.moreCount > 0 && (
                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                        and {role.moreCount} more
                    </span>
                )}
            </div>

            {mayAdminister &&
                role.mayCompose &&
                (role.offerable.length === 0 ? (
                    <p className="text-xs mt-3" style={{ color: 'var(--faint)' }}>
                        Every available permission is already granted.
                    </p>
                ) : (
                    <div className="mt-3" style={{ maxWidth: '22rem' }}>
                        <Combobox
                            value={undefined}
                            aria-label={`Add a permission to the ${role.name} role`}
                            placeholder="+ Add a permission…"
                            searchPlaceholder="Search permissions…"
                            emptyMessage="No permission matches that."
                            options={role.offerable.map((permission) => ({
                                value: permission.id,
                                label: permission.name,
                                keywords: [permission.name],
                                hint: permission.description ?? undefined,
                            }))}
                            onValueChange={(permission) =>
                                router.post(
                                    role.grantHref,
                                    { permission, granted: true },
                                    { preserveScroll: true },
                                )
                            }
                        />
                    </div>
                ))}
        </div>
    );
}

/**
 * What the app on the other end actually receives.
 *
 * The page above says a role is "a label stamped into the token" and then never shows the
 * token, so the developer reading this still has to guess which claim to read — and the
 * two commonest guesses, `scope` and a nested `authorization` object, are both wrong.
 */
function TokenShape({ sample }: { sample: { role: string; permissions: string[] } }) {
    const quoted = sample.permissions.map((permission) => `"${permission}"`).join(', ');

    return (
        <details className="card mt-4 p-4">
            <summary className="text-sm font-medium cursor-pointer">
                What your app receives
            </summary>

            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Roles and permissions arrive in the access token as two arrays. Your app reads them
                straight from the token — there is no call back to Cbox ID:
            </p>
            <Snippet>{`{
  "sub": "the person's id",
  "org": "the organization they are acting for",
  "roles": ["${sample.role}"],
  "permissions": [${quoted}]
}`}</Snippet>

            {/*
                SAID HERE, because it is the question people arrive with and the answer
                lived in a code comment. An app that expects "groups" — Kubernetes,
                Grafana, Vault, and most SaaS predating this vocabulary — is asking for
                these same roles under the name its ecosystem uses. There is nothing
                separate to create, and looking for a Groups page is the wrong search.
                (Directory groups, on Sync users in, are the opposite direction: an
                upstream provider's groups mapped ONTO these roles, and they never reach a
                token themselves.)
            */}
            <p className="mt-3 text-sm">
                <b>If your app expects “groups”, these are them.</b> Tick the{' '}
                <code className="mono">groups</code> scope when you register the app and the ID
                token carries the same role names as a <code className="mono">groups</code> claim —
                the name Kubernetes, Grafana and Vault look for. Nothing else to create.
            </p>
            <Snippet>{`{
  "groups": ["${sample.role}"]
}`}</Snippet>

            <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                {sample.permissions.length === 0 && (
                    <>
                        <b>{sample.role}</b> has no permissions yet, so <code className="mono">
                            permissions
                        </code>{' '}
                        arrives empty — the role name is still there to act on.{' '}
                    </>
                )}
                The <code className="mono">scope</code> claim is a different thing: it is what the{' '}
                <em>app</em> was allowed to ask for, not what this <em>person</em> may do.
            </p>
        </details>
    );
}

function Snippet({ children }: { children: string }) {
    return (
        <pre
            className="mt-3 rounded-lg p-3 overflow-x-auto text-xs mono"
            style={{
                background: 'var(--surface-2)',
                border: '1px solid var(--border)',
                lineHeight: 1.6,
            }}
        >
            {children}
        </pre>
    );
}

RolesIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
