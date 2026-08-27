import { Link, router, useForm } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Dialog, Input, Kv, KvList, PageHeader, Panel, Pill, Stat, Table, Td, Th } from '@/ui';

interface MemberRow {
    userId: string;
    email: string | null;
    name: string | null;
    role: string;
    status: string;
    impersonateHref: string | null;
    organizationId: string;
}

type Props = PageProps<{
    organization: {
        id: string;
        name: string;
        slug: string;
        status: string;
        active: boolean;
        type: string;
        createdAt: string | null;
    };
    members: MemberRow[];
    memberTotal: number;
    usage: {
        members: number;
        mfaUsers: number;
        mfaAdoption: number;
        sessions: number;
        connections: number;
        domains: number;
        clients: number;
        serviceAccounts: number;
        signIns: number;
    };
    childCount: number;
    sso: { type: string; name: string; status: string } | null;
    domains: { domain: string; verifiedAt: string | null; capture: boolean }[];
    entitlements: { key: string; value: string; mode: string; source: string }[];
    recent: {
        action: string;
        actorType: string;
        actorId: string | null;
        recordedAt: string | null;
    }[];
    ancestors: { id: string; name: string; href: string }[];
    indexHref: string;
    toggleHref: string;
}>;

function plural(count: number, noun: string): string {
    return `${count} ${count === 1 ? noun : `${noun}s`}`;
}

function memberTone(status: string) {
    if (status === 'active') {
        return 'success' as const;
    }

    return status === 'suspended' ? ('destructive' as const) : ('warning' as const);
}

export default function Organization({
    organization,
    members,
    memberTotal,
    usage,
    childCount,
    sso,
    domains,
    entitlements,
    recent,
    ancestors,
    indexHref,
    toggleHref,
}: Props) {
    const [confirming, setConfirming] = useState(false);

    return (
        <>
            <div className="mb-5">
                <Link
                    href={indexHref}
                    className="inline-flex items-center gap-1 text-sm"
                    style={{ color: 'var(--muted)' }}
                >
                    <span aria-hidden="true">&larr;</span> Back to organizations
                </Link>
            </div>

            {/*
                An explicit eyebrow, the one case the component documents: this is a
                resource detail page, so it has no nav entry of its own to be derived from.
                It says "Platform" because that is the rail area highlighted behind it — it
                used to say "Organization", which names the resource type rather than where
                you are standing, and so disagreed with the sidebar on the one label whose
                only job is orientation.
            */}
            <PageHeader
                eyebrow="Platform"
                description="Tenant detail in the target environment — members, SSO, domains, entitlements and recent activity. The only thing changed from this page is the tenant's status."
                actions={
                    <Button
                        variant={organization.active ? 'danger' : 'primary'}
                        onClick={() => setConfirming(true)}
                    >
                        {organization.active ? 'Suspend' : 'Reactivate'}
                    </Button>
                }
            />

            <Panel className="mb-5 mt-8">
                {ancestors.length > 0 && (
                    <nav
                        aria-label="Breadcrumb"
                        className="mb-3 text-xs flex flex-wrap items-center gap-1"
                        style={{ color: 'var(--faint)' }}
                    >
                        {ancestors.map((ancestor) => (
                            <Fragment key={ancestor.id}>
                                <Link href={ancestor.href} className="hover:underline">
                                    {ancestor.name}
                                </Link>
                                <span aria-hidden="true">/</span>
                            </Fragment>
                        ))}
                        <span style={{ color: 'var(--muted)' }}>{organization.name}</span>
                    </nav>
                )}

                <div className="flex flex-wrap items-center gap-2 mb-4">
                    <h2 className="text-base font-semibold">{organization.name}</h2>
                    {organization.status === 'suspended' ? (
                        <Pill tone="destructive">Suspended</Pill>
                    ) : organization.active ? (
                        <Pill tone="success">Active</Pill>
                    ) : (
                        <Pill className="capitalize">{organization.status}</Pill>
                    )}
                </div>

                <KvList>
                    <Kv label="Slug">{organization.slug}</Kv>
                    <Kv label="Type" prose>
                        <span className="capitalize">{organization.type}</span>
                    </Kv>
                    <Kv label="Members" prose>
                        {memberTotal}
                    </Kv>
                    <Kv label="Child tenants" prose>
                        {childCount}
                    </Kv>
                    {organization.createdAt !== null && (
                        <Kv label="Created">{organization.createdAt}</Kv>
                    )}
                </KvList>
            </Panel>

            <section className="mb-5">
                <h2 className="text-sm font-semibold mb-3">Usage</h2>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <Stat label="Members" value={usage.members.toLocaleString()} />
                    <Stat
                        label={`MFA adoption — ${usage.mfaUsers} of ${usage.members} with MFA`}
                        value={`${usage.mfaAdoption}%`}
                    />
                    <Stat label="Active sessions" value={usage.sessions.toLocaleString()} />
                    <Stat label="Sign-ins (30d)" value={usage.signIns.toLocaleString()} />
                    <Stat label="SSO connections" value={usage.connections.toLocaleString()} />
                    <Stat label="Verified domains" value={usage.domains.toLocaleString()} />
                    <Stat label="API clients" value={usage.clients.toLocaleString()} />
                    <Stat
                        label="Service accounts"
                        value={usage.serviceAccounts.toLocaleString()}
                    />
                </div>
            </section>

            <Panel
                title="Members"
                flush
                className="mb-5"
                action={
                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                        {members.length < memberTotal
                            ? `Showing ${members.length} of ${memberTotal}`
                            : `${memberTotal} total`}
                    </span>
                }
            >
                {members.length === 0 ? (
                    <div
                        className="px-5 py-8 text-center text-sm"
                        style={{ color: 'var(--faint)' }}
                    >
                        No members in this organization yet — nobody can sign in to it until
                        somebody is invited or provisioned in.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <Table caption={`The people in ${organization.name}.`}>
                            <thead>
                                <tr>
                                    <Th>User</Th>
                                    <Th>Role</Th>
                                    <Th>Status</Th>
                                    <Th className="text-right">Support</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {members.map((member) => (
                                    <tr key={member.userId}>
                                        <Td>
                                            <p className="font-medium">
                                                {member.email ?? member.name ?? member.userId}
                                            </p>
                                            {member.name !== null && member.email !== null && (
                                                <p
                                                    className="text-xs"
                                                    style={{ color: 'var(--faint)' }}
                                                >
                                                    {member.name}
                                                </p>
                                            )}
                                        </Td>
                                        <Td className="capitalize whitespace-nowrap">
                                            {member.role}
                                        </Td>
                                        <Td className="whitespace-nowrap">
                                            <Pill tone={memberTone(member.status)}>
                                                <span className="capitalize">
                                                    {member.status}
                                                </span>
                                            </Pill>
                                        </Td>
                                        <Td className="text-right">
                                            {member.impersonateHref === null ? (
                                                <span
                                                    className="text-xs"
                                                    style={{ color: 'var(--faint)' }}
                                                >
                                                    Not impersonable
                                                </span>
                                            ) : (
                                                <Impersonate member={member} />
                                            )}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                )}
            </Panel>

            <div className="grid gap-5 lg:grid-cols-2 mb-5">
                <Panel>
                    <h2 className="text-sm font-semibold mb-3">SSO connection</h2>
                    {sso === null ? (
                        <p className="text-sm" style={{ color: 'var(--faint)' }}>
                            No SSO connection configured.
                        </p>
                    ) : (
                        <>
                            <div className="flex items-center gap-2 mb-2">
                                <span className="text-sm font-medium">{sso.name}</span>
                                <Pill tone={sso.status === 'active' ? 'success' : 'neutral'}>
                                    <span className="capitalize">{sso.status}</span>
                                </Pill>
                            </div>
                            <p
                                className="text-xs uppercase tracking-wide"
                                style={{ color: 'var(--faint)' }}
                            >
                                Protocol
                            </p>
                            <p className="text-sm uppercase">{sso.type}</p>
                        </>
                    )}
                </Panel>

                <Panel>
                    <h2 className="text-sm font-semibold mb-3">Verified domains</h2>
                    {domains.length === 0 ? (
                        <p className="text-sm" style={{ color: 'var(--faint)' }}>
                            No domains registered.
                        </p>
                    ) : (
                        domains.map((domain) => (
                            <div
                                key={domain.domain}
                                className="flex items-center justify-between py-1.5 border-b last:border-0"
                                style={{ borderColor: 'var(--border)' }}
                            >
                                <span className="text-sm mono">{domain.domain}</span>
                                <span className="flex items-center gap-2">
                                    {domain.capture && <Pill dot={false}>Capture</Pill>}
                                    {domain.verifiedAt === null ? (
                                        <Pill tone="warning">Pending</Pill>
                                    ) : (
                                        <Pill tone="success">Verified</Pill>
                                    )}
                                </span>
                            </div>
                        ))
                    )}
                </Panel>
            </div>

            <Panel title="Entitlements" flush className="mb-5">
                {entitlements.length === 0 ? (
                    <div
                        className="px-5 py-8 text-center text-sm"
                        style={{ color: 'var(--faint)' }}
                    >
                        No entitlements set for this organization — it runs on the
                        deployment&rsquo;s defaults.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <Table caption={`Entitlements in force for ${organization.name}.`}>
                            <thead>
                                <tr>
                                    <Th>Key</Th>
                                    <Th>Value</Th>
                                    <Th>Enforcement</Th>
                                    <Th>Source</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {entitlements.map((entitlement) => (
                                    <tr key={entitlement.key}>
                                        <Td className="mono">{entitlement.key}</Td>
                                        <Td
                                            className="mono text-xs"
                                            style={{ color: 'var(--muted)' }}
                                        >
                                            {entitlement.value}
                                        </Td>
                                        <Td className="whitespace-nowrap">{entitlement.mode}</Td>
                                        <Td className="capitalize whitespace-nowrap">
                                            {entitlement.source}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                )}
            </Panel>

            <Panel title="Recent activity" flush>
                {recent.length === 0 ? (
                    <div
                        className="px-5 py-8 text-center text-sm"
                        style={{ color: 'var(--faint)' }}
                    >
                        No recent activity recorded for this tenant. Sign-ins, role changes and
                        connection edits appear here as they happen.
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <Table caption={`The newest entries on ${organization.name}'s trail.`}>
                            <thead>
                                <tr>
                                    <Th>Action</Th>
                                    <Th>Actor</Th>
                                    <Th>Recorded</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {recent.map((event, index) => (
                                    // The trail has no id of its own on the wire, and two
                                    // entries can share an action and a second — so the
                                    // position in this fixed, non-reorderable list is the
                                    // identity.
                                    // eslint-disable-next-line react/no-array-index-key
                                    <tr key={`${event.action}-${index}`}>
                                        <Td className="mono">{event.action}</Td>
                                        <Td className="text-xs" style={{ color: 'var(--muted)' }}>
                                            {event.actorType}
                                            {event.actorId !== null && ` · ${event.actorId}`}
                                        </Td>
                                        <Td
                                            className="whitespace-nowrap text-xs"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {event.recordedAt ?? '—'}
                                        </Td>
                                    </tr>
                                ))}
                            </tbody>
                        </Table>
                    </div>
                )}
            </Panel>

            {/*
                Same confirmation as the Organizations list, and for the same reason: a bare
                click here signs out every member of a live tenant.
            */}
            <Dialog
                open={confirming}
                onOpenChange={setConfirming}
                title={`${organization.active ? 'Suspend' : 'Reactivate'} ${organization.name}?`}
                description={
                    organization.active
                        ? `Its ${plural(memberTotal, 'member')} can no longer sign in to this tenant, and any app relying on it stops authenticating them. Sub-organizations are not suspended with it. You can reactivate it here.`
                        : 'Its members can sign in again immediately.'
                }
                footer={
                    <>
                        <Button onClick={() => setConfirming(false)}>Cancel</Button>
                        <Button
                            variant={organization.active ? 'danger' : 'primary'}
                            onClick={() => {
                                setConfirming(false);
                                router.post(toggleHref, {}, { preserveScroll: true });
                            }}
                        >
                            {organization.active ? 'Suspend' : 'Reactivate'}
                        </Button>
                    </>
                }
            />
        </>
    );
}

/**
 * Step into this member's session for support.
 *
 * Heavily rail-guarded: the console is read-only while impersonating, credential changes
 * are blocked, a justification is REQUIRED — which is why the reason is a field beside the
 * button rather than a prompt after it — and the session self-terminates after 30 minutes.
 */
function Impersonate({ member }: { member: MemberRow }) {
    const form = useForm({ organization: member.organizationId, reason: '' });
    const who = member.email ?? member.userId;

    return (
        <form
            className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end"
            onSubmit={(event) => {
                event.preventDefault();

                if (member.impersonateHref === null) {
                    return;
                }

                form.post(member.impersonateHref);
            }}
        >
            <Input
                name="reason"
                required
                maxLength={200}
                placeholder="Reason for access"
                className="text-xs"
                style={{ maxWidth: '12rem' }}
                aria-label={`Reason for impersonating ${who}`}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            <Button type="submit" size="sm" loading={form.processing}>
                Impersonate
            </Button>
        </form>
    );
}

Organization.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
