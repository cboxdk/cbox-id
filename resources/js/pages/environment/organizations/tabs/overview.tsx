import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { absoluteTime, relativeTime } from '@/lib/time';
import type { CertificateWarning, HelpContent, PageProps } from '@/types';
import { Button, CertificateWarnings, Help, Icon, Panel, Pill, Stat } from '@/ui';

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

/** An Admin Portal link that still opens something — never its URL, which was shown once. */
interface OutstandingPortalLink {
    id: string;
    /** What it opens, as the "Admin Portal link" dialog names them. */
    intents: string[];
    /** Opened, and its setup session may still be running. */
    inUse: boolean;
    emailedTo: string | null;
    createdAt: string | null;
    expiresAt: string;
    revokeHref: string;
}

type Props = PageProps<{
    help: HelpContent;
    setup: SetupStep[];
    counts: { members: number; invitations: number };
    recent: RecentEntry[];
    hrefs: { members: string; invitations: string; audit: string };
    /** SAML connections whose signing certificates stop working within 30 days. */
    certificateWarnings: CertificateWarning[];
    portalLinks: OutstandingPortalLink[];
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
    portalLinks,
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

            <PortalLinks links={portalLinks} />

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

/**
 * THE ADMIN PORTAL LINKS STILL OUT THERE — not yet opened, or opened with a setup session
 * that may still be running — and the way to take each back. A link is a credential handed
 * to somebody outside this console; one mailed to the wrong address has to be visible to be
 * withdrawn. Revoking one that is in use ends that session on its next click.
 */
function PortalLinks({ links }: { links: OutstandingPortalLink[] }) {
    const [revoking, setRevoking] = useState<string | null>(null);

    return (
        <Panel
            title="Admin Portal links"
            description="Links handed to this organization's IT administrator that still open something. Revoke one and it stops working at once — what was already set up through it stays."
        >
            {links.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--faint)' }}>
                    No link is outstanding.
                </p>
            ) : (
                <ul className="space-y-3">
                    {links.map((link) => (
                        <li
                            key={link.id}
                            className="flex items-start justify-between gap-3 flex-wrap"
                        >
                            <div className="min-w-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="font-medium">{link.intents.join(', ')}</span>
                                    {link.inUse ? (
                                        <Pill tone="warning">In use</Pill>
                                    ) : (
                                        <Pill>Not opened</Pill>
                                    )}
                                </div>
                                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                    {link.emailedTo !== null
                                        ? `Mailed to ${link.emailedTo}`
                                        : 'Copied by hand'}
                                    {link.createdAt !== null && (
                                        <>
                                            {' · created '}
                                            <time
                                                dateTime={link.createdAt}
                                                title={absoluteTime(link.createdAt)}
                                            >
                                                {relativeTime(link.createdAt)}
                                            </time>
                                        </>
                                    )}
                                    {!link.inUse && (
                                        <>
                                            {' · expires '}
                                            <time
                                                dateTime={link.expiresAt}
                                                title={absoluteTime(link.expiresAt)}
                                            >
                                                {relativeTime(link.expiresAt)}
                                            </time>
                                        </>
                                    )}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="danger"
                                loading={revoking === link.id}
                                aria-label={`Revoke the ${link.intents.join(', ')} link`}
                                onClick={() =>
                                    router.delete(link.revokeHref, {
                                        preserveScroll: true,
                                        onStart: () => setRevoking(link.id),
                                        onFinish: () => setRevoking(null),
                                    })
                                }
                            >
                                Revoke
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

OrganizationOverview.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
