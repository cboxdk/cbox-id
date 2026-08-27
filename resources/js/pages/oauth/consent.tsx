import { useForm } from '@inertiajs/react';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, Icon } from '@/ui';

interface ScopeRow {
    scope: string;
    label: string;
}

type Props = PageProps<{
    /** Set when the request cannot be answered by redirecting anywhere. */
    error?: string;
    client?: { name: string; owner: string };
    me?: { name: string; email: string | null; initial: string };
    scopes?: ScopeRow[];
    redirectHost?: string | null;
    approveHref?: string;
    denyHref?: string;
}>;

/**
 * A stable empty list, so a request that grants no scopes does not hand the tree a new
 * array identity on every render. `scopes = []` in the signature is a fresh array each
 * time, which defeats every memo below it.
 */
const NO_SCOPES: ScopeRow[] = [];

export default function Consent({
    error,
    client,
    me,
    scopes = NO_SCOPES,
    redirectHost,
    approveHref,
    denyHref,
}: Props) {
    if (error !== undefined || client === undefined || me === undefined) {
        return <Failure message={error ?? 'This authorization request could not be completed.'} />;
    }

    return (
        <Authorize
            client={client}
            me={me}
            scopes={scopes}
            redirectHost={redirectHost ?? null}
            approveHref={approveHref ?? ''}
            denyHref={denyHref ?? ''}
        />
    );
}

function Failure({ message }: { message: string }) {
    return (
        <div>
            <div
                className="grid place-items-center rounded-full mb-5 text-lg font-bold"
                style={{
                    width: '2.75rem',
                    height: '2.75rem',
                    background: 'var(--danger-soft)',
                    color: 'var(--danger-strong)',
                }}
                aria-hidden="true"
            >
                !
            </div>
            <h1 className="text-2xl font-semibold tracking-tight">Authorization failed</h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted)' }}>
                {message}
            </p>
            <Button asChild className="w-full mt-6">
                <a href="/">Back to Cbox ID</a>
            </Button>
        </div>
    );
}

function Authorize({
    client,
    me,
    scopes,
    redirectHost,
    approveHref,
    denyHref,
}: {
    client: NonNullable<Props['client']>;
    me: NonNullable<Props['me']>;
    scopes: ScopeRow[];
    redirectHost: string | null;
    approveHref: string;
    denyHref: string;
}) {
    const approve = useForm({});
    const deny = useForm({});

    return (
        <div>
            <div
                className="grid place-items-center rounded-full mb-5"
                style={{
                    width: '2.75rem',
                    height: '2.75rem',
                    background: 'var(--accent-soft)',
                    color: 'var(--accent-strong)',
                }}
            >
                <Icon name="shield" className="w-5 h-5" />
            </div>

            <h1 className="text-2xl font-semibold tracking-tight">Authorize {client.name}</h1>
            <p className="mt-1.5 text-sm" style={{ color: 'var(--muted)' }}>
                <b>{client.name}</b> wants to access your Cbox ID account.
            </p>

            {/*
                PROVENANCE. An application's name is chosen by whoever registered it, so the
                name alone is not evidence of who is asking — and any organization admin in
                this environment may register an app called "Cbox ID Account Sync".
            */}
            <p className="mt-1.5 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                Registered by <b style={{ color: 'var(--muted)' }}>{client.owner}</b> — an
                app&rsquo;s name is chosen by whoever registered it.
            </p>

            {/*
                WHICH ACCOUNT. A person may hold several on this browser, and the one being
                authorized is whichever is active — not necessarily the one they had in mind.
            */}
            <div className="card mt-6 p-4 flex items-center gap-3">
                <span
                    aria-hidden="true"
                    className="grid place-items-center rounded-full text-sm font-semibold"
                    style={{
                        width: '2.25rem',
                        height: '2.25rem',
                        background: 'var(--accent-soft)',
                        color: 'var(--accent-strong)',
                    }}
                >
                    {me.initial}
                </span>
                <div className="min-w-0">
                    <p className="font-medium truncate">{me.name}</p>
                    <p className="text-xs truncate" style={{ color: 'var(--muted-foreground)' }}>
                        {me.email}
                    </p>
                </div>
            </div>

            {scopes.length > 0 && (
                <>
                    <p className="cbx-page-eyebrow mt-6">This will allow {client.name} to</p>
                    <ul className="mt-2.5 space-y-2">
                        {scopes.map((row) => (
                            <li key={row.scope} className="flex items-center gap-2.5 text-sm">
                                <Icon
                                    name="check"
                                    className="w-4 h-4 shrink-0"
                                    style={{ color: 'var(--success-strong)' }}
                                />
                                <span>{row.label}</span>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            {/*
                CANCEL FIRST, and not for symmetry: somebody who does not recognise the app
                asking is the person this screen most has to serve, and the safe answer
                should not be the one they have to look for.
            */}
            <div className="mt-8 flex gap-3">
                <Button
                    className="flex-1"
                    loading={deny.processing}
                    onClick={() => deny.post(denyHref)}
                >
                    Cancel
                </Button>
                <Button
                    variant="primary"
                    className="flex-1"
                    loading={approve.processing}
                    onClick={() => approve.post(approveHref)}
                >
                    Authorize
                </Button>
            </div>

            {redirectHost !== null && (
                <p className="mt-6 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    You&rsquo;ll be redirected to <span className="mono">{redirectHost}</span>{' '}
                    after authorizing.
                </p>
            )}
        </div>
    );
}

Consent.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
