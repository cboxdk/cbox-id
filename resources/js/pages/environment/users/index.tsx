import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, SimplePagination as SimplePaginationState } from '@/types';
import {
    Button,
    EmptyState,
    Icon,
    Input,
    PageHeader,
    Pill,
    type PillTone,
    SimplePagination,
} from '@/ui';

interface UserRow {
    id: string;
    name: string | null;
    email: string;
    status: string;
    verified: boolean;
    href: string;
}

type Props = PageProps<{
    users: UserRow[];
    pagination: SimplePaginationState;
    search: string;
    createHref: string;
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

function statusTone(status: string): PillTone {
    if (status === 'disabled') {
        return 'warning';
    }

    return status === 'locked' ? 'destructive' : 'success';
}

export default function UsersIndex({ users, pagination, search, createHref }: Props) {
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
                description="Every end-user identity in this environment."
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            New user
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by email or name"
                    aria-label="Search users"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so this count is the only thing that can report the filter
                    narrowed to nothing. Counted on THIS page — the total would cost a
                    COUNT(*) over a leading-wildcard search of every user in the
                    environment, and "no users on this page" reports an empty filter just
                    as clearly.
                */}
                <output className="sr-only">
                    {pagination.count} {pagination.count === 1 ? 'user' : 'users'} on this page.
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {users.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title="No matches"
                            description={`No users match “${search}”. Try a different email or name.`}
                        />
                    ) : (
                        <EmptyState
                            icon="members"
                            title="No users yet"
                            description="Every end-user identity in this environment appears here. Create the first user to get started."
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        New user
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    users.map((user, index) => (
                        <Link
                            key={user.id}
                            href={user.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < users.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <span className="font-medium truncate">
                                    {user.name ?? user.email}
                                </span>
                                <p
                                    className="text-sm truncate mono"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {user.email}
                                </p>
                            </div>

                            {!user.verified && <Pill tone="warning">Unverified</Pill>}

                            <Pill tone={statusTone(user.status)}>{user.status}</Pill>

                            <Icon
                                name="chevron"
                                className="w-4 h-4 shrink-0"
                                style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
                            />
                        </Link>
                    ))
                )}
            </div>

            <div className="mt-4">
                <SimplePagination
                    pagination={pagination}
                    noun="user"
                    href={(page) => listHref(search, page)}
                />
            </div>
        </>
    );
}

UsersIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
