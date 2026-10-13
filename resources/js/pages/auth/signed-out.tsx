import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button } from '@/ui';

type Props = PageProps<{ signInUrl: string }>;

/**
 * WHERE EVERY SIGN-OUT LANDS — a page, not a door.
 *
 * Sending people to a sign-in door after signing out let an environment console's gate
 * hand them straight back in. This page does nothing by itself; Sign in is a plain link,
 * not an Inertia visit, because on an environment's host it leaves for the account host.
 */
export default function SignedOut({ signInUrl }: Props) {
    const { t } = useTranslator();

    return (
        <div className="w-full max-w-sm mx-auto">
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                {t('auth.signed_out.heading')}
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {t('auth.signed_out.lead')}
            </p>

            <Button asChild variant="primary" size="lg" className="mt-7 w-full">
                <a href={signInUrl}>{t('auth.common.sign_in')}</a>
            </Button>
        </div>
    );
}

SignedOut.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
