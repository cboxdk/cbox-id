import { useTranslator } from '@/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Button, ConfirmDelete, Dialog, EmptyState, PageHeader, Panel, Pill } from '@/ui';

interface SessionRow {
    id: string;
    label: string;
    isCurrent: boolean;
    isSupport: boolean;
    ip: string | null;
    country: string | null;
    countryCode: string | null;
    city: string | null;
    locationUnavailable: string;
    signInMethod: string | null;
    signedIn: string | null;
    signedInIso: string | null;
    lastActive: string | null;
    lastActiveIso: string | null;
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
    /** A second line where the event needs one — for a support session, the app and why. */
    detail: string | null;
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
    help: HelpContent;
}>;

export default function Activity({
    sessions,
    applications,
    activity,
    revokeOthersHref,
    help,
}: Props) {
    const { t: tc } = useTranslator();

    const [signingOut, setSigningOut] = useState<SessionRow | null>(null);
    const [signingOutOthers, setSigningOutOthers] = useState(false);
    const [withdrawing, setWithdrawing] = useState<ApplicationRow | null>(null);

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.where_you_are_signed_in_what_can_act_as_you__874bdb24')}
            />

            <Panel
                title={tc('console.where_you_are_signed_in_a07510ca')}
                description={tc('console.recognise_your_devices_by_their_browser_ip_a_eb8587ae')}
                className="mt-6"
                action={
                    sessions.length > 1 && (
                        <Button size="sm" onClick={() => setSigningOutOthers(true)}>
                            {tc('console.sign_out_everywhere_else_76406ac4')}
                        </Button>
                    )
                }
            >
                <div style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                    {sessions.map((session) => (
                        <div
                            key={session.id}
                            className="rounded-lg border p-4"
                            style={{
                                borderColor: session.isCurrent
                                    ? 'var(--accent-edge)'
                                    : 'var(--border)',
                                background: session.isCurrent ? 'var(--accent-soft)' : undefined,
                            }}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="flex flex-wrap items-center gap-2 text-sm font-medium break-words">
                                        {session.label}
                                        {session.isCurrent && (
                                            <Pill tone="success">
                                                {tc('console.this_device_d052579c')}
                                            </Pill>
                                        )}
                                        {session.isSupport && (
                                            <Pill tone="warning">
                                                {tc('console.support_session_5f5a9473')}
                                            </Pill>
                                        )}
                                    </p>
                                    {session.signInMethod && (
                                        <p
                                            className="mt-1 text-xs"
                                            style={{ color: 'var(--muted-foreground)' }}
                                        >
                                            {session.signInMethod}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    size="sm"
                                    variant="danger"
                                    className="shrink-0"
                                    aria-label={session.revokeLabel}
                                    onClick={() => setSigningOut(session)}
                                >
                                    {tc('console.sign_out_48f0d3d3')}
                                </Button>
                            </div>
                            <dl className="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2 xl:grid-cols-4 text-sm">
                                <div className="min-w-0">
                                    <dt
                                        className="text-xs"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {tc('console.country_701d021d')}
                                    </dt>
                                    <dd className="mt-1 break-words">
                                        {session.country ?? session.locationUnavailable}
                                    </dd>
                                    {session.city && (
                                        <dd
                                            className="mt-1 text-xs break-words"
                                            style={{ color: 'var(--muted-foreground)' }}
                                        >
                                            {session.city}
                                        </dd>
                                    )}
                                </div>
                                <div className="min-w-0">
                                    <dt
                                        className="text-xs"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {tc('console.ip_address_0302a467')}
                                    </dt>
                                    <dd className="mt-1 mono break-all">
                                        {session.ip ?? tc('console.not_recorded_b37c7879')}
                                    </dd>
                                </div>
                                <div className="min-w-0">
                                    <dt
                                        className="text-xs"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {tc('console.signed_in_ca566c89')}
                                    </dt>
                                    <dd className="mt-1">
                                        <time
                                            dateTime={session.signedInIso ?? undefined}
                                            title={session.signedInIso ?? undefined}
                                        >
                                            {session.signedIn ??
                                                tc('console.not_recorded_b37c7879')}
                                        </time>
                                    </dd>
                                </div>
                                <div className="min-w-0">
                                    <dt
                                        className="text-xs"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {tc('console.last_active_bcdf701c')}
                                    </dt>
                                    <dd className="mt-1">
                                        <time
                                            dateTime={session.lastActiveIso ?? undefined}
                                            title={session.lastActiveIso ?? undefined}
                                        >
                                            {session.lastActive ??
                                                tc('console.not_recorded_b37c7879')}
                                        </time>
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    ))}
                </div>
            </Panel>

            <Panel
                title={tc('console.applications_that_can_act_as_you_f9bce68e')}
                description={tc('console.anything_you_approved_including_a_device_or__eea3fafc')}
                className="mt-6"
            >
                {applications.length === 0 ? (
                    <EmptyState
                        icon="clients"
                        title={tc('console.nothing_can_act_as_you_aa9fe49d')}
                        description={tc(
                            'console.applications_you_approve_a_command_line_a_de_9ae677c4',
                        )}
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
                                                {tc('console.works_when_you_are_away_e5adcefe')}
                                            </Pill>
                                        )}
                                    </p>
                                    <p
                                        className="text-xs truncate"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {tc('console.approved_68a00b43')}
                                        {application.approved ?? tc('console.unknown_b23a6a84')}
                                        {tc('console.last_used_85606035')}{' '}
                                        {application.lastUsed ?? tc('console.never_6497e4b3')}
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
                                    aria-label={tc('console.withdraw_access_for_value0_56675c80', {
                                        value0: application.name,
                                    })}
                                    onClick={() => setWithdrawing(application)}
                                >
                                    {tc('console.withdraw_access_b15ef5cf')}
                                </Button>
                            </div>
                        ))}
                    </div>
                )}
            </Panel>

            <Panel
                title={tc('console.recent_activity_6cb44b56')}
                description={
                    // Guarded: "The most recent 0 events." rendered directly above the empty
                    // state saying nothing is recorded yet.
                    activity.length === 0
                        ? tc('console.sign_ins_and_changes_to_how_you_sign_in_7dad1547')
                        : tc('console.sign_ins_and_changes_to_how_you_sign_in_the__2db9cf3f', {
                              value0: activity.length,
                              value1: activity.length === 1 ? 'event' : 'events',
                          })
                }
                className="mt-6"
            >
                {activity.length === 0 ? (
                    <EmptyState
                        icon="audit"
                        title={tc('console.nothing_recorded_yet_cb77e288')}
                        description={tc(
                            'console.sign_ins_and_changes_to_your_password_passke_9e7da5c8',
                        )}
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
                                {entry.detail !== null && (
                                    <p
                                        className="text-xs break-words"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {entry.detail}
                                    </p>
                                )}
                                {/*
                                    Only when there IS one. An entry written outside a request
                                    has no address, and "no address recorded" on every such
                                    row is a column of noise between the reader and the rows
                                    that do carry one.
                                */}
                                {entry.ip !== null && (
                                    <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                        {tc('console.from_49a6ddf9')}
                                        {entry.ip}
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
                          ? tc('console.sign_out_of_this_device_5c5bf797')
                          : tc('console.sign_out_value0_8c6eb13b', { value0: signingOut.label })
                }
                description={
                    signingOut?.isCurrent === true
                        ? tc('console.you_will_have_to_sign_in_again_69f7f1e7')
                        : tc('console.that_session_stops_working_on_its_next_reque_be13d5de')
                }
                footer={
                    <>
                        <Button onClick={() => setSigningOut(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
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
                            {tc('console.sign_out_48f0d3d3')}
                        </Button>
                    </>
                }
            />

            <Dialog
                open={signingOutOthers}
                onOpenChange={setSigningOutOthers}
                title={tc('console.sign_out_of_every_session_except_this_one_5d438b18')}
                description={tc('console.every_other_browser_and_device_holding_a_ses_3d2d96e0')}
                footer={
                    <>
                        <Button onClick={() => setSigningOutOthers(false)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                setSigningOutOthers(false);
                                router.post(revokeOthersHref, {}, { preserveScroll: true });
                            }}
                        >
                            {tc('console.sign_out_everywhere_else_76406ac4')}
                        </Button>
                    </>
                }
            />

            <ConfirmDelete
                open={withdrawing !== null}
                onOpenChange={(open) => !open && setWithdrawing(null)}
                name={withdrawing?.name ?? ''}
                verb="Withdraw"
                consequence={tc('console.it_stops_being_able_to_act_as_you_immediatel_5c3d584b')}
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
