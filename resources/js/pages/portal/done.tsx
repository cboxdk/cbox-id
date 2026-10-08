import { usePage } from '@inertiajs/react';
import { useTranslator } from '@/i18n';
import PortalLayout from '@/layouts/PortalLayout';
import { Icon } from '@/ui';

/**
 * The end of a setup link, and the end of the session with it.
 *
 * Its own page rather than a state of the setup screen: finishing CLEARS the portal
 * session, so re-rendering that screen would be answered by the middleware's bounce to
 * "this link is no longer valid" — the wrong sentence for somebody who just succeeded.
 */
export default function PortalDone() {
    const organization = usePage().flash.portalOrganization;
    const auditLogs = usePage().flash.portalAuditLogs === true;
    const { t } = useTranslator();

    return (
        <div className="card p-10 text-center">
            <div
                className="mx-auto grid place-items-center rounded-full"
                style={{
                    width: '2.75rem',
                    height: '2.75rem',
                    background: 'var(--success-soft)',
                    color: 'var(--success-strong)',
                }}
            >
                <Icon name="check" className="w-5 h-5" />
            </div>
            <h1 className="mt-4 text-lg font-semibold tracking-tight">
                {t('portal.done.heading')}
            </h1>
            <p
                className="mt-2 text-sm leading-relaxed mx-auto"
                style={{ color: 'var(--muted)', maxWidth: '28rem' }}
            >
                {t(auditLogs ? 'portal.done.audit_logs_body' : 'portal.done.body', {
                    organization: organization ?? t('portal.done.this_organization'),
                })}
            </p>
        </div>
    );
}

PortalDone.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
