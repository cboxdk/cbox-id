import { useConsoleCount } from '@/i18n/count';
import { useTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import { Fragment, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Button,
    Dialog,
    Input,
    Kv,
    KvList,
    PageHeader,
    Panel,
    Pill,
    Stat,
    Table,
    Td,
    Th,
} from '@/ui';

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
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();
    const plural = useConsoleCount();

    const [confirming, setConfirming] = useState(false);

    return (
        <>
            <div className="mb-5">
                <Link
                    href={indexHref}
                    className="inline-flex items-center gap-1 text-sm"
                    style={{ color: 'var(--muted)' }}
                >
                    <span aria-hidden="true">{tc('console.larr_848fc0e7')}</span>
                    {tc('console.back_to_the_organization_tree_b6570ade')}
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
                description={tc('console.organization_detail_in_the_target_environmen_caef589f')}
                actions={
                    <Button
                        variant={organization.active ? 'danger' : 'primary'}
                        onClick={() => setConfirming(true)}
                    >
                        {organization.active
                            ? tc('console.suspend_4948e134')
                            : tc('console.reactivate_2dc7478f')}
                    </Button>
                }
            />

            <Panel className="mb-5 mt-8">
                {ancestors.length > 0 && (
                    <nav
                        aria-label={tc('console.breadcrumb_2bd873d6')}
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
                        <Pill tone="destructive">{tc('console.suspended_e392a389')}</Pill>
                    ) : organization.active ? (
                        <Pill tone="success">{tc('console.active_92340695')}</Pill>
                    ) : (
                        <Pill className="capitalize">{organization.status}</Pill>
                    )}
                </div>

                <KvList>
                    <Kv label={tc('console.slug_d15387ec')}>{organization.slug}</Kv>
                    <Kv label={tc('console.type_baaddf70')} prose>
                        <span className="capitalize">{organization.type}</span>
                    </Kv>
                    <Kv label={tc('console.members_1044a4c0')} prose>
                        {memberTotal}
                    </Kv>
                    <Kv label={tc('console.sub_organizations_df8a3f2e')} prose>
                        {childCount}
                    </Kv>
                    {organization.createdAt !== null && (
                        <Kv label={tc('console.created_d70b9e24')}>{organization.createdAt}</Kv>
                    )}
                </KvList>
            </Panel>

            <section className="mb-5">
                <h2 className="text-sm font-semibold mb-3">{tc('console.usage_8d59829c')}</h2>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <Stat
                        label={tc('console.members_1044a4c0')}
                        value={usage.members.toLocaleString(locale)}
                    />
                    <Stat
                        label={tc('console.mfa_adoption_value0_of_value1_with_mfa_52f65791', {
                            value0: usage.mfaUsers,
                            value1: usage.members,
                        })}
                        value={`${usage.mfaAdoption}%`}
                    />
                    <Stat
                        label={tc('console.active_sessions_61ad9909')}
                        value={usage.sessions.toLocaleString(locale)}
                    />
                    <Stat
                        label={tc('console.sign_ins_30d_75b71c8d')}
                        value={usage.signIns.toLocaleString(locale)}
                    />
                    <Stat
                        label={tc('console.sso_connections_2e2e48f8')}
                        value={usage.connections.toLocaleString(locale)}
                    />
                    <Stat
                        label={tc('console.verified_domains_b01c494f')}
                        value={usage.domains.toLocaleString(locale)}
                    />
                    <Stat
                        label={tc('console.api_clients_35c69da7')}
                        value={usage.clients.toLocaleString(locale)}
                    />
                    <Stat
                        label={tc('console.service_accounts_1642d9e2')}
                        value={usage.serviceAccounts.toLocaleString(locale)}
                    />
                </div>
            </section>

            <Panel
                title={tc('console.members_1044a4c0')}
                flush
                className="mb-5"
                action={
                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                        {members.length < memberTotal
                            ? tc('console.showing_value0_of_value1_c760e5a8', {
                                  value0: members.length,
                                  value1: memberTotal,
                              })
                            : tc('console.value0_total_b1c1ad38', { value0: memberTotal })}
                    </span>
                }
            >
                {members.length === 0 ? (
                    <div
                        className="px-5 py-8 text-center text-sm"
                        style={{ color: 'var(--faint)' }}
                    >
                        {tc('console.no_members_in_this_organization_yet_nobody_c_d0c78702')}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <Table caption={`The people in ${organization.name}.`}>
                            <thead>
                                <tr>
                                    <Th>{tc('console.user_b512d97e')}</Th>
                                    <Th>{tc('console.built_in_role_9f680a16')}</Th>
                                    <Th>{tc('console.status_920e413c')}</Th>
                                    <Th className="text-right">{tc('console.support_be91940b')}</Th>
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
                                                <span className="capitalize">{member.status}</span>
                                            </Pill>
                                        </Td>
                                        <Td className="text-right">
                                            {member.impersonateHref === null ? (
                                                <span
                                                    className="text-xs"
                                                    style={{ color: 'var(--faint)' }}
                                                >
                                                    {tc('console.not_impersonable_0e273ab2')}
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
                    <h2 className="text-sm font-semibold mb-3">
                        {tc('console.sso_connection_07f7aa11')}
                    </h2>
                    {sso === null ? (
                        <p className="text-sm" style={{ color: 'var(--faint)' }}>
                            {tc('console.no_sso_connection_configured_2034a4fa')}
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
                                {tc('console.protocol_cf088334')}
                            </p>
                            <p className="text-sm uppercase">{sso.type}</p>
                        </>
                    )}
                </Panel>

                <Panel>
                    <h2 className="text-sm font-semibold mb-3">
                        {tc('console.verified_domains_b01c494f')}
                    </h2>
                    {domains.length === 0 ? (
                        <p className="text-sm" style={{ color: 'var(--faint)' }}>
                            {tc('console.no_domains_registered_32acc545')}
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
                                    {domain.capture && (
                                        <Pill dot={false}>{tc('console.capture_1900b478')}</Pill>
                                    )}
                                    {domain.verifiedAt === null ? (
                                        <Pill tone="warning">{tc('console.pending_331551b0')}</Pill>
                                    ) : (
                                        <Pill tone="success">
                                            {tc('console.verified_4f783840')}
                                        </Pill>
                                    )}
                                </span>
                            </div>
                        ))
                    )}
                </Panel>
            </div>

            <Panel title={tc('console.entitlements_2cc79c9e')} flush className="mb-5">
                {entitlements.length === 0 ? (
                    <div
                        className="px-5 py-8 text-center text-sm"
                        style={{ color: 'var(--faint)' }}
                    >
                        {tc('console.no_entitlements_set_for_this_organization_it_76313cb5')}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <Table caption={`Entitlements in force for ${organization.name}.`}>
                            <thead>
                                <tr>
                                    <Th>{tc('console.key_99a52df3')}</Th>
                                    <Th>{tc('console.value_8e37953d')}</Th>
                                    <Th>{tc('console.enforcement_bda2e3e6')}</Th>
                                    <Th>{tc('console.source_0e570ca6')}</Th>
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

            <Panel title={tc('console.recent_activity_6cb44b56')} flush>
                {recent.length === 0 ? (
                    <div
                        className="px-5 py-8 text-center text-sm"
                        style={{ color: 'var(--faint)' }}
                    >
                        {tc('console.no_recent_activity_recorded_for_this_organiz_150edeb1')}
                    </div>
                ) : (
                    <div className="overflow-x-auto">
                        <Table caption={`The newest entries on ${organization.name}'s trail.`}>
                            <thead>
                                <tr>
                                    <Th>{tc('console.action_64cff131')}</Th>
                                    <Th>{tc('console.actor_449995c4')}</Th>
                                    <Th>{tc('console.recorded_c7175fa7')}</Th>
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
                        ? tc('console.its_value0_can_no_longer_sign_in_to_this_org_b832d3fa', {
                              value0: plural(memberTotal, 'member'),
                          })
                        : tc('console.its_members_can_sign_in_again_immediately_bd3004a5')
                }
                footer={
                    <>
                        <Button onClick={() => setConfirming(false)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant={organization.active ? 'danger' : 'primary'}
                            onClick={() => {
                                setConfirming(false);
                                router.post(toggleHref, {}, { preserveScroll: true });
                            }}
                        >
                            {organization.active
                                ? tc('console.suspend_4948e134')
                                : tc('console.reactivate_2dc7478f')}
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
    const { t: tc } = useTranslator();

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
                placeholder={tc('console.reason_for_access_888a7a48')}
                className="text-xs"
                style={{ maxWidth: '12rem' }}
                aria-label={tc('console.reason_for_impersonating_value0_b92309db', { value0: who })}
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
            />
            <Button type="submit" size="sm" loading={form.processing}>
                {tc('console.impersonate_f78577dc')}
            </Button>
        </form>
    );
}

Organization.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
