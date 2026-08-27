import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, EmptyState, Field, Icon, Input, PageHeader, Panel, Pill } from '@/ui';

interface OrganizationResult {
    id: string;
    name: string;
    slug: string;
    suspended: boolean;
    plane: string;
    href: string;
}

interface UserResult {
    id: string;
    name: string | null;
    email: string;
    plane: string;
    organizations: { id: string; name: string }[];
    /** Null for somebody in no organization — there is nowhere to send them. */
    href: string | null;
}

type Props = PageProps<{
    term: string;
    /** False below the minimum term length: a hint rather than a query. */
    ready: boolean;
    organizations: OrganizationResult[];
    users: UserResult[];
}>;

export default function PlatformSearch({ term, ready, organizations, users }: Props) {
    const [query, setQuery] = useState(term);

    useEffect(() => {
        if (query === term) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                query === ''
                    ? window.location.pathname
                    : `${window.location.pathname}?term=${encodeURIComponent(query)}`,
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [query, term]);

    return (
        <>
            <PageHeader description="Find an organization or a user across every environment — above the plane the console is currently pinned to." />

            {/*
                The results arrive without a page load, so nothing else here tells a
                screen-reader user that anything happened. Typing "acme" returned ten rows and
                announced none of them (WCAG 4.1.3).
            */}
            <output className="sr-only">
                {ready
                    ? `${organizations.length} ${organizations.length === 1 ? 'organization' : 'organizations'} and ${users.length} ${users.length === 1 ? 'user' : 'users'} found for “${term}”.`
                    : 'Type at least two characters to search.'}
            </output>

            <div className="card p-4 mb-5 mt-8">
                <Field
                    label="Search term"
                    hint="Matches organization name/slug and user email/name. Case-insensitive; literal % and _ are not wildcards."
                >
                    <div className="relative">
                        <span
                            className="absolute inset-y-0 left-0 flex items-center pl-3"
                            style={{ color: 'var(--faint)' }}
                            aria-hidden="true"
                        >
                            <Icon name="search" className="w-4 h-4" />
                        </span>
                        <Input
                            type="search"
                            style={{ paddingLeft: '2.25rem' }}
                            placeholder="Name, slug or email…"
                            autoComplete="off"
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                        />
                    </div>
                </Field>
            </div>

            {!ready ? (
                <EmptyState
                    icon="search"
                    title="Type at least two characters"
                    description="Searches organizations and users across every environment on this install."
                />
            ) : (
                <>
                    <div className="mb-5">
                        <Panel
                            title="Organizations"
                            action={
                                <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                    {organizations.length}{' '}
                                    {organizations.length === 1 ? 'match' : 'matches'}
                                </span>
                            }
                        >
                            {organizations.length === 0 ? (
                                <p
                                    className="py-6 text-center text-sm"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    No organizations match “{term}”.
                                </p>
                            ) : (
                                organizations.map((organization, index) => (
                                    <Row
                                        key={organization.id}
                                        last={index === organizations.length - 1}
                                        plane={organization.plane}
                                        action={
                                            <Button asChild size="sm">
                                                <Link href={organization.href}>View</Link>
                                            </Button>
                                        }
                                    >
                                        <p className="text-sm font-semibold truncate">
                                            {organization.name}
                                            {organization.suspended && (
                                                <Pill
                                                    tone="destructive"
                                                    className="align-middle ml-1"
                                                >
                                                    Suspended
                                                </Pill>
                                            )}
                                        </p>
                                        <p
                                            className="text-xs mono truncate"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {organization.slug}
                                        </p>
                                    </Row>
                                ))
                            )}
                        </Panel>
                    </div>

                    <Panel
                        title="Users"
                        action={
                            <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                {users.length} {users.length === 1 ? 'match' : 'matches'}
                            </span>
                        }
                    >
                        {users.length === 0 ? (
                            <p
                                className="py-6 text-center text-sm"
                                style={{ color: 'var(--faint)' }}
                            >
                                No users match “{term}”.
                            </p>
                        ) : (
                            users.map((user, index) => (
                                <Row
                                    key={user.id}
                                    last={index === users.length - 1}
                                    plane={user.plane}
                                    action={
                                        user.href === null ? (
                                            // Nowhere to send them, said plainly rather than
                                            // offered as a control that goes nowhere.
                                            <span
                                                className="text-xs"
                                                style={{ color: 'var(--faint)' }}
                                            >
                                                No organization
                                            </span>
                                        ) : (
                                            <Button asChild size="sm">
                                                <Link href={user.href}>View</Link>
                                            </Button>
                                        )
                                    }
                                >
                                    <p className="text-sm font-medium truncate">{user.email}</p>
                                    <p
                                        className="text-xs truncate"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {user.name ?? '—'}
                                        {user.organizations.length > 0 &&
                                            ` · ${user.organizations.map((o) => o.name).join(', ')}`}
                                    </p>
                                </Row>
                            ))
                        )}
                    </Panel>
                </>
            )}
        </>
    );
}

/** One result: what it is, which plane it lives in, and the way to open it. */
function Row({
    children,
    plane,
    action,
    last,
}: {
    children: React.ReactNode;
    plane: string;
    action: React.ReactNode;
    last: boolean;
}) {
    return (
        <div
            className="py-3 flex flex-col gap-2 sm:grid sm:items-center sm:gap-4"
            style={{
                gridTemplateColumns: '2.5fr 1.4fr auto',
                borderBottom: last ? undefined : '1px solid var(--border)',
            }}
        >
            <div className="min-w-0">{children}</div>
            <div>
                <Pill tone="info">
                    <Icon name="layers" className="w-3 h-3" aria-hidden="true" />
                    {plane}
                </Pill>
            </div>
            <div className="sm:justify-self-end">{action}</div>
        </div>
    );
}

PlatformSearch.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
