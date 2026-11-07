import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from './Badge';
import { Button } from './Button';
import { Dialog } from './Dialog';

/** A support session that is open right now, as the server describes it. */
export interface SupportSessionRow {
    id: string;
    /** The person being acted as. */
    user: string;
    userHref: string;
    organization: string;
    app: string;
    /** The app's own address, to go back into it while the session is open. */
    appUrl: string | null;
    reason: string;
    /** The administrator who started it, or null when they no longer resolve. */
    startedBy: string | null;
    /** "in 42 minutes". */
    expiresIn: string;
    expiresAt: string;
    endHref: string;
}

/**
 * The open support sessions, each with End now — on a user's page and on an
 * organization's, one list, so the two cannot describe one session differently.
 *
 * `lead` picks which of the two names a row leads with: the person on an organization's
 * page, the organization on a person's.
 */
export function SupportSessions({
    sessions,
    lead,
}: {
    sessions: SupportSessionRow[];
    lead: 'user' | 'organization';
}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [ending, setEnding] = useState<SupportSessionRow | null>(null);

    if (sessions.length === 0) {
        return null;
    }

    return (
        <>
            <ul className="space-y-2">
                {sessions.map((session) => (
                    <li
                        key={session.id}
                        className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-lg border px-3 py-2"
                        style={{
                            borderColor: 'color-mix(in srgb, var(--warning) 45%, var(--border))',
                            background: 'color-mix(in srgb, var(--warning) 6%, transparent)',
                        }}
                    >
                        <div className="min-w-0 flex-1 basis-56">
                            <p className="text-sm font-medium truncate">
                                {consoleRich('console.support_session_subject_app', {
                                    subject:
                                        lead === 'user' ? (
                                            <Link
                                                href={session.userHref}
                                                style={{ color: 'var(--accent-strong)' }}
                                            >
                                                {session.user}
                                            </Link>
                                        ) : (
                                            session.organization
                                        ),
                                    app: session.app,
                                })}
                            </p>
                            <p
                                className="text-xs break-words"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                “{session.reason}”
                            </p>
                            <p className="text-xs" style={{ color: 'var(--faint)' }}>
                                {session.startedBy !== null && (
                                    <>
                                        {consoleRich('console.by_part1_805e76a8', {
                                            part1: session.startedBy,
                                        })}
                                    </>
                                )}
                                <time dateTime={session.expiresAt}>
                                    {consoleRich('console.ends_part1_10fed5b5', {
                                        part1: session.expiresIn,
                                    })}
                                </time>
                            </p>
                        </div>

                        <Badge tone="warn">{consoleT('console.support_session_5f5a9473')}</Badge>

                        <div className="flex items-center gap-2 shrink-0">
                            {session.appUrl !== null && (
                                <Button asChild size="sm">
                                    <a href={session.appUrl} rel="noreferrer">
                                        {consoleRich('console.open_part1_10c0f8b9', {
                                            part1: session.app,
                                        })}
                                    </a>
                                </Button>
                            )}
                            <Button size="sm" variant="danger" onClick={() => setEnding(session)}>
                                {consoleT('console.end_now_adf7eccb')}
                            </Button>
                        </div>
                    </li>
                ))}
            </ul>

            {/*
                An ORDINARY confirm, not type-to-confirm. Ending a session only ever takes
                access away from the person acting, and it is the button somebody reaches
                for when something looks wrong — a typed name in front of it slows exactly
                the moment that should be fast.
            */}
            <Dialog
                open={ending !== null}
                onOpenChange={(open) => !open && setEnding(null)}
                size="sm"
                title={
                    ending === null
                        ? ''
                        : consoleFinalText(
                              'console.end_the_support_session_for_part1_in_part2_7c1d4ee9',
                              { part1: ending.user, part2: ending.app },
                          )
                }
                description={consoleT(
                    'console.every_token_it_issued_is_revoked_now_and_no_new_sign_in_e0dca49c',
                )}
                footer={
                    <>
                        <Button onClick={() => setEnding(null)}>
                            {consoleT('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                const session = ending;
                                setEnding(null);

                                if (session !== null) {
                                    router.delete(session.endHref, { preserveScroll: true });
                                }
                            }}
                        >
                            {consoleT('console.end_now_adf7eccb')}
                        </Button>
                    </>
                }
            />
        </>
    );
}
