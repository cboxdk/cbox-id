import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button } from '@/ui';

type Props = PageProps<{
    /** A short code for support. Never the explanation. */
    reason: string;
    /** Whether this was a step-up ("confirm it's you") and not a sign-in. */
    stepUp: boolean;
    retryUrl: string;
}>;

/**
 * THE CONSOLE'S SIGN-IN AT THE LOGIN HOST DID NOT COMPLETE (login-host mode).
 *
 * A page, never a redirect back to the start: a login host that keeps giving the same
 * answer would bounce the browser between two hosts. "Try again" is a plain link, because
 * it leaves for the login host.
 */
export default function ConsoleSignInFailed({ reason, stepUp, retryUrl }: Props) {
    const { t } = useTranslator();

    const lead = stepUp
        ? t('auth.console_sign_in.step_up_lead')
        : reason === 'client_not_provisioned'
          ? t('auth.console_sign_in.not_set_up_lead')
          : reason === 'second_factor_required'
            ? t('auth.console_sign_in.second_factor_lead')
            : t('auth.console_sign_in.failed_lead');

    return (
        <div className="w-full max-w-sm mx-auto">
            <h1 className="auth-title">
                {stepUp
                    ? t('auth.console_sign_in.step_up_heading')
                    : t('auth.console_sign_in.failed_heading')}
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {lead}
            </p>

            <Button asChild variant="primary" size="lg" className="mt-7 w-full">
                <a href={retryUrl}>
                    {stepUp
                        ? t('auth.console_sign_in.back_to_console')
                        : t('auth.console_sign_in.try_again')}
                </a>
            </Button>

            <p className="mt-4 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {t('auth.console_sign_in.reference', { reason })}
            </p>
        </div>
    );
}

ConsoleSignInFailed.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
