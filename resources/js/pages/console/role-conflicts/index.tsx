import { Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Badge, Button, EmptyState, Icon, Input, PageHeader, Panel, Pill } from '@/ui';

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
    /** False when no organization is chosen — so no scan has run, which is not "clean". */
    organizationChosen: boolean;
    violations: Violation[];
    createHref: string;
}>;

function listHref(search: string): string {
    return search === ''
        ? window.location.pathname
        : `${window.location.pathname}?q=${encodeURIComponent(search)}`;
}

export default function RoleConflictsIndex({
    help,
    rules,
    search,
    organizationChosen,
    violations,
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
                description="Declare the roles that must never sit with the same person — segregation of duties — and see who already holds a conflicting pair."
                actions={
                    <Button asChild variant="primary" className="shrink-0">
                        <Link href={createHref}>
                            <Icon name="plus" className="w-4 h-4" />
                            New rule
                        </Link>
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name"
                    aria-label="Search role conflicts"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so the count is the only thing that can report the filter
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {rules.length} {rules.length === 1 ? 'rule' : 'rules'} found.
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
                            title={`No rules match “${search}”`}
                            description="No role conflict matches that name. Try a different search."
                        />
                    ) : (
                        <EmptyState
                            icon="shield"
                            title="No rules yet"
                            help={help}
                            description="A rule names two or more roles that must never sit with the same person — whoever raises a payment should not also approve it."
                            steps={[
                                'Name the conflict the way your auditor would — “Raise payment vs. approve payment”.',
                                'Pick the roles that must not be combined.',
                                'Save it: new grants that would break the rule are blocked, and anyone who already holds the pair is listed for you.',
                            ]}
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        New rule
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

            <Violations chosen={organizationChosen} violations={violations} />
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
                    <Badge>{rule.owner ?? 'Environment-wide'}</Badge>
                    {rule.roles.map((role) => (
                        <Badge key={role}>{role}</Badge>
                    ))}
                </div>
            </div>

            <Pill tone={rule.active ? 'success' : 'neutral'}>
                {rule.active ? 'Active' : 'Inactive'}
            </Pill>

            {rule.mayChange ? (
                <Button
                    size="sm"
                    className="shrink-0"
                    onClick={() => router.post(rule.toggleHref, {}, { preserveScroll: true })}
                >
                    {rule.active ? 'Deactivate' : 'Activate'}
                </Button>
            ) : (
                // Not offered and refused: this rule binds every organization here, and an
                // administrator who could switch it off could then grant themselves the
                // very pair it forbids.
                <span className="text-xs shrink-0" style={{ color: 'var(--faint)' }}>
                    Managed for the environment
                </span>
            )}
        </div>
    );
}

/**
 * The detective half: people who already hold a forbidden pair.
 *
 * "CHOOSE AN ORGANIZATION" IS NOT "NO CONFLICTS". A scan needs an organization to walk, so
 * with none chosen nothing has been checked — and reporting a clean result for a scan that
 * never ran is the more dangerous of the two answers.
 */
function Violations({ chosen, violations }: { chosen: boolean; violations: Violation[] }) {
    return (
        <div className="mt-8">
            <h2 className="cbx-section-title mb-3">Violations</h2>

            {!chosen ? (
                <Panel>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        Choose an organization above to scan it for people who already hold a
                        conflicting pair. Nothing has been checked yet.
                    </p>
                </Panel>
            ) : violations.length === 0 ? (
                <Panel>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        No conflicts detected — nobody in this organization holds a forbidden pair.
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
                Holds conflicting roles:{' '}
                {violation.roles.map((role) => (
                    <Badge key={role}>{role}</Badge>
                ))}
            </p>
        </div>
    );
}

RoleConflictsIndex.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
