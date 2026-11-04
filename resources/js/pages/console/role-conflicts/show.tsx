import { useTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Badge, Breadcrumb, Button, ConfirmDelete, Kv, KvList, Panel, Pill } from '@/ui';

interface Violation {
    policy: string;
    subject: string;
    subjectId: string;
    roles: string[];
}

type Props = PageProps<{
    rule: {
        id: string;
        name: string;
        description: string | null;
        /** The organization it belongs to, or null when it binds the whole environment. */
        owner: string | null;
        roles: string[];
        active: boolean;
    };
    mayChange: boolean;
    /** False when there is no organization to evaluate against — not "no conflicts". */
    scannable: boolean;
    scanned: boolean;
    violations: Violation[];
    indexHref: string;
    scanHref: string;
    urls: { toggle: string; destroy: string };
}>;

export default function RoleConflictDetail({
    rule,
    mayChange,
    scannable,
    scanned,
    violations,
    indexHref,
    scanHref,
    urls,
}: Props) {
    const { t: tc } = useTranslator();

    const [confirming, setConfirming] = useState(false);

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={tc('console.role_conflicts_1f4cf2a5')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{rule.name}</h1>
                    <Pill tone={rule.active ? 'success' : 'neutral'}>
                        {rule.active
                            ? tc('console.active_92340695')
                            : tc('console.inactive_ac7c949f')}
                    </Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {rule.id}
                </p>
            </div>

            <Panel title={tc('console.details_45989de4')}>
                <KvList>
                    <Kv label={tc('console.applies_to_6687458b')} prose>
                        <Badge>
                            {rule.owner ??
                                tc('console.every_organization_in_this_environment_6250aa57')}
                        </Badge>
                    </Kv>
                    <Kv label={tc('console.conflicting_roles_60210f0e')} prose>
                        <span className="flex flex-wrap gap-1.5">
                            {rule.roles.map((role) => (
                                <Badge key={role}>{role}</Badge>
                            ))}
                        </span>
                    </Kv>
                    <Kv label={tc('console.description_526e0087')} prose>
                        {rule.description ?? '—'}
                    </Kv>
                </KvList>
            </Panel>

            {/*
                THE DETECTIVE HALF, ASKED FOR RATHER THAN RUN ON EVERY LOAD. A scan walks
                every grant in the organization; doing that on page load made opening a
                rule cost the size of the tenant, for a report most visits never read.
            */}
            <Panel
                title={tc('console.who_already_holds_a_conflicting_pair_a2e23702')}
                description={
                    scannable
                        ? tc('console.evaluates_this_rule_against_every_current_gr_404bdc65')
                        : undefined
                }
                action={
                    scannable ? (
                        <Button asChild size="sm" className="shrink-0">
                            <Link href={scanHref} preserveScroll>
                                {scanned
                                    ? tc('console.scan_again_3d04584d')
                                    : tc('console.scan_now_c01d65df')}
                            </Link>
                        </Button>
                    ) : undefined
                }
            >
                {!scannable ? (
                    // A different answer from "no conflicts": there is no organization to
                    // walk, so nothing has been checked.
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.this_rule_binds_every_organization_here_choo_9d1bbde3')}
                    </p>
                ) : !scanned ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.not_scanned_yet_a_scan_reads_every_grant_in__762adfd4')}
                    </p>
                ) : violations.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.no_conflicts_nobody_holds_a_pair_this_rule_f_469b152b')}
                    </p>
                ) : (
                    <div className="space-y-3">
                        {violations.map((violation) => (
                            <div
                                key={violation.subjectId}
                                className="rounded-lg border p-3"
                                style={{
                                    borderColor:
                                        'color-mix(in oklch, var(--warning) 45%, transparent)',
                                }}
                            >
                                <p className="font-medium">{violation.subject}</p>
                                <p
                                    className="mt-1 text-sm flex flex-wrap items-center gap-1.5"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {tc('console.holds_7f1d4110')}{' '}
                                    {violation.roles.map((role) => (
                                        <Badge key={role}>{role}</Badge>
                                    ))}
                                </p>
                            </div>
                        ))}
                    </div>
                )}
            </Panel>

            {mayChange ? (
                <>
                    <Panel
                        title={
                            rule.active
                                ? tc('console.deactivate_rule_b819d737')
                                : tc('console.activate_rule_71312baf')
                        }
                        description={
                            rule.active
                                ? tc(
                                      'console.grants_that_would_break_this_rule_are_allowe_4ded361b',
                                  )
                                : tc(
                                      'console.grants_that_would_break_this_rule_are_blocke_1454bf59',
                                  )
                        }
                    >
                        <Button
                            size="sm"
                            onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                        >
                            {rule.active
                                ? tc('console.deactivate_fb1e6fa5')
                                : tc('console.activate_24433c70')}
                        </Button>
                    </Panel>

                    <Panel
                        title={tc('console.remove_rule_3fab7379')}
                        description={tc(
                            'console.the_conflict_stops_being_enforced_grants_it__37391474',
                        )}
                    >
                        <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                            {tc('console.remove_rule_3fab7379')}
                        </Button>
                    </Panel>
                </>
            ) : (
                <Panel title={tc('console.managed_for_the_environment_502c8ccb')}>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.this_rule_binds_every_organization_in_the_en_91928f48')}
                    </p>
                </Panel>
            )}

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={rule.name}
                verb="Remove"
                consequence={tc('console.the_conflict_stops_being_enforced_immediatel_88f978ef')}
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

RoleConflictDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
