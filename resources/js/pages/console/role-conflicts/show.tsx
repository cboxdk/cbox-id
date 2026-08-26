import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Badge, Button, ConfirmDelete, Icon, Kv, KvList, Panel, Pill } from '@/ui';

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
    const [confirming, setConfirming] = useState(false);

    return (
        <div className="space-y-6">
            <div>
                <Link
                    href={indexHref}
                    className="text-sm inline-flex items-center gap-1"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    <Icon
                        name="chevron"
                        className="w-3.5 h-3.5"
                        style={{ transform: 'rotate(90deg)' }}
                    />
                    Role conflicts
                </Link>
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{rule.name}</h1>
                    <Pill tone={rule.active ? 'success' : 'neutral'}>
                        {rule.active ? 'Active' : 'Inactive'}
                    </Pill>
                </div>
                <p className="mt-1 text-sm mono" style={{ color: 'var(--faint)' }}>
                    {rule.id}
                </p>
            </div>

            <Panel title="Details">
                <KvList>
                    <Kv label="Applies to" prose>
                        <Badge>{rule.owner ?? 'Every organization in this environment'}</Badge>
                    </Kv>
                    <Kv label="Conflicting roles" prose>
                        <span className="flex flex-wrap gap-1.5">
                            {rule.roles.map((role) => (
                                <Badge key={role}>{role}</Badge>
                            ))}
                        </span>
                    </Kv>
                    <Kv label="Description" prose>
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
                title="Who already holds a conflicting pair"
                description={
                    scannable
                        ? 'Evaluates this rule against every current grant in the organization.'
                        : undefined
                }
                action={
                    scannable ? (
                        <Button asChild size="sm" className="shrink-0">
                            <Link href={scanHref} preserveScroll>
                                {scanned ? 'Scan again' : 'Scan now'}
                            </Link>
                        </Button>
                    ) : undefined
                }
            >
                {!scannable ? (
                    // A different answer from "no conflicts": there is no organization to
                    // walk, so nothing has been checked.
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        This rule binds every organization here. Choose one in the console header to
                        evaluate it against that organization's grants.
                    </p>
                ) : !scanned ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        Not scanned yet. A scan reads every grant in the organization, so it runs
                        when you ask for it rather than every time this page opens.
                    </p>
                ) : violations.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        No conflicts — nobody holds a pair this rule forbids.
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
                                    Holds{' '}
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
                        title={rule.active ? 'Deactivate rule' : 'Activate rule'}
                        description={
                            rule.active
                                ? 'Grants that would break this rule are allowed again. Nothing already granted changes.'
                                : 'Grants that would break this rule are blocked from now on. Anyone who already holds the pair keeps it — the scan above is how you find them.'
                        }
                    >
                        <Button
                            size="sm"
                            onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                        >
                            {rule.active ? 'Deactivate' : 'Activate'}
                        </Button>
                    </Panel>

                    <Panel
                        title="Remove rule"
                        description="The conflict stops being enforced. Grants it was blocking become possible again."
                    >
                        <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                            Remove rule
                        </Button>
                    </Panel>
                </>
            ) : (
                <Panel title="Managed for the environment">
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        This rule binds every organization in the environment. It is shown here
                        because it constrains yours — your operator manages it.
                    </p>
                </Panel>
            )}

            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={rule.name}
                verb="Remove"
                consequence="The conflict stops being enforced immediately, and grants it was blocking become possible again. This cannot be undone."
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

RoleConflictDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
