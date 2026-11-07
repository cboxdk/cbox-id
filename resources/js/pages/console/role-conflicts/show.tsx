import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [confirming, setConfirming] = useState(false);

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={consoleT('console.role_conflicts_1f4cf2a5')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{rule.name}</h1>
                    <Pill tone={rule.active ? 'success' : 'neutral'}>
                        {rule.active
                            ? consoleText('console.active_92340695')
                            : consoleText('console.inactive_ac7c949f')}
                    </Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {rule.id}
                </p>
            </div>

            <Panel title={consoleT('console.details_45989de4')}>
                <KvList>
                    <Kv label={consoleT('console.applies_to_6687458b')} prose>
                        <Badge>
                            {rule.owner ??
                                consoleRuntimeText(
                                    'console.every_organization_in_this_environment_6250aa57',
                                )}
                        </Badge>
                    </Kv>
                    <Kv label={consoleT('console.conflicting_roles_60210f0e')} prose>
                        <span className="flex flex-wrap gap-1.5">
                            {rule.roles.map((role) => (
                                <Badge key={role}>{role}</Badge>
                            ))}
                        </span>
                    </Kv>
                    <Kv label={consoleT('console.description_526e0087')} prose>
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
                title={consoleT('console.who_already_holds_a_conflicting_pair_a2e23702')}
                description={
                    scannable
                        ? consoleText(
                              'console.evaluates_this_rule_against_every_current_grant_in_the_404bdc65',
                          )
                        : undefined
                }
                action={
                    scannable ? (
                        <Button asChild size="sm" className="shrink-0">
                            <Link href={scanHref} preserveScroll>
                                {scanned
                                    ? consoleText('console.scan_again_3d04584d')
                                    : consoleText('console.scan_now_c01d65df')}
                            </Link>
                        </Button>
                    ) : undefined
                }
            >
                {!scannable ? (
                    // A different answer from "no conflicts": there is no organization to
                    // walk, so nothing has been checked.
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.this_rule_binds_every_organization_here_choose_one_in_t_9d1bbde3',
                        )}
                    </p>
                ) : !scanned ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.not_scanned_yet_a_scan_reads_every_grant_in_the_organiz_762adfd4',
                        )}
                    </p>
                ) : violations.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.no_conflicts_nobody_holds_a_pair_this_rule_forbids_469b152b',
                        )}
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
                                    {consoleRich('console.holds_part1_part2_6b1a0140', {
                                        part1: ' ',
                                        part2: violation.roles.map((role) => (
                                            <Badge key={role}>{role}</Badge>
                                        )),
                                    })}
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
                                ? consoleText('console.deactivate_rule_b819d737')
                                : consoleText('console.activate_rule_71312baf')
                        }
                        description={
                            rule.active
                                ? consoleText(
                                      'console.grants_that_would_break_this_rule_are_allowed_again_not_4ded361b',
                                  )
                                : consoleText(
                                      'console.grants_that_would_break_this_rule_are_blocked_from_now_1454bf59',
                                  )
                        }
                    >
                        <Button
                            size="sm"
                            onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                        >
                            {rule.active
                                ? consoleText('console.deactivate_fb1e6fa5')
                                : consoleText('console.activate_24433c70')}
                        </Button>
                    </Panel>

                    <Panel
                        title={consoleT('console.remove_rule_3fab7379')}
                        description={consoleT(
                            'console.the_conflict_stops_being_enforced_grants_it_was_blockin_37391474',
                        )}
                    >
                        <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                            {consoleT('console.remove_rule_3fab7379')}
                        </Button>
                    </Panel>
                </>
            ) : (
                <Panel title={consoleT('console.managed_for_the_environment_502c8ccb')}>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.this_rule_binds_every_organization_in_the_environment_i_91928f48',
                        )}
                    </p>
                </Panel>
            )}

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={rule.name}
                verb={consoleShortText('console.remove_c3812fc4')}
                consequence={consoleUiText(
                    'console.the_conflict_stops_being_enforced_immediately_and_grant_88f978ef',
                )}
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

RoleConflictDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
