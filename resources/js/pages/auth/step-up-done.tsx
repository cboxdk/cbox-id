import { useEffect } from 'react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import { STEP_UP_CHANNEL } from '@/lib/stepUp';
import type { PageProps } from '@/types';
import { Button } from '@/ui';

type Props = PageProps<{
    plane: string;
    confirmed: boolean;
    /** Where to go when this is not a window somebody else opened. */
    home: string;
}>;

/**
 * THE END OF A STEP-UP WINDOW (login-host mode).
 *
 * The in-place "Confirm it's you" dialog opened this window, the login host signed the
 * person in again, and the console landed it here. It tells the console page behind it how
 * that went, on a channel only this origin can hear, and closes. The page behind then
 * repeats the request it was holding; the server decides whether the window is open.
 */
export default function StepUpDone({ plane, confirmed, home }: Props) {
    const { t } = useTranslator();

    useEffect(() => {
        const channel = new BroadcastChannel(STEP_UP_CHANNEL);

        channel.postMessage({ plane, confirmed });
        channel.close();

        // Only a window a script opened may close itself; anywhere else this does nothing
        // and the page below says what to do.
        window.close();
    }, [plane, confirmed]);

    return (
        <div className="w-full max-w-sm mx-auto">
            <h1 className="font-semibold tracking-tight" style={{ fontSize: '1.7rem' }}>
                {confirmed
                    ? t('auth.console_sign_in.done_heading')
                    : t('auth.console_sign_in.step_up_heading')}
            </h1>
            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {confirmed
                    ? t('auth.console_sign_in.done_lead')
                    : t('auth.console_sign_in.not_confirmed_lead')}
            </p>

            <Button asChild size="lg" className="mt-7 w-full">
                <a href={home}>{t('auth.console_sign_in.back_to_console')}</a>
            </Button>
        </div>
    );
}

StepUpDone.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
