import { useTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Button,
    type IconName,
    Icon,
    PageHeader,
    Panel,
    Pill,
    Progress,
    type StatTone,
} from '@/ui';

interface ModuleCard {
    key: string;
    label: string;
    value: string;
    caption: string | null;
    icon: IconName;
    tone: StatTone;
    linkLabel: string | null;
    linkHref: string | null;
    /** A colour to tint the tile with, for a card whose subject IS a colour. */
    swatch: string | null;
}

interface ChecklistStep {
    key: string;
    title: string;
    href: string;
    done: boolean;
}

type Props = PageProps<{
    /** The h1, which is a greeting — the TAB says "Overview", the word the rail uses. */
    heading: string;
    greeting: string;
    isAdmin: boolean;
    role: string;
    organizationName: string | null;
    memberCount: number;
    ssoActive: boolean;
    recent: { id: string; action: string; subject: string | null; when: string | null }[];
    cards: ModuleCard[];
    checklist: {
        completed: number;
        total: number;
        percent: number;
        nextTitle: string | null;
        steps: ChecklistStep[];
        /** The setup guide, and where "hide" posts — only while there is a checklist. */
        guideHref: string;
        dismissHref: string;
    } | null;
    help: HelpContent;
    urls: {
        /** Null for anybody the audit log would refuse: the feed is an admin's. */
        audit: string | null;
        account: string;
    };
}>;

export default function Dashboard({
    heading,
    greeting,
    isAdmin,
    role,
    organizationName,
    memberCount,
    ssoActive,
    recent,
    cards,
    checklist,
    help,
    urls,
}: Props) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    return (
        <>
            <PageHeader title={heading} help={help} description={greeting} />

            {isAdmin ? (
                <>
                    {/*
                        FIRST RUN. An organization where nothing has been set up yet has an
                        empty activity feed, no apps and a member count of one — a dashboard
                        that reports accurately that nothing has happened, which is no help
                        to the person who just arrived.

                        A banner rather than a redirect, on purpose: hijacking /dashboard
                        would take a deep link away from somebody who typed it, and there is
                        no reliable "this is their very first visit" signal to hang that on.
                    */}
                    {checklist !== null && checklist.completed === 0 && (
                        <FirstRun checklist={checklist} href={checklist.guideHref} />
                    )}

                    {cards.length > 0 && (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 mb-4">
                            {cards.map((card) => (
                                <ModuleCardTile key={card.key} card={card} />
                            ))}
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="card p-5">
                            <div
                                className="flex items-center gap-2 text-sm"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                <Icon name="members" className="w-4 h-4" />
                                {tc('console.members_0240fc22')}
                            </div>
                            <p className="mt-2 text-3xl font-semibold tracking-tight mono">
                                {memberCount.toLocaleString(locale)}
                            </p>
                        </div>
                        <div className="card p-5">
                            <div
                                className="flex items-center gap-2 text-sm"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                <Icon name="connections" className="w-4 h-4" />
                                {tc('console.enterprise_sso_d3bef1d7')}
                            </div>
                            <p className="mt-2 text-lg font-semibold">
                                <Pill tone={ssoActive ? 'success' : 'neutral'}>
                                    {ssoActive
                                        ? tc('console.active_92340695')
                                        : tc('console.not_configured_dd1841d2')}
                                </Pill>
                            </p>
                        </div>
                        <div className="card p-5">
                            <div
                                className="flex items-center gap-2 text-sm"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                <Icon name="shield" className="w-4 h-4" />
                                {tc('console.your_role_2c73fd13')}
                            </div>
                            <p className="mt-2 text-lg font-semibold">{role}</p>
                        </div>
                    </div>

                    <div className="grid gap-4 lg:grid-cols-3 mt-4">
                        {/*
                            `min-w-0`, because a grid item's default `min-width: auto` floors
                            it at its own min-content width. Without it these two cards forced
                            the row 36px wider than a 375px viewport — and because the scroll
                            container is <main> rather than the document, every "the page does
                            not scroll sideways" check passed while the console panned under
                            the user's thumb.

                            The feed widens to the full row once the checklist beside it is
                            gone, rather than leaving a third of the grid empty for the rest
                            of the organization's life.
                        */}
                        <div
                            className={`min-w-0 ${checklist !== null ? 'lg:col-span-2' : 'lg:col-span-3'}`}
                        >
                            <RecentActivity recent={recent} href={urls.audit} />
                        </div>

                        {checklist !== null && (
                            <Checklist
                                checklist={checklist}
                                getStartedHref={checklist.guideHref}
                                dismissHref={checklist.dismissHref}
                            />
                        )}
                    </div>
                </>
            ) : (
                /*
                    MEMBER OVERVIEW: a nudge to their own security.
                    Org stats, the org-wide audit feed, the module cards and the setup
                    checklist are admin surfaces — a plain member neither manages nor needs
                    to see them.
                */
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="card p-5">
                        <div
                            className="flex items-center gap-2 text-sm"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            <Icon name="shield" className="w-4 h-4" />
                            {tc('console.your_role_2c73fd13')}
                        </div>
                        {/*
                            The separator belongs to the organization name, not to the role:
                            printed unconditionally it left a bare "Member ·" trailing into
                            nothing whenever the member has no organization resolved.
                        */}
                        <p className="mt-2 text-lg font-semibold">
                            {role}
                            {organizationName !== null && (
                                <span style={{ color: 'var(--muted-foreground)', fontWeight: 400 }}>
                                    {' · '}
                                    {organizationName}
                                </span>
                            )}
                        </p>
                    </div>
                    <div className="card p-5 flex flex-col">
                        <div
                            className="flex items-center gap-2 text-sm"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            <Icon name="key" className="w-4 h-4" />
                            {tc('console.your_security_4cb5de89')}
                        </div>
                        <p className="mt-2 text-sm">
                            {tc('console.add_a_passkey_or_two_factor_authentication_t_1e16b90d')}
                        </p>
                        <Button asChild size="sm" variant="primary" className="mt-3 self-start">
                            <Link href={urls.account}>
                                {tc('console.manage_security_9292d619')}
                            </Link>
                        </Button>
                    </div>
                </div>
            )}
        </>
    );
}

function FirstRun({
    checklist,
    href,
}: {
    checklist: NonNullable<Props['checklist']>;
    href: string;
}) {
    const { t: tc } = useTranslator();

    return (
        <div
            className="card p-5 mb-4"
            style={{ borderColor: 'var(--accent-edge)', background: 'var(--accent-soft)' }}
        >
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-start gap-3 min-w-0">
                    <span
                        className="grid place-items-center rounded-lg shrink-0"
                        style={{
                            width: '2rem',
                            height: '2rem',
                            background: 'var(--card)',
                            color: 'var(--primary)',
                        }}
                        aria-hidden="true"
                    >
                        <Icon name="rocket" className="w-4 h-4" />
                    </span>
                    <div className="min-w-0">
                        <p className="font-semibold">
                            {tc('console.nothing_is_set_up_yet_0c0c66c3')}
                        </p>
                        <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {checklist.total}
                            {tc(
                                'console.steps_in_the_order_that_makes_sense_starting_fbaba61b',
                            )}{' '}
                            {checklist.nextTitle ?? tc('console.the_basics_abf82d2f')}.
                        </p>
                    </div>
                </div>
                <Button asChild variant="primary" className="shrink-0">
                    <Link href={href}>{tc('console.start_setting_up_202f2866')}</Link>
                </Button>
            </div>
        </div>
    );
}

/**
 * A module's card, drawn by the console.
 *
 * Each of these used to be a rendered HTML string a module returned through a slot — five
 * hand-drawn SVGs at four sizes, and one that would have rendered permanently light had its
 * author reached for a Tailwind `dark:` utility. The module says what the card IS now; how
 * it looks is not its business.
 */
function ModuleCardTile({ card }: { card: ModuleCard }) {
    const { t: tc } = useTranslator();

    const toneClass =
        card.tone === 'success'
            ? 'cbx-stat-icon--success'
            : card.tone === 'warning'
              ? 'cbx-stat-icon--warning'
              : card.tone === 'neutral'
                ? 'cbx-stat-icon--neutral'
                : '';

    return (
        <div className="card p-5">
            <div className="flex items-center gap-3">
                <span
                    className={`cbx-stat-icon ${toneClass}`}
                    aria-hidden="true"
                    style={
                        card.swatch === null
                            ? undefined
                            : { background: card.swatch, color: 'var(--accent-foreground)' }
                    }
                >
                    <Icon name={card.icon} className="w-5 h-5" />
                </span>
                <div className="min-w-0">
                    <p className="text-sm font-medium" style={{ color: 'var(--muted-foreground)' }}>
                        {card.label}
                    </p>
                    <p
                        className="text-lg font-semibold truncate"
                        style={{ fontVariantNumeric: 'tabular-nums' }}
                    >
                        {card.value}
                    </p>
                </div>
            </div>

            {card.caption !== null && (
                <p className="mt-3 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {card.caption}
                </p>
            )}

            {card.linkHref !== null && (
                <Link
                    href={card.linkHref}
                    className="mt-4 inline-block text-sm font-medium"
                    style={{ color: 'var(--accent-strong)' }}
                >
                    {card.linkLabel ?? tc('console.open_ed077f3d')} →
                </Link>
            )}
        </div>
    );
}

function RecentActivity({ recent, href }: { recent: Props['recent']; href: string | null }) {
    const { t: tc } = useTranslator();

    return (
        <Panel
            title={tc('console.recent_activity_6cb44b56')}
            action={
                href !== null ? (
                    <Link href={href} className="text-sm" style={{ color: 'var(--accent-strong)' }}>
                        {tc('console.view_audit_log_d34e55be')}
                    </Link>
                ) : undefined
            }
        >
            {recent.length === 0 ? (
                <p className="py-6 text-center text-sm" style={{ color: 'var(--faint)' }}>
                    {tc('console.no_activity_recorded_yet_09f1c438')}
                </p>
            ) : (
                <ul>
                    {recent.map((entry, index) => (
                        <li
                            key={entry.id}
                            className="py-3 flex items-center justify-between gap-4"
                            style={
                                index < recent.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0">
                                <p className="text-sm font-medium truncate">{entry.action}</p>
                                {entry.subject !== null && (
                                    <p
                                        className="text-xs truncate"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {entry.subject}
                                    </p>
                                )}
                            </div>
                            <span
                                className="text-xs whitespace-nowrap"
                                style={{ color: 'var(--faint)' }}
                            >
                                {entry.when}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/**
 * The setup checklist, MEASURED — every tick corresponds to something that is actually true
 * of this organization. It disappears for good once the last step is done, or when this
 * admin puts it away.
 */
function Checklist({
    checklist,
    getStartedHref,
    dismissHref,
}: {
    checklist: NonNullable<Props['checklist']>;
    getStartedHref: string;
    dismissHref: string;
}) {
    const { t: tc } = useTranslator();

    return (
        <div className="card min-w-0 p-5">
            <div className="flex items-start justify-between gap-2">
                <h3 className="font-semibold">{tc('console.finish_setting_up_51eb40d7')}</h3>
                <button
                    type="button"
                    className="cbx-help-link"
                    style={{ color: 'var(--muted-foreground)' }}
                    onClick={() => router.post(dismissHref, {}, { preserveScroll: true })}
                >
                    {tc('console.hide_ac20a57b')}
                </button>
            </div>

            <div className="mt-3 flex items-center gap-3">
                <Progress
                    percent={checklist.percent}
                    label={tc('console.setup_progress_e9b4495a')}
                />
                <span
                    className="text-xs mono shrink-0"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    {checklist.completed}/{checklist.total}
                </span>
            </div>

            <ul className="mt-4 space-y-3">
                {checklist.steps.map((step) => (
                    <li key={step.key} className="flex items-start gap-3">
                        <span
                            className="grid place-items-center rounded-full mt-0.5 shrink-0"
                            style={{
                                width: '1.25rem',
                                height: '1.25rem',
                                ...(step.done
                                    ? {
                                          background: 'var(--success-soft)',
                                          color: 'var(--success-strong)',
                                      }
                                    : { border: '1px solid var(--border)' }),
                            }}
                            aria-hidden="true"
                        >
                            {step.done && <Icon name="check" className="w-3 h-3" />}
                        </span>
                        <div className="min-w-0">
                            {step.done ? (
                                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                    {step.title}
                                </p>
                            ) : (
                                <Link
                                    href={step.href}
                                    className="text-sm font-medium inline-flex items-center"
                                    style={{
                                        color: 'var(--accent-strong)',
                                        minHeight: '1.5rem',
                                    }}
                                >
                                    {step.title} →
                                </Link>
                            )}
                        </div>
                    </li>
                ))}
            </ul>

            <Button asChild size="sm" className="mt-4 w-full justify-center">
                <Link href={getStartedHref}>
                    <Icon name="rocket" className="w-4 h-4" />
                    {tc('console.open_the_setup_guide_7c0189e5')}
                </Link>
            </Button>
        </div>
    );
}

Dashboard.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
