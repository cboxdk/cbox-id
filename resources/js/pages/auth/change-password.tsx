import { useForm } from '@inertiajs/react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, PasswordField, PasswordManagerIdentity } from '@/ui';
import { update as changePassword } from '@routes/password/change';

type Props = PageProps<{ email: string | null }>;

/**
 * THE FORCED PASSWORD CHANGE.
 *
 * The current password is deliberately not asked for: the person just proved it to reach
 * this session, and asking again would only tempt somebody who was handed a password by
 * their administrator into writing it down.
 */
export default function ChangePassword({ email }: Props) {
    const form = useForm({ password: '', password_confirmation: '' });
    const { t } = useTranslator();

    return (
        <>
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                {t('auth.change_password.title')}
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {t('auth.change_password.lead')}
            </p>

            <form
                className="mt-7 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(changePassword.url());
                }}
            >
                <PasswordManagerIdentity username={email ?? undefined} />

                <PasswordField
                    label={t('auth.common.new_password')}
                    name="password"
                    autoComplete="new-password"
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
                    {t('auth.change_password.submit')}
                </Button>
            </form>
        </>
    );
}

ChangePassword.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
