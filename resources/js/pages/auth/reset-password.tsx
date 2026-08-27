import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, PasswordField, PasswordManagerIdentity } from '@/ui';
import { login } from '@routes';
import { update } from '@routes/password';

type Props = PageProps<{ token: string }>;

/**
 * SETTING A NEW PASSWORD FROM A RESET LINK.
 *
 * The page never resolves the token to a person, and does not display who it is for.
 * That is not an omission — a page that greets you by name is an account-existence
 * oracle for anybody holding a guessed token.
 */
export default function ResetPassword({ token }: Props) {
    const form = useForm({
        token,
        password: '',
        password_confirmation: '',
    });

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                Choose a new password
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Pick a strong password of at least 12 characters.
            </p>

            <form
                className="mt-7 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(update.url());
                }}
            >
                <PasswordManagerIdentity />

                <PasswordField
                    label="New password"
                    name="password"
                    autoComplete="new-password"
                    placeholder="At least 12 characters"
                    policy
                    error={form.errors.password}
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                />

                <PasswordField
                    label="Confirm new password"
                    name="password_confirmation"
                    autoComplete="new-password"
                    placeholder="Re-enter your new password"
                    error={form.errors.password_confirmation}
                    value={form.data.password_confirmation}
                    onChange={(event) =>
                        form.setData('password_confirmation', event.target.value)
                    }
                />

                <Button
                    type="submit"
                    variant="primary"
                    size="lg"
                    className="w-full"
                    loading={form.processing}
                >
                    Reset password
                </Button>
            </form>

            <p className="mt-6 text-sm text-center" style={{ color: 'var(--muted-foreground)' }}>
                <Link
                    href={login.url()}
                    className="font-medium underline underline-offset-2"
                    style={{ color: 'var(--accent-strong)' }}
                >
                    Back to sign in
                </Link>
            </p>
        </>
    );
}

ResetPassword.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
