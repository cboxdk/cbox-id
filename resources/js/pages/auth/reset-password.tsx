import { Link, useForm } from '@inertiajs/react';
import { useTranslator } from '@/i18n';
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
    const { t } = useTranslator();
    const form = useForm({
        token,
        password: '',
        password_confirmation: '',
    });

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                {t('auth.reset_password.title')}
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {t('auth.reset_password.lead', { count: 12 })}
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
                    label={t('auth.common.new_password')}
                    name="password"
                    autoComplete="new-password"
                    placeholder={t('auth.password_field.policy', { count: 12 })}
                    policy
                    policyLabel={t('auth.password_field.policy', { count: 12 })}
                    showLabel={t('auth.password_field.show')}
                    hideLabel={t('auth.password_field.hide')}
                    error={form.errors.password}
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                />

                <PasswordField
                    label={t('auth.common.confirm_new_password')}
                    name="password_confirmation"
                    autoComplete="new-password"
                    placeholder={t('auth.reset_password.confirm_placeholder')}
                    showLabel={t('auth.password_field.show')}
                    hideLabel={t('auth.password_field.hide')}
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
                    {t('auth.reset_password.submit')}
                </Button>
            </form>

            <p className="mt-6 text-sm text-center" style={{ color: 'var(--muted-foreground)' }}>
                <Link
                    href={login.url()}
                    className="font-medium underline underline-offset-2"
                    style={{ color: 'var(--accent-strong)' }}
                >
                    {t('auth.common.back_to_sign_in')}
                </Link>
            </p>
        </>
    );
}

ResetPassword.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
