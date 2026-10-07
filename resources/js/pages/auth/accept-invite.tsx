import { useForm } from '@inertiajs/react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, PasswordField, PasswordManagerIdentity } from '@/ui';

type Props = PageProps<{
    email: string;
    organizationName: string | null;
    inviterName: string | null;
    roleLabel: string;
    /** Signed, and minted on this page — see the controller for why the write is signed too. */
    acceptUrl: string;
}>;

/**
 * SET A PASSWORD AND JOIN.
 *
 * The address is the invitation's and is shown rather than asked for: it is what the link
 * was sent to, and letting somebody change it here would let one person's invitation
 * create another person's account.
 */
export default function AcceptInvite({
    email,
    organizationName,
    inviterName,
    roleLabel,
    acceptUrl,
}: Props) {
    const { t, rich } = useTranslator();
    const form = useForm({ password: '' });

    const emphasis = (text: string) => (
        <span className="font-medium" style={{ color: 'var(--foreground)' }}>
            {text}
        </span>
    );

    const facts = {
        organization: emphasis(organizationName ?? t('auth.accept_invite.organization_fallback')),
        role: emphasis(roleLabel),
        email: emphasis(email),
    };

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                {t('auth.accept_invite.heading')}
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {inviterName !== null
                    ? rich('auth.accept_invite.lead_from', { ...facts, inviter: emphasis(inviterName) })
                    : rich('auth.accept_invite.lead', facts)}
            </p>

            <form
                className="mt-7 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(acceptUrl);
                }}
            >
                <PasswordManagerIdentity username={email} />

                <PasswordField
                    label={t('auth.accept_invite.password_label')}
                    name="password"
                    autoComplete="new-password"
                    placeholder={t('auth.password_field.policy', { count: 12 })}
                    policy
                    showLabel={t('auth.password_field.show')}
                    hideLabel={t('auth.password_field.hide')}
                    policyLabel={t('auth.password_field.policy', { count: 12 })}
                    error={form.errors.password}
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                />

                <p className="text-xs" style={{ color: 'var(--faint)' }}>
                    {t('auth.accept_invite.breach_note')}
                </p>

                <Button
                    type="submit"
                    variant="primary"
                    size="lg"
                    className="w-full"
                    loading={form.processing}
                >
                    {t('auth.accept_invite.submit')}
                </Button>
            </form>
        </>
    );
}

AcceptInvite.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
