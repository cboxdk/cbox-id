import { useForm } from '@inertiajs/react';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, PasswordField, PasswordManagerIdentity } from '@/ui';

type Props = PageProps<{
    email: string;
    organizationName: string | null;
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
export default function AcceptInvite({ email, organizationName, acceptUrl }: Props) {
    const form = useForm({ password: '' });

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                Accept your invitation
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Set a password to join{' '}
                <span className="font-medium" style={{ color: 'var(--foreground)' }}>
                    {organizationName ?? 'the organization'}
                </span>{' '}
                as{' '}
                <span className="font-medium" style={{ color: 'var(--foreground)' }}>
                    {email}
                </span>
                .
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
                    label="Choose a password"
                    name="password"
                    autoComplete="new-password"
                    placeholder="At least 12 characters"
                    policy
                    error={form.errors.password}
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                />

                <p className="text-xs" style={{ color: 'var(--faint)' }}>
                    Checked against known breaches.
                </p>

                <Button
                    type="submit"
                    variant="primary"
                    size="lg"
                    className="w-full"
                    loading={form.processing}
                >
                    Accept &amp; sign in
                </Button>
            </form>
        </>
    );
}

AcceptInvite.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
