import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import AuthLayout from '@/layouts/AuthLayout';
import type { PageProps } from '@/types';
import { Button, ProviderMark } from '@/ui';

type Props = PageProps<{
    provider: string;
    providerName: string;
    scopes: string[];
    appName: string | null;
    clientId: string | null;
    returnTo: string | null;
    authorizeHref: string;
    cancelHref: string;
}>;

/**
 * CONNECT A THIRD-PARTY ACCOUNT — the page an app sends its user to before the provider's
 * own consent screen.
 *
 * It says, in the person's language, where they are about to be sent and what will be
 * asked for, so the provider's screen is not the first they hear of it. Continuing is a
 * POST (a page elsewhere cannot start a connect for them); the server answers with the
 * provider's URL and Inertia leaves for it.
 */
export default function ConnectService({
    provider,
    providerName,
    scopes,
    appName,
    clientId,
    returnTo,
    authorizeHref,
    cancelHref,
}: Props) {
    const { t } = useTranslator();
    const [continuing, setContinuing] = useState(false);

    return (
        <>
            <div className="flex items-center gap-3">
                <ProviderMark provider={provider} size={28} />
                <h1 className="text-xl font-semibold tracking-tight">
                    {t('auth.connect_service.heading', { provider: providerName })}
                </h1>
            </div>

            <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {appName !== null
                    ? t('auth.connect_service.lead_app', { app: appName, provider: providerName })
                    : t('auth.connect_service.lead', { provider: providerName })}
            </p>

            <div
                className="mt-5 rounded-lg p-4 text-sm"
                style={{ background: 'var(--secondary)', border: '1px solid var(--border)' }}
            >
                {scopes.length > 0 ? (
                    <>
                        <p>{t('auth.connect_service.asks_for', { provider: providerName })}</p>
                        <ul className="mt-2 flex flex-wrap gap-1.5">
                            {scopes.map((scope) => (
                                <li key={scope}>
                                    <code
                                        className="rounded px-1.5 py-0.5 text-xs"
                                        style={{
                                            background: 'var(--background)',
                                            border: '1px solid var(--border)',
                                        }}
                                    >
                                        {scope}
                                    </code>
                                </li>
                            ))}
                        </ul>
                    </>
                ) : (
                    <p>{t('auth.connect_service.no_scopes', { provider: providerName })}</p>
                )}
            </div>

            <div className="mt-6 flex flex-col-reverse gap-2.5 sm:flex-row">
                <Button asChild className="sm:flex-1">
                    <a href={returnTo ?? cancelHref}>{t('auth.connect_service.cancel')}</a>
                </Button>
                <Button
                    variant="primary"
                    className="sm:flex-1"
                    loading={continuing}
                    onClick={() => {
                        setContinuing(true);
                        router.post(
                            authorizeHref,
                            { client_id: clientId, return_to: returnTo },
                            { onFinish: () => setContinuing(false) },
                        );
                    }}
                >
                    {t('auth.connect_service.continue', { provider: providerName })}
                </Button>
            </div>

            <p className="mt-5 text-xs" style={{ color: 'var(--faint)' }}>
                {t('auth.connect_service.later')}
            </p>
        </>
    );
}

ConnectService.layout = (page: React.ReactNode) => <AuthLayout>{page}</AuthLayout>;
