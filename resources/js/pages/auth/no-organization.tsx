import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Avatar, Button, Icon, Spinner } from '@/ui';

interface Invitation {
    id: string;
    organization: string;
    /** Who sent it, when that is known. */
    inviter: string | null;
    acceptHref: string;
}

type Props = PageProps<{
    /** The signed-in person's address (their name when they have none). */
    account: string;
    /** Live invitations for their confirmed address; empty until it is confirmed. */
    invitations: Invitation[];
    needsConfirmation: boolean;
    personalHref: string;
    signOutHref: string;
}>;

/**
 * SIGNED IN, AND IN NO ORGANIZATION.
 *
 * Shown before an app or the console, which never see a person without one. Two ways on,
 * and each is one press: an invitation that is waiting for this address, or an
 * organization of their own. Both are POSTs that carry on to wherever the person was going.
 */
export default function NoOrganization({
    account,
    invitations,
    needsConfirmation,
    personalHref,
    signOutHref,
}: Props) {
    const error = usePage().props.errors.invitation;
    const [busy, setBusy] = useState<string | null>(null);
    const { t } = useTranslator();

    const post = (key: string, href: string) => {
        setBusy(key);
        router.post(href, {}, { onFinish: () => setBusy(null) });
    };

    return (
        <div className="w-full max-w-sm mx-auto">
            <h1 className="auth-title">{t('auth.no_organization.heading')}</h1>
            <p className="mt-1.5 text-sm" style={{ color: 'var(--muted)' }}>
                {t('auth.no_organization.lead')}
            </p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {t('auth.no_organization.signed_in_as', { account })}
            </p>

            {error !== undefined && (
                <p className="field-error mt-4" role="alert">
                    {error}
                </p>
            )}

            {invitations.length > 0 && (
                <>
                    <h2 className="mt-6 text-sm font-medium">
                        {t('auth.no_organization.invitations')}
                    </h2>
                    <ul className="mt-2 flex flex-col gap-2">
                        {invitations.map((invitation) => (
                            <li
                                key={invitation.id}
                                className="flex items-center gap-3 rounded-xl border px-3.5 py-3"
                                style={{ borderColor: 'var(--border)', background: 'var(--card)' }}
                            >
                                <Avatar name={invitation.organization} />

                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">
                                        {invitation.organization}
                                    </span>
                                    {invitation.inviter !== null && (
                                        <span
                                            className="block truncate text-sm"
                                            style={{ color: 'var(--muted-foreground)' }}
                                        >
                                            {t('auth.no_organization.invited_by', {
                                                name: invitation.inviter,
                                            })}
                                        </span>
                                    )}
                                </span>

                                <Button
                                    variant="primary"
                                    size="sm"
                                    loading={busy === invitation.id}
                                    disabled={busy !== null}
                                    onClick={() => post(invitation.id, invitation.acceptHref)}
                                >
                                    {t('auth.no_organization.accept')}
                                </Button>
                            </li>
                        ))}
                    </ul>
                </>
            )}

            {needsConfirmation && (
                <p
                    className="mt-6 rounded-xl border p-4 text-sm"
                    style={{ borderColor: 'var(--border)', color: 'var(--muted-foreground)' }}
                >
                    {t('auth.no_organization.confirm_first')}
                </p>
            )}

            <button
                type="button"
                onClick={() => post('personal', personalHref)}
                disabled={busy !== null}
                className="mt-6 w-full flex items-center gap-3 rounded-xl border px-3.5 py-3 text-left transition hover:border-[var(--border-strong)] disabled:opacity-60"
                style={{ borderColor: 'var(--border)', background: 'var(--card)' }}
            >
                <span className="min-w-0 flex-1">
                    <span className="block font-medium">{t('auth.no_organization.personal')}</span>
                    <span className="block text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {t('auth.no_organization.personal_hint')}
                    </span>
                </span>

                {busy === 'personal' ? (
                    <Spinner label={t('auth.no_organization.personal')} />
                ) : (
                    <Icon
                        name="chevron"
                        className="w-4 h-4 shrink-0 -rotate-90"
                        style={{ color: 'var(--faint)' }}
                    />
                )}
            </button>

            <Button
                variant="ghost"
                className="w-full mt-6"
                disabled={busy !== null}
                onClick={() => post('sign-out', signOutHref)}
            >
                {t('auth.no_organization.sign_out')}
            </Button>
        </div>
    );
}

NoOrganization.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
