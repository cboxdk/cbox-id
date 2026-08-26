import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Badge,
    Button,
    EmptyState,
    Icon,
    Input,
    PageHeader,
    Pagination,
    Pill,
} from '@/ui';

interface SecretRow {
    id: string;
    name: string;
    provider: string;
    scope: string;
    status: 'active' | 'expired' | 'revoked';
    revoked: boolean;
    rotatedAt: string | null;
    expiresAt: string | null;
    href: string;
}

type Props = PageProps<{
    help: HelpContent;
    secrets: SecretRow[];
    pagination: PaginationState;
    search: string;
    /** True when this is the environment's OWN set, not a wider view of the tenants'. */
    environmentWide: boolean;
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

/** Revoked is permanent; expired is a date that has passed. The next move differs. */
export function statusTone(status: SecretRow['status']): 'success' | 'warning' | 'destructive' {
    if (status === 'revoked') {
        return 'destructive';
    }

    return status === 'expired' ? 'warning' : 'success';
}

export default function VaultIndex({
    help,
    secrets,
    pagination,
    search,
    environmentWide,
    createHref,
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
                description="API keys your apps and agents present to other services. Each value is sealed at rest, handed only to the apps you grant, and never shown again after you store it."
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            New secret
                        </Link>
                    </Button>
                }
            />

            {environmentWide && (
                // Said out loud: "the vault" meaning two different collections depending
                // on a picker elsewhere in the chrome is not something an administrator
                // should have to infer.
                <p className="mt-4 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    Showing this environment's own secrets. Choose an organization above to manage
                    that organization's.
                </p>
            )}

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name"
                    aria-label="Search secrets"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {pagination.total} {pagination.total === 1 ? 'secret' : 'secrets'} found.
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {secrets.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="key"
                            title="No matching secrets"
                            description={`No secret matches “${search}”. Try a different name.`}
                        />
                    ) : (
                        <EmptyState
                            icon="key"
                            title="No secrets stored yet"
                            help={help}
                            description="Every API key sitting in an app’s config or environment file is one you cannot rotate centrally or take away in a hurry. Stored here, it is encrypted, granted per app, and revocable in one click."
                            steps={[
                                'Store the provider’s API key — you will not see it again afterwards.',
                                'Grant the specific apps that may use it; nothing else can read it.',
                                'Rotate it here when the provider issues a new one — the apps keep working, unchanged.',
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        New secret
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    secrets.map((secret, index) => (
                        <Link
                            key={secret.id}
                            href={secret.href}
                            className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                            style={
                                index < secrets.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <span className="font-medium truncate">{secret.name}</span>
                                <p
                                    className="text-xs truncate mono"
                                    style={{ color: 'var(--faint)' }}
                                >
                                    {secret.provider}
                                </p>
                            </div>

                            <Badge>{secret.scope}</Badge>

                            <Pill tone={statusTone(secret.status)}>
                                {secret.status === 'revoked'
                                    ? 'Revoked'
                                    : secret.status === 'expired'
                                      ? 'Expired'
                                      : 'Active'}
                            </Pill>

                            <Icon
                                name="chevron"
                                className="w-4 h-4 shrink-0"
                                style={{ color: 'var(--faint)', transform: 'rotate(-90deg)' }}
                            />
                        </Link>
                    ))
                )}
            </div>

            <Pagination
                pagination={pagination}
                noun="secret"
                href={(page) => listHref(search, page)}
            />
        </>
    );
}

VaultIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
