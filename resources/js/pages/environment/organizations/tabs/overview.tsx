import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { CertificateWarning, HelpContent, PageProps } from '@/types';
import { CertificateWarnings, Help, Icon, Panel, Pill, Stat } from '@/ui';

interface SetupStep {
    key: string;
    label: string;
    done: boolean;
    /** False when the organization's plan does not include it — said, not offered. */
    available: boolean;
    detail: string;
    href: string;
}

interface RecentEntry {
    id: string;
    action: string;
    phrase: string;
    actorName: string | null;
    recordedAt: string | null;
}

type Props = PageProps<{
    help: HelpContent;
    setup: SetupStep[];
    counts: { members: number; invitations: number };
    recent: RecentEntry[];
    hrefs: { members: string; invitations: string; audit: string };
    /** SAML connections whose signing certificates stop working within 30 days. */
    certificateWarnings: CertificateWarning[];
}>;

/**
 * AN ORGANIZATION › OVERVIEW — is it set up, and what happened to it lately.
 *
 * The three setup questions are answered from the system's own state rather than from a
 * checklist anybody ticked, and each links to the tab where it is done. The header and tabs
 * are the layout's (`../frame.tsx`).
 */
export default function OrganizationOverview({
    help,
    setup,
    counts,
    recent,
    hrefs,
    certificateWarnings,
}: Props) {
    return (
        <div className="space-y-6">
            <CertificateWarnings warnings={certificateWarnings} />

            <div className="grid gap-4 sm:grid-cols-2">
                <Stat
                    icon="members"
                    label="Members"
                    value={counts.members.toLocaleString()}
                    href={hrefs.members}
                />
                <Stat
                    icon="mail"
                    label="Pending invitations"
                    value={counts.invitations.toLocaleString()}
                    href={hrefs.invitations}
                />
            </div>

            <Panel
                title="Setup"
                description="What this organization's sign-in is wired to, read from what is actually configured."
                action={<Help help={help} />}
            >
                <ul className="space-y-3">
                    {setup.map((step) => (
                        <li key={step.key} className="flex items-start gap-3">
                            <span
                                className="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full"
                                style={{
                                    background: step.done
                                        ? 'var(--success-soft)'
                                        : 'var(--secondary)',
                                    color: step.done
                                        ? 'var(--success-strong)'
                                        : 'var(--muted-foreground)',
                                }}
                                aria-hidden="true"
                            >
                                {step.done ? <Icon name="check" className="w-3.5 h-3.5" /> : null}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <Link href={step.href} className="font-medium">
                                        {step.label}
                                    </Link>
                                    {step.done ? (
                                        <Pill tone="success">Done</Pill>
                                    ) : step.available ? (
                                        <Pill>Not yet</Pill>
                                    ) : (
                                        <Pill tone="warning">Not in plan</Pill>
                                    )}
                                </div>
                                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                    {step.available
                                        ? step.detail
                                        : "This organization's plan does not include it."}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            </Panel>

            <Panel
                title="Recent activity"
                description="The newest entries on this organization's audit log."
                action={
                    <Link href={hrefs.audit} className="text-sm">
                        Audit log
                    </Link>
                }
            >
                {recent.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        Nothing has been recorded for this organization yet.
                    </p>
                ) : (
                    <ul className="space-y-2">
                        {recent.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex items-baseline justify-between gap-3 flex-wrap"
                            >
                                <span className="min-w-0">
                                    <span className="font-medium">{entry.phrase}</span>
                                    {entry.actorName !== null && (
                                        <span style={{ color: 'var(--muted-foreground)' }}>
                                            {' '}
                                            · {entry.actorName}
                                        </span>
                                    )}
                                    <span className="sr-only"> ({entry.action})</span>
                                </span>
                                {entry.recordedAt !== null && (
                                    <time
                                        dateTime={entry.recordedAt}
                                        className="text-xs"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {new Date(entry.recordedAt).toLocaleString()}
                                    </time>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </div>
    );
}

OrganizationOverview.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
