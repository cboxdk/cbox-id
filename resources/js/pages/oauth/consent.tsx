import { useForm, usePage } from '@inertiajs/react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps, SharedProps } from '@/types';
import { Button, Icon, Pill } from '@/ui';

interface ScopeRow {
    scope: string;
    label: string;
    /** A management-plane scope: what an agent may DO as this person, not what it learns. */
    management?: boolean;
    /** Some critical action needs it — minting credentials, changing how people sign in. */
    critical?: boolean;
}

interface ClientProps {
    name: string;
    owner: string;
    /** It registered itself (RFC 7591, or a metadata document): nobody here reviewed it. */
    selfRegistered?: boolean;
    /** The host that published its metadata document — the one VERIFIED fact about it. */
    documentHost?: string | null;
    clientUri?: string | null;
}

type Props = PageProps<{
    /** Set when the request cannot be answered by redirecting anywhere. */
    error?: string;
    client?: ClientProps;
    me?: { name: string; email: string | null; initial: string };
    /** The organization the app will see this person in, when there is one. */
    organization?: string | null;
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
    organization,
    scopes = NO_SCOPES,
    redirectHost,
    approveHref,
    denyHref,
}: Props) {
    const { t } = useTranslator();

    if (error !== undefined || client === undefined || me === undefined) {
        return <Failure message={error ?? t('oauth.failure.generic')} />;
    }

    return (
        <Authorize
            client={client}
            me={me}
            organization={organization ?? null}
            scopes={scopes}
            redirectHost={redirectHost ?? null}
            approveHref={approveHref ?? ''}
            denyHref={denyHref ?? ''}
        />
    );
}

/** Whose account this is: the door's brand on a customer's environment, else this product. */
function useAccountName(): string {
    const { app, brand } = usePage<SharedProps>().props;

    return brand?.name ?? app.name;
}

function Failure({ message }: { message: string }) {
    const accountName = useAccountName();
    const { t } = useTranslator();
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
            <h1 className="text-2xl font-semibold tracking-tight">{t('oauth.failure.heading')}</h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted)' }}>
                {message}
            </p>
            <Button asChild className="w-full mt-6">
                <a href="/">{t('oauth.failure.back', { name: accountName })}</a>
            </Button>
        </div>
    );
}

function Authorize({
    client,
    me,
    organization,
    scopes,
    redirectHost,
    approveHref,
    denyHref,
}: {
    client: NonNullable<Props['client']>;
    me: NonNullable<Props['me']>;
    organization: string | null;
    scopes: ScopeRow[];
    redirectHost: string | null;
    approveHref: string;
    denyHref: string;
}) {
    const approve = useForm({});
    const deny = useForm({});
    const accountName = useAccountName();
    const { t, rich } = useTranslator();
    const actsAsYou = scopes.some((row) => row.management === true);
    const anyCritical = scopes.some((row) => row.critical === true);

    return (
        <div>
            {/*
                No app logo: one named by a registration or a metadata document is an image on
                the publisher's host, and drawing it would report every person who reached this
                screen to them. See OAuthConsentController.
            */}
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

            <h1 className="text-2xl font-semibold tracking-tight">
                {t('oauth.consent.heading', { client: client.name })}
            </h1>
            <p className="mt-1.5 text-sm" style={{ color: 'var(--muted)' }}>
                {rich('oauth.consent.wants_access', {
                    client: <b>{client.name}</b>,
                    account: accountName,
                })}
            </p>

            {/*
                A METADATA DOCUMENT CLIENT leads with the host that published it: the name and
                the logo are whatever that host wrote, the host is what was fetched.
            */}
            {client.documentHost && (
                <p className="mt-1.5 text-xs" style={{ color: 'var(--muted)' }}>
                    {rich('oauth.consent.published_by', {
                        host: <b className="mono">{client.documentHost}</b>,
                    })}
                    {client.clientUri && (
                        <>
                            {' '}
                            <a
                                href={client.clientUri}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="underline"
                            >
                                {t('oauth.consent.about_app')}
                            </a>
                        </>
                    )}
                </p>
            )}

            {/*
                PROVENANCE. An application's name is chosen by whoever registered it, so the
                name alone is not evidence of who is asking — and any organization admin in
                this environment may register an app called "Cbox ID Account Sync". One that
                registered ITSELF has no owner to name, and says so as a warning: anybody who
                can reach this server can register one, and send a link to it.
            */}
            {client.selfRegistered ? (
                <p
                    className="mt-3 flex items-start gap-2 text-xs rounded-md p-2.5"
                    style={{ background: 'var(--warning-soft)', color: 'var(--warning-strong)' }}
                >
                    <Icon name="warning" className="w-4 h-4 shrink-0" />
                    <span>{t('oauth.consent.self_registered', { account: accountName })}</span>
                </p>
            ) : (
                <p className="mt-1.5 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {rich('oauth.consent.registered_by', {
                        owner: <b style={{ color: 'var(--muted)' }}>{client.owner}</b>,
                    })}
                </p>
            )}

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
                    {/*
                        WHICH ORGANIZATION. The app's tokens carry this organization's roles,
                        so somebody in several is agreeing to something different in each.
                    */}
                    {organization !== null && (
                        <p className="text-xs truncate mt-0.5" style={{ color: 'var(--muted)' }}>
                            {rich('oauth.consent.in_organization', {
                                organization: <b>{organization}</b>,
                            })}
                        </p>
                    )}
                </div>
            </div>

            {scopes.length > 0 && (
                <>
                    <p className="cbx-page-eyebrow mt-6">
                        {t('oauth.consent.will_allow', { client: client.name })}
                    </p>
                    <ul className="mt-2.5 space-y-2">
                        {scopes.map((row) => (
                            <li key={row.scope} className="flex items-center gap-2.5 text-sm">
                                <Icon
                                    name={row.critical ? 'warning' : 'check'}
                                    className="w-4 h-4 shrink-0"
                                    style={{
                                        color: row.critical
                                            ? 'var(--danger-strong)'
                                            : 'var(--success-strong)',
                                    }}
                                />
                                <span>{row.label}</span>
                                {/*
                                    CRITICAL, SAID IN WORDS — the colour is never the only
                                    carrier: a scope that lets an agent mint credentials or
                                    change how people sign in must not read like one that
                                    lets it see a name.
                                */}
                                {row.critical && (
                                    <Pill tone="destructive" dot={false}>
                                        {t('oauth.consent.critical')}
                                    </Pill>
                                )}
                            </li>
                        ))}
                    </ul>
                    {actsAsYou && (
                        <p className="mt-3 text-xs" style={{ color: 'var(--muted)' }}>
                            {t('oauth.consent.acts_as_you')}
                            {anyCritical && <> {t('oauth.consent.critical_notice')}</>}
                        </p>
                    )}
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
                    {t('oauth.consent.cancel')}
                </Button>
                <Button
                    variant="primary"
                    className="flex-1"
                    loading={approve.processing}
                    onClick={() => approve.post(approveHref)}
                >
                    {t('oauth.consent.authorize')}
                </Button>
            </div>

            {redirectHost !== null && (
                <p className="mt-6 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                    {rich('oauth.consent.redirect_notice', {
                        host: <span className="mono">{redirectHost}</span>,
                    })}
                </p>
            )}
        </div>
    );
}

Consent.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
