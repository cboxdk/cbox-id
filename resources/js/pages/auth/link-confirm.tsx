import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button } from '@/ui';
import { connect, decline } from '@routes/link';

type Props = PageProps<{
    provider: string;
    email: string | null;
    name: string | null;
}>;

/**
 * CONNECT THIS PROVIDER, OR SAY IT WAS NOT YOU.
 *
 * Both answers are buttons of equal weight, and the declining one is FIRST in the DOM on
 * a narrow screen (reversed visually) — because a person who reaches this screen without
 * having just signed in with that provider is looking at somebody else's attempt to use
 * their address, and "no" is the answer that needs to be easy to reach.
 */
export default function LinkConfirm({ provider, email }: Props) {
    const { t, rich } = useTranslator();
    const [answering, setAnswering] = useState<'connect' | 'decline' | null>(null);

    return (
        <>
            <h1 className="text-xl font-semibold tracking-tight">
                {t('auth.link_confirm.heading', { provider })}
            </h1>

            <p className="mt-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {email !== null
                    ? rich('auth.link_confirm.lead_email', {
                          provider,
                          email: (
                              <span className="font-medium" style={{ color: 'var(--foreground)' }}>
                                  {email}
                              </span>
                          ),
                      })
                    : t('auth.link_confirm.lead', { provider })}
            </p>

            <div
                className="mt-5 rounded-lg p-4 text-sm"
                style={{ background: 'var(--secondary)', border: '1px solid var(--border)' }}
            >
                <p>
                    {rich('auth.link_confirm.was_you', {
                        emphasis: <b>{t('auth.link_confirm.was_you_emphasis')}</b>,
                        provider,
                    })}
                </p>
                <p className="mt-2.5" style={{ color: 'var(--muted-foreground)' }}>
                    {rich('auth.link_confirm.was_not_you', {
                        emphasis: <b>{t('auth.link_confirm.was_not_you_emphasis')}</b>,
                    })}
                </p>
            </div>

            <div className="mt-6 flex flex-col-reverse gap-2.5 sm:flex-row">
                <Button
                    className="sm:flex-1"
                    loading={answering === 'decline'}
                    onClick={() => {
                        setAnswering('decline');
                        router.post(decline.url());
                    }}
                >
                    {t('auth.link_confirm.decline')}
                </Button>

                <Button
                    variant="primary"
                    className="sm:flex-1"
                    loading={answering === 'connect'}
                    onClick={() => {
                        setAnswering('connect');
                        router.post(connect.url());
                    }}
                >
                    {t('auth.link_confirm.connect', { provider })}
                </Button>
            </div>

            <p className="mt-5 text-xs" style={{ color: 'var(--faint)' }}>
                {t('auth.link_confirm.disconnect_hint', { provider })}
            </p>
        </>
    );
}

LinkConfirm.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
