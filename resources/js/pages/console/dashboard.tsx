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

interface App {
    name: string;
    url: string;
    host: string;
    initial: string;
    /** A stable hue per app name, so each tile keeps a recognisable colour. */
    hue: number;
}

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
    apps: App[];
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
    } | null;
    help: HelpContent;
    urls: {
        audit: string;
        account: string;
        getStarted: string;
        dismissChecklist: string;
    };
}>;

export default function Dashboard({
    heading,
    greeting,
    isAdmin,
    apps,
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
    return (
        <>
            <PageHeader title={heading} help={help} description={greeting} />

            {apps.length > 0 && <Apps apps={apps} />}

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
                        <FirstRun checklist={checklist} href={urls.getStarted} />
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
                                <Icon name="members" className="w-4 h-4" /> Members
                            </div>
                            <p className="mt-2 text-3xl font-semibold tracking-tight mono">
                                {memberCount.toLocaleString()}
                            </p>
                        </div>
                        <div className="card p-5">
                            <div
                                className="flex items-center gap-2 text-sm"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                <Icon name="connections" className="w-4 h-4" /> Enterprise SSO
                            </div>
                            <p className="mt-2 text-lg font-semibold">
                                <Pill tone={ssoActive ? 'success' : 'neutral'}>
                                    {ssoActive ? 'Active' : 'Not configured'}
                                </Pill>
                            </p>
                        </div>
                        <div className="card p-5">
                            <div
                                className="flex items-center gap-2 text-sm"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                <Icon name="shield" className="w-4 h-4" /> Your role
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
                                getStartedHref={urls.getStarted}
                                dismissHref={urls.dismissChecklist}
                            />
                        )}
                    </div>
                </>
            ) : (
                /*
                    MEMBER OVERVIEW: their apps, above, plus a nudge to their own security.
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
                            <Icon name="shield" className="w-4 h-4" /> Your role
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
                            <Icon name="key" className="w-4 h-4" /> Your security
                        </div>
                        <p className="mt-2 text-sm">
                            Add a passkey or two-factor authentication to keep your account safe.
                        </p>
                        <Button asChild size="sm" variant="primary" className="mt-3 self-start">
                            <Link href={urls.account}>Manage security</Link>
                        </Button>
                    </div>
                </div>
            )}
        </>
    );
}

function Apps({ apps }: { apps: App[] }) {
    return (
        <section className="mb-6">
            <h2
                className="text-xs font-medium uppercase mb-3"
                style={{ color: 'var(--muted-foreground)', letterSpacing: '0.06em' }}
            >
                Your apps
            </h2>
            <div className="grid gap-3 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
                {apps.map((app) => (
                    // A real `<a>`, not an Inertia visit: these leave this application
                    // entirely, which is the whole point of the launcher.
                    <a key={app.url} href={app.url} className="cbx-app-tile">
                        <span
                            className="cbx-app-logo"
                            aria-hidden="true"
                            style={{
                                background: `linear-gradient(135deg, oklch(0.63 0.15 ${app.hue}), oklch(0.5 0.17 ${(app.hue + 28) % 360}))`,
                            }}
                        >
                            {app.initial}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span
                                className="block truncate font-semibold"
                                style={{ color: 'var(--foreground)' }}
                            >
                                {app.name}
                            </span>
                            <span
                                className="block truncate text-xs mono"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {app.host}
                            </span>
                        </span>
                        <Icon name="external" className="cbx-app-go w-4 h-4" aria-hidden="true" />
                    </a>
                ))}
            </div>
        </section>
    );
}

function FirstRun({
    checklist,
    href,
}: {
    checklist: NonNullable<Props['checklist']>;
    href: string;
}) {
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
                        <p className="font-semibold">Nothing is set up yet</p>
                        <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {checklist.total} steps, in the order that makes sense — starting with{' '}
                            {checklist.nextTitle ?? 'the basics'}.
                        </p>
                    </div>
                </div>
                <Button asChild variant="primary" className="shrink-0">
                    <Link href={href}>Start setting up</Link>
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
                    {card.linkLabel ?? 'Open'} →
                </Link>
            )}
        </div>
    );
}

function RecentActivity({ recent, href }: { recent: Props['recent']; href: string }) {
    return (
        <Panel
            title="Recent activity"
            action={
                <Link href={href} className="text-sm" style={{ color: 'var(--accent-strong)' }}>
                    View activity log
                </Link>
            }
        >
            {recent.length === 0 ? (
                <p className="py-6 text-center text-sm" style={{ color: 'var(--faint)' }}>
                    No activity recorded yet.
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
    return (
        <div className="card min-w-0 p-5">
            <div className="flex items-start justify-between gap-2">
                <h3 className="font-semibold">Finish setting up</h3>
                <button
                    type="button"
                    className="cbx-help-link"
                    style={{ color: 'var(--muted-foreground)' }}
                    onClick={() => router.post(dismissHref, {}, { preserveScroll: true })}
                >
                    Hide
                </button>
            </div>

            <div className="mt-3 flex items-center gap-3">
                <Progress percent={checklist.percent} label="Setup progress" />
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
                    Open the setup guide
                </Link>
            </Button>
        </div>
    );
}

Dashboard.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
