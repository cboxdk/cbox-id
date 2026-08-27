import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, ConfirmDelete, Dialog, EmptyState, PageHeader, Panel, Pill } from '@/ui';

interface SessionRow {
    id: string;
    label: string;
    isCurrent: boolean;
    isSupport: boolean;
    ip: string | null;
    signedIn: string | null;
    lastActive: string | null;
    revokeHref: string;
    revokeLabel: string;
}

interface ApplicationRow {
    clientId: string;
    name: string;
    actsOffline: boolean;
    scopes: string[];
    approved: string | null;
    lastUsed: string | null;
    withdrawHref: string;
}

interface ActivityRow {
    id: string;
    label: string;
    ip: string | null;
    at: string | null;
    atIso: string | null;
    atExact: string | null;
}

type Props = PageProps<{
    sessions: SessionRow[];
    applications: ApplicationRow[];
    activity: ActivityRow[];
    revokeOthersHref: string;
}>;

export default function Activity({ sessions, applications, activity, revokeOthersHref }: Props) {
    const [signingOut, setSigningOut] = useState<SessionRow | null>(null);
    const [signingOutOthers, setSigningOutOthers] = useState(false);
    const [withdrawing, setWithdrawing] = useState<ApplicationRow | null>(null);

    return (
        <>
            <PageHeader description="Where you are signed in, what can act as you, and what has happened to your account. If you see something you do not recognise, sign it out and change your password." />

            <Panel
                title="Where you are signed in"
                description="Every browser and device holding a live session as you."
                className="mt-6"
                action={
                    sessions.length > 1 && (
                        <Button size="sm" onClick={() => setSigningOutOthers(true)}>
                            Sign out everywhere else
                        </Button>
                    )
                }
            >
                <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                    {sessions.map((session) => (
                        <div
                            key={session.id}
                            className="flex items-center gap-3 rounded-lg border px-3 py-2"
                            style={{
                                borderColor: session.isCurrent
                                    ? 'var(--accent-edge)'
                                    : 'var(--border)',
                                background: session.isCurrent ? 'var(--accent-soft)' : undefined,
                            }}
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-sm truncate">
                                    {session.label}
                                    {session.isCurrent && (
                                        <Pill tone="success" className="align-middle ml-1">
                                            This device
                                        </Pill>
                                    )}
                                    {/*
                                        Said out loud: somebody is acting as you with
                                        permission, and you are entitled to know it is
                                        happening.
                                    */}
                                    {session.isSupport && (
                                        <Pill tone="warning" className="align-middle ml-1">
                                            Support session
                                        </Pill>
                                    )}
                                </p>
                                {/*
                                    --muted-foreground, not --faint. `--faint` is placed to
                                    clear 4.5:1 on the CARD, and the current session's row
                                    is not the card — it carries the accent wash, where the
                                    same 12px line measures 4.34:1 in dark. This is the one
                                    line on the page somebody actually reads character by
                                    character (an address, and when it was last used), so it
                                    takes the token that survives being sat on something.
                                */}
                                <p
                                    className="text-xs truncate"
                                    style={{ color: 'var(--muted-foreground)' }}
                                >
                                    {session.ip ?? 'no address recorded'} · signed in{' '}
                                    {session.signedIn ?? 'unknown'} · last active{' '}
                                    {session.lastActive ?? 'never'}
                                </p>
                            </div>

                            {/*
                                The accessible name names the DEVICE — six sessions would
                                otherwise be six buttons called "Sign out", on the control
                                that can end the browser you are holding. The visible label
                                stays short.
                            */}
                            <Button
                                size="sm"
                                variant="danger"
                                className="shrink-0"
                                aria-label={session.revokeLabel}
                                onClick={() => setSigningOut(session)}
                            >
                                Sign out
                            </Button>
                        </div>
                    ))}
                </div>
            </Panel>

            <Panel
                title="Applications that can act as you"
                description="Anything you approved — including a device or a command line you signed in from."
                className="mt-6"
            >
                {applications.length === 0 ? (
                    <EmptyState
                        icon="clients"
                        title="Nothing can act as you"
                        description="Applications you approve — a command line, a device, another product signing you in with Cbox ID — appear here, and you can withdraw any of them."
                    />
                ) : (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                        {applications.map((application) => (
                            <div
                                key={application.clientId}
                                className="flex items-center gap-3 rounded-lg border px-3 py-2"
                                style={{ borderColor: 'var(--border)' }}
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="text-sm truncate">
                                        {application.name}
                                        {/*
                                            The distinction that matters to a person: this
                                            one keeps working when they are not there.
                                        */}
                                        {application.actsOffline && (
                                            <Pill tone="info" className="align-middle ml-1">
                                                Works when you are away
                                            </Pill>
                                        )}
                                    </p>
                                    <p
                                        className="text-xs truncate"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        approved {application.approved ?? 'unknown'} · last used{' '}
                                        {application.lastUsed ?? 'never'}
                                    </p>
                                    {application.scopes.length > 0 && (
                                        <p
                                            className="text-xs mt-1 mono truncate"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {application.scopes.join(' · ')}
                                        </p>
                                    )}
                                </div>

                                {/*
                                    Type-to-confirm: this destroys a credential somebody else
                                    is holding, and it cannot be undone from here — the
                                    application has to ask again.
                                */}
                                <Button
                                    size="sm"
                                    className="shrink-0"
                                    aria-label={`Withdraw access for ${application.name}`}
                                    onClick={() => setWithdrawing(application)}
                                >
                                    Withdraw access
                                </Button>
                            </div>
                        ))}
                    </div>
                )}
            </Panel>

            <Panel
                title="Recent activity"
                description={
                    // Guarded: "The most recent 0 events." rendered directly above the empty
                    // state saying nothing is recorded yet.
                    activity.length === 0
                        ? 'Sign-ins and changes to how you sign in.'
                        : `Sign-ins and changes to how you sign in. The most recent ${activity.length} ${activity.length === 1 ? 'event' : 'events'}.`
                }
                className="mt-6"
            >
                {activity.length === 0 ? (
                    <EmptyState
                        icon="audit"
                        title="Nothing recorded yet"
                        description="Sign-ins and changes to your password, passkeys and two-factor settings appear here."
                    />
                ) : (
                    activity.map((entry, index) => (
                        <div
                            key={entry.id}
                            className="flex items-baseline gap-3 py-2"
                            style={
                                index < activity.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <div className="min-w-0 flex-1">
                                <p className="text-sm">{entry.label}</p>
                                {/*
                                    Only when there IS one. An entry written outside a request
                                    has no address, and "no address recorded" on every such
                                    row is a column of noise between the reader and the rows
                                    that do carry one.
                                */}
                                {entry.ip !== null && (
                                    <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                        from {entry.ip}
                                    </p>
                                )}
                            </div>
                            <time
                                className="text-xs shrink-0"
                                style={{ color: 'var(--faint)' }}
                                dateTime={entry.atIso ?? undefined}
                                title={entry.atExact ?? undefined}
                            >
                                {entry.at ?? '—'}
                            </time>
                        </div>
                    ))
                )}
            </Panel>

            {/*
                An ordinary confirm, not the type-to-confirm one: signing a session out is
                about your OWN account, where the two-identical-tabs hazard that dialog
                exists for does not apply.
            */}
            <Dialog
                open={signingOut !== null}
                onOpenChange={(open) => !open && setSigningOut(null)}
                title={
                    signingOut === null
                        ? ''
                        : signingOut.isCurrent
                          ? 'Sign out of this device?'
                          : `Sign out ${signingOut.label}?`
                }
                description={
                    signingOut?.isCurrent === true
                        ? 'You will have to sign in again.'
                        : 'That session stops working on its next request.'
                }
                footer={
                    <>
                        <Button onClick={() => setSigningOut(null)}>Cancel</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                const session = signingOut;
                                setSigningOut(null);

                                if (session !== null) {
                                    router.post(session.revokeHref, {}, { preserveScroll: true });
                                }
                            }}
                        >
                            Sign out
                        </Button>
                    </>
                }
            />

            <Dialog
                open={signingOutOthers}
                onOpenChange={setSigningOutOthers}
                title="Sign out of every session except this one?"
                description="Every other browser and device holding a session as you stops working on its next request. This one stays signed in."
                footer={
                    <>
                        <Button onClick={() => setSigningOutOthers(false)}>Cancel</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                setSigningOutOthers(false);
                                router.post(revokeOthersHref, {}, { preserveScroll: true });
                            }}
                        >
                            Sign out everywhere else
                        </Button>
                    </>
                }
            />

            <ConfirmDelete
                open={withdrawing !== null}
                onOpenChange={(open) => !open && setWithdrawing(null)}
                name={withdrawing?.name ?? ''}
                verb="Withdraw"
                consequence="It stops being able to act as you immediately, and has to ask for your approval again."
                // An application's access is not scoped to one environment, so naming one
                // in the dialog would be naming something that is not true of the act.
                environment={null}
                onConfirm={() => {
                    const application = withdrawing;
                    setWithdrawing(null);

                    if (application !== null) {
                        router.delete(application.withdrawHref, { preserveScroll: true });
                    }
                }}
            />
        </>
    );
}

Activity.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
