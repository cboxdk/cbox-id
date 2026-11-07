import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { listHref } from '@/lib/listHref';
import type { HelpContent, OrganizationFilter, PageProps } from '@/types';
import {
    Badge,
    Button,
    EmptyState,
    FilterChips,
    Icon,
    Input,
    OrganizationFilterChip,
    PageHeader,
    Panel,
    Pill,
} from '@/ui';

interface RuleRow {
    id: string;
    name: string;
    /** The organization it belongs to, or null when it binds the whole environment. */
    owner: string | null;
    roles: string[];
    active: boolean;
    mayChange: boolean;
    href: string;
    toggleHref: string;
}

interface Violation {
    policy: string;
    subject: string;
    subjectId: string;
    roles: string[];
}

type Props = PageProps<{
    help: HelpContent;
    rules: RuleRow[];
    search: string;
    /** False when the list is about no one organization — so no scan has run, which is not "clean". */
    scanned: boolean;
    violations: Violation[];
    createHref: string;
    /** The environment-wide list's Organization chip; null where the list is one organization's already. */
    organizationFilter: OrganizationFilter | null;
}>;

export default function RoleConflictsIndex({
    help,
    rules,
    search,
    scanned,
    violations,
    createHref,
    organizationFilter,
}: Props) {
    const { t: consoleRuntimeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
                description={consoleT(
                    'console.declare_the_roles_that_must_never_sit_with_the_same_per_00379b41',
                )}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            {consoleRich('console._part1_new_rule_f1ce74a6', {
                                part1: <Icon name="plus" className="w-4 h-4" />,
                            })}
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6 space-y-3">
                {organizationFilter !== null && (
                    <FilterChips>
                        <OrganizationFilterChip filter={organizationFilter} />
                    </FilterChips>
                )}
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={consoleUiText('console.search_by_name_4ae2b333')}
                    aria-label={consoleT('console.search_role_conflicts_639c006f')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {consoleRich('console.search_results_found', { count: rules.length })}
                </output>
            </div>

            <div
                className="mt-4 rounded-xl border overflow-hidden"
                style={{ borderColor: 'var(--border)' }}
            >
                {rules.length === 0 ? (
                    search !== '' ? (
                        <EmptyState
                            icon="search"
                            title={consoleFinalText('console.no_rules_match_part1_d58e559c', {
                                part1: search,
                            })}
                            description={consoleT(
                                'console.no_role_conflict_matches_that_name_try_a_different_sear_08d99745',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="shield"
                            equivalent="sod_policies.create"
                            title={consoleT('console.no_rules_yet_d29cf782')}
                            help={help}
                            description={consoleT(
                                'console.a_rule_names_two_or_more_roles_that_must_never_sit_with_376dbd9e',
                            )}
                            steps={[
                                consoleRuntimeText(
                                    'console.name_the_conflict_the_way_your_auditor_would_raise_paym_b9337c40',
                                ),
                                consoleRuntimeText(
                                    'console.pick_the_roles_that_must_not_be_combined_fc41a76e',
                                ),
                                consoleRuntimeText(
                                    'console.save_it_new_grants_that_would_break_the_rule_are_blocke_1ec3f0d3',
                                ),
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        {consoleRich('console._part1_new_rule_f1ce74a6', {
                                            part1: <Icon name="plus" className="w-4 h-4" />,
                                        })}
                                    </Link>
                                </Button>
                            }
                        />
                    )
                ) : (
                    rules.map((rule, index) => (
                        <Rule key={rule.id} rule={rule} last={index === rules.length - 1} />
                    ))
                )}
            </div>

            <Violations scanned={scanned} violations={violations} />
        </>
    );
}

/**
 * One rule.
 *
 * A row rather than one big link: the switch lives inside it, and a button nested in an
 * anchor is neither valid markup nor operable by keyboard.
 */
function Rule({ rule, last }: { rule: RuleRow; last: boolean }) {
    const { t: consoleT } = useConsoleTranslator();
    return (
        <div
            className="flex items-center gap-3 p-4"
            style={last ? undefined : { borderBottom: '1px solid var(--border)' }}
        >
            <div className="min-w-0 flex-1">
                <Link href={rule.href} className="font-medium truncate">
                    {rule.name}
                </Link>
                <div className="mt-1 flex flex-wrap items-center gap-1.5">
                    <Badge>{rule.owner ?? consoleT('console.environment_wide_cd1204b6')}</Badge>
                    {rule.roles.map((role) => (
                        <Badge key={role}>{role}</Badge>
                    ))}
                </div>
            </div>

            <Pill tone={rule.active ? 'success' : 'neutral'}>
                {rule.active
                    ? consoleT('console.active_92340695')
                    : consoleT('console.inactive_ac7c949f')}
            </Pill>

            {rule.mayChange ? (
                <Button
                    size="sm"
                    className="shrink-0"
                    onClick={() => router.post(rule.toggleHref, {}, { preserveScroll: true })}
                >
                    {rule.active
                        ? consoleT('console.deactivate_fb1e6fa5')
                        : consoleT('console.activate_24433c70')}
                </Button>
            ) : (
                // Not offered and refused: this rule binds every organization here, and an
                // administrator who could switch it off could then grant themselves the
                // very pair it forbids.
                <span className="text-xs shrink-0" style={{ color: 'var(--faint)' }}>
                    {consoleT('console.managed_for_the_environment_502c8ccb')}
                </span>
            )}
        </div>
    );
}

/**
 * The detective half: people who already hold a forbidden pair.
 *
 * "NOT SCANNED" IS NOT "NO CONFLICTS". A scan needs an organization to walk, so a list
 * about every organization has checked nothing — and reporting a clean result for a scan
 * that never ran is the more dangerous of the two answers.
 */
function Violations({ scanned, violations }: { scanned: boolean; violations: Violation[] }) {
    const { t: consoleT } = useConsoleTranslator();
    return (
        <div className="mt-8">
            <h2 className="cbx-section-title mb-3">{consoleT('console.violations_3f752920')}</h2>

            {!scanned ? (
                <Panel>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.filter_the_list_to_one_organization_to_scan_it_for_peop_6dbcc13b',
                        )}
                    </p>
                </Panel>
            ) : violations.length === 0 ? (
                <Panel>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT(
                            'console.no_conflicts_detected_nobody_in_this_organization_holds_b5178538',
                        )}
                    </p>
                </Panel>
            ) : (
                <div className="space-y-3">
                    {violations.map((violation) => (
                        <ViolationCard
                            key={`${violation.policy}-${violation.subjectId}`}
                            violation={violation}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

function ViolationCard({ violation }: { violation: Violation }) {
    const { rich: consoleRich } = useConsoleTranslator();
    return (
        <div
            className="card p-4"
            style={{ borderColor: 'color-mix(in oklch, var(--warning) 45%, transparent)' }}
        >
            <div className="flex items-center gap-2 flex-wrap">
                <Pill tone="warning">{violation.policy}</Pill>
                {/*
                    THE PERSON, not their id. The whole output of this half of the feature
                    is a list of people somebody has to go and talk to, and a ULID is not
                    somebody you can go and talk to.
                */}
                <span className="font-medium">{violation.subject}</span>
            </div>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {consoleRich('console.holds_conflicting_roles_part1_part2_23b385ca', {
                    part1: ' ',
                    part2: violation.roles.map((role) => <Badge key={role}>{role}</Badge>),
                })}
            </p>
        </div>
    );
}

RoleConflictsIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
