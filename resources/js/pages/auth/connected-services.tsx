import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import AccountLayout from '@/layouts/AccountLayout';
import type { PageProps } from '@/types';
import { Button, Dialog, PageHeader, Panel, Pill, ProviderMark } from '@/ui';

type Service = {
    provider: string;
    name: string;
    connected: boolean;
    needsReauth: boolean;
    account: string | null;
    scopes: string[];
    connectedAt: string | null;
    connectHref: string | null;
    disconnectHref: string | null;
};

type Props = PageProps<{
    services: Service[];
    accountHref: string;
}>;

/**
 * CONNECTED SERVICES — the third-party accounts (GitHub, Google, Slack…) a person has
 * connected so apps here can work with them, and the way to take each one back.
 *
 * Not the social sign-in links on the Security page: nobody signs in through these. Each
 * row is either a Connect button or a Disconnect button, plus a Reconnect when the
 * provider stopped accepting the connection.
 */
export default function ConnectedServices({ services }: Props) {
    const { t } = useTranslator();
    const { errors, flash } = usePage().props;
    const [disconnecting, setDisconnecting] = useState<Service | null>(null);
    const error = typeof errors.disconnect === 'string' ? errors.disconnect : flash.error;

    return (
        <div className="space-y-6">
            <PageHeader
                title={t('auth.connected_services.heading')}
                description={t('auth.connected_services.lead')}
            />

            {flash.status !== null && (
                <output className="block text-sm" style={{ color: 'var(--success)' }}>
                    {flash.status}
                </output>
            )}
            {error !== null && error !== undefined && (
                <p className="field-error" role="alert">
                    {error}
                </p>
            )}

            <Panel>
                {services.length === 0 ? (
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {t('auth.connected_services.empty')}
                    </p>
                ) : (
                    <ul className="-my-3 divide-y" style={{ borderColor: 'var(--border)' }}>
                        {services.map((service) => (
                            <li
                                key={service.provider}
                                className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 py-3"
                            >
                                <div className="flex min-w-0 items-start gap-3">
                                    <ProviderMark provider={service.provider} size={22} />
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{service.name}</span>
                                            {service.needsReauth ? (
                                                <Pill tone="warning">
                                                    {t('auth.connected_services.needs_reauth')}
                                                </Pill>
                                            ) : service.connected ? (
                                                <Pill tone="success">
                                                    {t('auth.connected_services.connected_label')}
                                                </Pill>
                                            ) : (
                                                <Pill>
                                                    {t('auth.connected_services.not_connected')}
                                                </Pill>
                                            )}
                                        </div>
                                        {service.account !== null && (
                                            <p
                                                className="mt-0.5 truncate text-xs"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                {t('auth.connected_services.connected_as', {
                                                    account: service.account,
                                                })}
                                            </p>
                                        )}
                                        {service.needsReauth && (
                                            <p
                                                className="mt-0.5 text-xs"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                {t('auth.connected_services.needs_reauth_hint', {
                                                    provider: service.name,
                                                })}
                                            </p>
                                        )}
                                        {service.connected && service.scopes.length > 0 && (
                                            <p
                                                className="mt-0.5 text-xs"
                                                style={{ color: 'var(--faint)' }}
                                            >
                                                {t('auth.connected_services.access', {
                                                    scopes: service.scopes.join(', '),
                                                })}
                                            </p>
                                        )}
                                    </div>
                                </div>
                                <div className="flex shrink-0 gap-2">
                                    {service.connectHref !== null &&
                                        (!service.connected || service.needsReauth) && (
                                            <Button
                                                asChild
                                                size="sm"
                                                variant={
                                                    service.needsReauth ? 'primary' : undefined
                                                }
                                            >
                                                <a href={service.connectHref}>
                                                    {service.needsReauth
                                                        ? t('auth.connected_services.reconnect')
                                                        : t('auth.connected_services.connect')}
                                                </a>
                                            </Button>
                                        )}
                                    {service.disconnectHref !== null && (
                                        <Button
                                            size="sm"
                                            variant="danger"
                                            onClick={() => setDisconnecting(service)}
                                        >
                                            {t('auth.connected_services.disconnect')}
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>

            <Dialog
                open={disconnecting !== null}
                onOpenChange={(open) => !open && setDisconnecting(null)}
                title={
                    disconnecting === null
                        ? ''
                        : t('auth.connected_services.disconnect_title', {
                              provider: disconnecting.name,
                          })
                }
                description={
                    disconnecting === null
                        ? ''
                        : t('auth.connected_services.disconnect_body', {
                              provider: disconnecting.name,
                          })
                }
                footer={
                    <>
                        <Button onClick={() => setDisconnecting(null)}>
                            {t('auth.connected_services.cancel')}
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                const service = disconnecting;
                                setDisconnecting(null);

                                if (service !== null && service.disconnectHref !== null) {
                                    router.delete(service.disconnectHref, { preserveScroll: true });
                                }
                            }}
                        >
                            {t('auth.connected_services.disconnect')}
                        </Button>
                    </>
                }
            />
        </div>
    );
}

ConnectedServices.layout = (page: React.ReactNode) => <AccountLayout>{page}</AccountLayout>;
