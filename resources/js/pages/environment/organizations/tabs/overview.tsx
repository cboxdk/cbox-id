import { useTranslator } from '@/i18n';
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
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    return (
        <div className="space-y-6">
            <CertificateWarnings warnings={certificateWarnings} />

            <div className="grid gap-4 sm:grid-cols-2">
                <Stat
                    icon="members"
                    label={tc('console.members_1044a4c0')}
                    value={counts.members.toLocaleString(locale)}
                    href={hrefs.members}
                />
                <Stat
                    icon="mail"
                    label={tc('console.pending_invitations_76198e63')}
                    value={counts.invitations.toLocaleString(locale)}
                    href={hrefs.invitations}
                />
            </div>

            <Panel
                title={tc('console.setup_7013af4c')}
                description={tc('console.what_this_organization_s_sign_in_is_wired_to_cde71601')}
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
                                        <Pill tone="success">{tc('console.ux_configured')}</Pill>
                                    ) : step.available ? (
                                        <Pill>{tc('console.ux_not_configured')}</Pill>
                                    ) : (
                                        <Pill tone="warning">
                                            {tc('console.not_in_plan_0f69a710')}
                                        </Pill>
                                    )}
                                </div>
                                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                    {step.available
                                        ? step.detail
                                        : tc(
                                              'console.this_organization_s_plan_does_not_include_it_24125cc4',
                                          )}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            </Panel>

            {portalLinks.length > 0 && <PortalLinks links={portalLinks} />}

            <Panel
                title={tc('console.recent_activity_6cb44b56')}
                description={tc('console.the_newest_entries_on_this_organization_s_au_a1dd5ccc')}
                action={
                    <Link href={hrefs.audit} className="text-sm">
                        {tc('console.audit_log_e4d36f9a')}
                    </Link>
                }
            >
                {recent.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--faint)' }}>
                        {tc('console.nothing_has_been_recorded_for_this_organizat_0571948e')}
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
                                        {new Date(entry.recordedAt).toLocaleString(locale)}
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
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    const [revoking, setRevoking] = useState<string | null>(null);

    return (
        <Panel
            title={tc('console.admin_portal_links_e941273c')}
            description={tc('console.links_handed_to_this_organization_s_it_admin_65719b81')}
        >
            {links.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--faint)' }}>
                    {tc('console.no_link_is_outstanding_cdd62efb')}
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
                                        <Pill tone="warning">{tc('console.in_use_750af77a')}</Pill>
                                    ) : (
                                        <Pill>{tc('console.not_opened_3c1918bf')}</Pill>
                                    )}
                                </div>
                                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                    {link.emailedTo !== null
                                        ? tc('console.mailed_to_value0_2da6e216', {
                                              value0: link.emailedTo,
                                          })
                                        : tc('console.copied_by_hand_3771c413')}
                                    {link.createdAt !== null && (
                                        <>
                                            {tc('console.created_d8cbb77e')}
                                            <time
                                                dateTime={link.createdAt}
                                                title={absoluteTime(link.createdAt, locale)}
                                            >
                                                {relativeTime(link.createdAt, locale)}
                                            </time>
                                        </>
                                    )}
                                    {!link.inUse && (
                                        <>
                                            {tc('console.expires_886eec4c')}
                                            <time
                                                dateTime={link.expiresAt}
                                                title={absoluteTime(link.expiresAt, locale)}
                                            >
                                                {relativeTime(link.expiresAt, locale)}
                                            </time>
                                        </>
                                    )}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="danger"
                                loading={revoking === link.id}
                                aria-label={tc('console.revoke_the_value0_link_50b2d0ac', {
                                    value0: link.intents.join(', '),
                                })}
                                onClick={() =>
                                    router.delete(link.revokeHref, {
                                        preserveScroll: true,
                                        onStart: () => setRevoking(link.id),
                                        onFinish: () => setRevoking(null),
                                    })
                                }
                            >
                                {tc('console.revoke_87e6d00b')}
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

OrganizationOverview.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
