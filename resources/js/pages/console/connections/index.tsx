import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps, Pagination as PaginationState } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
    Pagination,
    Panel,
    Pill,
} from '@/ui';

interface ConnectionRow {
    id: string;
    name: string;
    type: string;
    active: boolean;
    status: string;
    owner: string | null;
    href: string;
}

interface DomainRow {
    id: string;
    domain: string;
    verified: boolean;
    capture: boolean;
}

type Props = PageProps<{
    help: HelpContent;
    connections: ConnectionRow[];
    pagination: PaginationState;
    search: string;
    mayAdminister: boolean;
    /** An environment administrator has not chosen an organization yet. */
    needsOrganization: boolean;
    entitled: boolean;
    domains: DomainRow[];
    createHref: string;
    urls: { invite: string; addDomain: string };
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

export default function ConnectionsIndex({
    help,
    connections,
    pagination,
    search,
    mayAdminister,
    needsOrganization,
    entitled,
    domains,
    createHref,
    urls,
}: Props) {
    // Both on the flash channel: the portal link admits its holder to this tenant's SSO
    // setup with no account at all, and the DNS token is a one-shot instruction. Neither
    // belongs in a history entry.
    const { portalUrl, dns } = usePage().flash;

    const [term, setTerm] = useState(search);
    const [removing, setRemoving] = useState<DomainRow | null>(null);

    const domainForm = useForm({ domain: '' });

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

    const canAct = mayAdminister && entitled;

    return (
        <>
            <PageHeader
                help={help}
                description="Let people sign in with the company account they already have, instead of a separate password here."
                actions={
                    canAct ? (
                        <>
                            <Button
                                icon="members"
                                onClick={() =>
                                    router.post(urls.invite, {}, { preserveScroll: true })
                                }
                            >
                                Invite your IT admin
                            </Button>
                            <Button asChild variant="primary" className="shrink-0">
                                <Link href={createHref}>
                                    <Icon name="plus" className="w-4 h-4" />
                                    New connection
                                </Link>
                            </Button>
                        </>
                    ) : undefined
                }
            />

            {!needsOrganization && !entitled ? (
                <div className="card mt-8">
                    <EmptyState
                        icon="connections"
                        title="Single sign-on is an Enterprise feature"
                        help={help}
                        description="Letting your people sign in with Entra ID, Okta or Google Workspace is available on the Enterprise plan. Contact your account team to enable it for this organization."
                    />
                </div>
            ) : (
                <div className="mt-8 space-y-6">
                    {needsOrganization && (
                        <div className="card">
                            <EmptyState
                                icon="layers"
                                title="Choose an organization"
                                description="Below is every connection in this environment. To add one, verify a domain or invite an IT admin, pick the organization you are configuring — the selector sits in the bar at the top of the page."
                            />
                        </div>
                    )}

                    {portalUrl !== undefined && mayAdminister && (
                        <RevealedOnce
                            icon="members"
                            title="Setup link for your IT admin"
                            description="Send this single-use link to whoever configures your identity provider. It expires soon and works without an account. Copy it now — it is shown only once."
                            value={portalUrl}
                        />
                    )}

                    <div>
                        <Input
                            type="search"
                            style={{ maxWidth: '24rem' }}
                            placeholder="Search by name"
                            aria-label="Search connections"
                            value={term}
                            onChange={(event) => setTerm(event.target.value)}
                        />
                            {/*
                                SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                                change, so the count is the only thing that can report the filter
                                narrowed to nothing.
                            */}
                            <output className="sr-only">
                                {pagination.total}{' '}
                                {pagination.total === 1 ? 'connection' : 'connections'} found.
                            </output>
                    </div>

                    <div
                        className="rounded-xl border overflow-hidden"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        {connections.length === 0 ? (
                            search !== '' ? (
                                <EmptyState
                                    icon="search"
                                    title={`No matches for "${search}"`}
                                    description="No connections match that name. Try a different search term."
                                />
                            ) : (
                                <EmptyState
                                    icon="connections"
                                    title="No identity provider connected yet"
                                    help={help}
                                    description="Right now people sign in with credentials held here. Connect your provider and they use the company account they already have — and lose access here the moment you disable it there."
                                    steps={[
                                        'In Entra ID, Okta or Google Workspace, create an application for Cbox ID.',
                                        'Add the connection — paste the provider’s metadata and the fields fill themselves.',
                                        'Verify the email domains you own, so your people are routed to your provider automatically.',
                                        'Activate the connection once a test sign-in works.',
                                    ]}
                                    actions={
                                        canAct ? (
                                            <Button asChild variant="primary">
                                                <Link href={createHref}>
                                                    <Icon name="plus" className="w-4 h-4" />
                                                    New connection
                                                </Link>
                                            </Button>
                                        ) : undefined
                                    }
                                />
                            )
                        ) : (
                            connections.map((connection, index) => (
                                <Link
                                    key={connection.id}
                                    href={connection.href}
                                    className="flex items-center gap-3 p-4 transition-colors hover:bg-[var(--surface-2)]"
                                    style={
                                        index < connections.length - 1
                                            ? { borderBottom: '1px solid var(--border)' }
                                            : undefined
                                    }
                                >
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center gap-2 flex-wrap">
                                            <span className="font-medium truncate">
                                                {connection.name}
                                            </span>
                                            {needsOrganization && connection.owner !== null && (
                                                <Badge>{connection.owner}</Badge>
                                            )}
                                        </div>
                                        <p
                                            className="text-xs truncate mono"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {connection.id}
                                        </p>
                                    </div>
                                    <Pill tone="info">{connection.type}</Pill>
                                    <Badge tone={connection.active ? 'success' : 'neutral'}>
                                        {connection.active ? 'Active' : connection.status}
                                    </Badge>
                                    <Icon
                                        name="chevron"
                                        className="w-4 h-4 shrink-0"
                                        style={{
                                            color: 'var(--faint)',
                                            transform: 'rotate(-90deg)',
                                        }}
                                    />
                                </Link>
                            ))
                        )}
                    </div>

                    <Pagination
                        pagination={pagination}
                        noun="connection"
                        href={(page) => listHref(search, page)}
                    />

                    {/*
                        Verified domains — DNS-proven ownership powers home-realm discovery
                        and the optional capture gate. They belong to the ORGANIZATION
                        rather than to one connection, which is why they live here.
                    */}
                    {!needsOrganization && (
                        <div className="space-y-4">
                            <div>
                                <h2 className="cbx-panel-title" style={{ fontSize: '18px' }}>
                                    Verified domains
                                </h2>
                                <p
                                    className="mt-1 text-sm"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    Prove ownership of an email domain to route your team to SSO
                                    automatically.
                                </p>
                            </div>

                            {mayAdminister && (
                                <form
                                    className="card p-5 flex flex-wrap items-end gap-3"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        domainForm.post(urls.addDomain, {
                                            preserveScroll: true,
                                            onSuccess: () => domainForm.reset(),
                                        });
                                    }}
                                >
                                    <div className="flex-1 min-w-[14rem]">
                                        <Field label="Domain" error={domainForm.errors.domain}>
                                            <Input
                                                name="domain"
                                                className="mono"
                                                inputMode="url"
                                                autoCapitalize="none"
                                                spellCheck={false}
                                                placeholder="acme.com"
                                                value={domainForm.data.domain}
                                                onChange={(event) =>
                                                    domainForm.setData('domain', event.target.value)
                                                }
                                            />
                                        </Field>
                                    </div>
                                    <Button
                                        type="submit"
                                        variant="primary"
                                        icon="plus"
                                        loading={domainForm.processing}
                                    >
                                        Add domain
                                    </Button>
                                </form>
                            )}

                            {dns !== undefined && mayAdminister && (
                                <RevealedOnce
                                    icon="connections"
                                    title={`Verify ${dns.domain}`}
                                    description={
                                        <>
                                            Add a TXT record at{' '}
                                            <code className="mono">{dns.host}</code> with the value
                                            below, then click Verify. DNS changes can take a few
                                            minutes to propagate.
                                        </>
                                    }
                                    value={dns.token}
                                />
                            )}

                            <div className="space-y-3">
                                {domains.length === 0 ? (
                                    <div className="card">
                                        <EmptyState
                                            icon="directory"
                                            title="No verified domains yet"
                                            description="Verifying a domain you own — acme.com — lets Cbox ID recognise your people by their email address and send them straight to your provider, so nobody has to pick the right sign-in button."
                                        />
                                    </div>
                                ) : (
                                    domains.map((domain) => (
                                        <DomainCard
                                            key={domain.id}
                                            domain={domain}
                                            mayAdminister={mayAdminister}
                                            onRemove={() => setRemoving(domain)}
                                        />
                                    ))
                                )}
                            </div>
                        </div>
                    )}
                </div>
            )}

            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.domain ?? ''}
                consequence="This domain will no longer route its users through this organization's SSO. This cannot be undone."
                onConfirm={() => {
                    const target = removing;
                    setRemoving(null);

                    if (target !== null) {
                        router.delete(`${window.location.pathname}/domains/${target.id}`, {
                            preserveScroll: true,
                        });
                    }
                }}
            />
        </>
    );
}

function DomainCard({
    domain,
    mayAdminister,
    onRemove,
}: {
    domain: DomainRow;
    mayAdminister: boolean;
    onRemove: () => void;
}) {
    const base = `${window.location.pathname}/domains/${domain.id}`;

    return (
        <div className="card p-5">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-2 flex-wrap">
                        <p className="font-semibold truncate mono">{domain.domain}</p>
                        <Pill tone={domain.verified ? 'success' : 'warning'}>
                            {domain.verified ? 'Verified' : 'Pending'}
                        </Pill>
                        {domain.capture && <Pill tone="info">Capture on</Pill>}
                    </div>
                </div>

                {mayAdminister && (
                    <div className="flex items-center gap-2">
                        {!domain.verified && (
                            <Button
                                variant="primary"
                                size="sm"
                                icon="check"
                                onClick={() =>
                                    router.post(`${base}/verify`, {}, { preserveScroll: true })
                                }
                            >
                                Verify
                            </Button>
                        )}
                        <Button size="sm" variant="danger" onClick={onRemove}>
                            Remove
                        </Button>
                    </div>
                )}
            </div>

            {domain.verified && mayAdminister && (
                <div
                    className="mt-4 flex items-start justify-between gap-3 rounded-lg px-3 py-3"
                    style={{ background: 'var(--secondary)' }}
                >
                    <div className="min-w-0">
                        <p className="text-sm font-medium">Capture</p>
                        <p className="mt-0.5 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            Force everyone with an @{domain.domain} email to sign in through this
                            organization's SSO.
                        </p>
                    </div>
                    <Button
                        size="sm"
                        variant={domain.capture ? 'primary' : 'ghost'}
                        onClick={() => router.post(`${base}/capture`, {}, { preserveScroll: true })}
                    >
                        {domain.capture ? 'On' : 'Off'}
                    </Button>
                </div>
            )}
        </div>
    );
}

/**
 * A value the server will not repeat.
 *
 * Both of the things this page mints are one-shot: the portal link is a credential in a
 * URL, and the DNS token is the answer to a challenge that is re-issued rather than
 * re-read. Neither is a page prop, so neither is in the history entry — this is where the
 * one render that carries them shows them.
 */
function RevealedOnce({
    icon,
    title,
    description,
    value,
}: {
    icon: 'members' | 'connections';
    title: React.ReactNode;
    description: React.ReactNode;
    value: string;
}) {
    return (
        <Panel
            className="p-5"
            style={{ borderColor: 'color-mix(in oklch, var(--accent) 40%, transparent)' }}
        >
            <div className="flex items-center gap-2 font-semibold">
                <Icon name={icon} className="w-4 h-4" />
                {title}
            </div>
            <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {description}
            </p>
            <p
                className="mt-3 mono text-xs rounded-lg px-3 py-2 select-all break-all"
                style={{ background: 'var(--secondary)', border: '1px solid var(--border)' }}
            >
                {value}
            </p>
        </Panel>
    );
}

ConnectionsIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
