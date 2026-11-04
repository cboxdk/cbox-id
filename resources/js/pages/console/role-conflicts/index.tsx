import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

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
                description={tc('console.declare_the_roles_that_must_never_sit_with_t_00379b41')}
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            {tc('console.new_rule_99cd24ef')}
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
                    placeholder={tc('console.search_by_name_4ae2b333')}
                    aria-label={tc('console.search_role_conflicts_639c006f')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {rules.length}{' '}
                    {rules.length === 1
                        ? tc('console.rule_7369949b')
                        : tc('console.rules_6c621d1a')}
                    {tc('console.found_599e25a3')}
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
                            title={tc('console.no_rules_match_value0_ec9ee4d7', { value0: search })}
                            description={tc(
                                'console.no_role_conflict_matches_that_name_try_a_dif_08d99745',
                            )}
                        />
                    ) : (
                        <EmptyState
                            icon="shield"
                            equivalent="sod_policies.create"
                            title={tc('console.no_rules_yet_d29cf782')}
                            help={help}
                            description={tc(
                                'console.a_rule_names_two_or_more_roles_that_must_nev_376dbd9e',
                            )}
                            steps={[
                                tc('console.name_the_conflict_the_way_your_auditor_would_b9337c40'),
                                tc('console.pick_the_roles_that_must_not_be_combined_fc41a76e'),
                                tc('console.save_it_new_grants_that_would_break_the_rule_1ec3f0d3'),
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        {tc('console.new_rule_99cd24ef')}
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
    const { t: tc } = useTranslator();

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
                    <Badge>{rule.owner ?? tc('console.environment_wide_cd1204b6')}</Badge>
                    {rule.roles.map((role) => (
                        <Badge key={role}>{role}</Badge>
                    ))}
                </div>
            </div>

            <Pill tone={rule.active ? 'success' : 'neutral'}>
                {rule.active ? tc('console.active_92340695') : tc('console.inactive_ac7c949f')}
            </Pill>

            {rule.mayChange ? (
                <Button
                    size="sm"
                    className="shrink-0"
                    onClick={() => router.post(rule.toggleHref, {}, { preserveScroll: true })}
                >
                    {rule.active
                        ? tc('console.deactivate_fb1e6fa5')
                        : tc('console.activate_24433c70')}
                </Button>
            ) : (
                // Not offered and refused: this rule binds every organization here, and an
                // administrator who could switch it off could then grant themselves the
                // very pair it forbids.
                <span className="text-xs shrink-0" style={{ color: 'var(--faint)' }}>
                    {tc('console.managed_for_the_environment_502c8ccb')}
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
    const { t: tc } = useTranslator();

    return (
        <div className="mt-8">
            <h2 className="cbx-section-title mb-3">{tc('console.violations_3f752920')}</h2>

            {!scanned ? (
                <Panel>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.filter_the_list_to_one_organization_to_scan__6dbcc13b')}
                    </p>
                </Panel>
            ) : violations.length === 0 ? (
                <Panel>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {tc('console.no_conflicts_detected_nobody_in_this_organiz_b5178538')}
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
    const { t: tc } = useTranslator();

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
                {tc('console.holds_conflicting_roles_ea64430b')}{' '}
                {violation.roles.map((role) => (
                    <Badge key={role}>{role}</Badge>
                ))}
            </p>
        </div>
    );
}

RoleConflictsIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
