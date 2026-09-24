import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Avatar, Button, Icon, Spinner } from '@/ui';

interface OrganizationRow {
    id: string;
    name: string;
    /** The person's role in it, as a label ("Owner", "Member"). */
    role: string;
}

type Props = PageProps<{
    client: { name: string; owner: string };
    me: { name: string; email: string | null; initial: string };
    organizations: OrganizationRow[];
    /** The app's hint, the organization the person is working in, or the first. */
    selected: string | null;
    chooseHref: string;
    /** Null when this environment does not let people create organizations. */
    createHref: string | null;
    denyHref: string;
}>;

/**
 * WHICH ORGANIZATION AN APP SEES YOU IN.
 *
 * Each organization is a button that answers at once, like the account chooser: choosing
 * is a POST that binds the grant, and a list of radio buttons with a Continue under it
 * would be two clicks for one decision. The suggested one — the app's hint, or where the
 * person was last working — is marked rather than pre-submitted, because a default nobody
 * looked at is the wrong-organization bug this page exists to prevent.
 *
 * Nothing here is remembered: the choice binds this app's sign-in, not the next one's.
 */
export default function ChooseOrganization({
    client,
    me,
    organizations,
    selected,
    chooseHref,
    createHref,
    denyHref,
}: Props) {
    const error = usePage().props.errors.organization;
    const [choosing, setChoosing] = useState<string | null>(null);
    const [cancelling, setCancelling] = useState(false);

    const choose = (id: string) => {
        setChoosing(id);
        router.post(chooseHref, { organization: id }, { onFinish: () => setChoosing(null) });
    };

    return (
        <div>
            <h1 className="text-2xl font-semibold tracking-tight">Choose an organization</h1>
            <p className="mt-1.5 text-sm" style={{ color: 'var(--muted)' }}>
                <b>{client.name}</b> will use the organization you pick, with your role in it.
            </p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                Signed in as {me.email ?? me.name}
            </p>

            {error !== undefined && (
                <p className="field-error mt-4" role="alert">
                    {error}
                </p>
            )}

            {organizations.length === 0 ? (
                <div
                    className="mt-6 rounded-xl border p-4 text-sm"
                    style={{ borderColor: 'var(--border)', color: 'var(--muted-foreground)' }}
                >
                    {createHref !== null
                        ? 'You are not in any organization here yet. Create one to continue.'
                        : 'You are not in any organization here yet. Ask someone to invite you to theirs, then try again.'}
                </div>
            ) : (
                <ul className="mt-6 flex flex-col gap-2" aria-label="Your organizations">
                    {organizations.map((organization) => {
                        const suggested = organization.id === selected;

                        return (
                            <li key={organization.id}>
                                <button
                                    type="button"
                                    onClick={() => choose(organization.id)}
                                    disabled={choosing !== null}
                                    aria-describedby={
                                        suggested ? `suggested-${organization.id}` : undefined
                                    }
                                    className="w-full flex items-center gap-3 rounded-xl border px-3.5 py-3 text-left transition hover:border-[var(--border-strong)] disabled:opacity-60"
                                    style={{
                                        borderColor: suggested
                                            ? 'var(--accent-strong)'
                                            : 'var(--border)',
                                        background: 'var(--card)',
                                    }}
                                >
                                    <Avatar name={organization.name} />

                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">
                                            {organization.name}
                                        </span>
                                        <span
                                            className="block truncate text-sm"
                                            style={{ color: 'var(--muted-foreground)' }}
                                        >
                                            {organization.role}
                                        </span>
                                    </span>

                                    {suggested && (
                                        <span
                                            id={`suggested-${organization.id}`}
                                            className="text-xs font-medium shrink-0"
                                            style={{ color: 'var(--accent-strong)' }}
                                        >
                                            Suggested
                                        </span>
                                    )}

                                    {choosing === organization.id ? (
                                        <Spinner label={`Continuing with ${organization.name}`} />
                                    ) : (
                                        <Icon
                                            name="chevron"
                                            className="w-4 h-4 shrink-0 -rotate-90"
                                            style={{ color: 'var(--faint)' }}
                                        />
                                    )}
                                </button>
                            </li>
                        );
                    })}
                </ul>
            )}

            {createHref !== null && (
                <Link
                    href={createHref}
                    className="mt-3 w-full flex items-center justify-center gap-2 rounded-xl border border-dashed px-3.5 py-3 text-sm font-medium transition"
                    style={{ borderColor: 'var(--border)' }}
                >
                    <Icon name="plus" className="w-4 h-4" /> Create an organization
                </Link>
            )}

            <Button
                variant="ghost"
                className="w-full mt-6"
                loading={cancelling}
                onClick={() => {
                    setCancelling(true);
                    router.post(denyHref, {}, { onFinish: () => setCancelling(false) });
                }}
            >
                Cancel and return to {client.name}
            </Button>
        </div>
    );
}

ChooseOrganization.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
