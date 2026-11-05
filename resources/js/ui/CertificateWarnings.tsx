import { useUiTranslator } from '@/i18n/ui';
import { Link } from '@inertiajs/react';
import type { CertificateWarning } from '@/types';
import { Icon } from './Icon';

/**
 * SAML signing certificates about to stop working — the console's half of the daily
 * certificate scan, drawn on an organization's Overview and on its SSO tab.
 *
 * Said in the warning colour and above the fold, because the failure it warns of is a
 * whole organization unable to sign in on a date somebody set years ago. Each line links to
 * the connection, and the advice is the one that fixes it: an Admin Portal link with
 * certificate renewal, for the customer's IT administrator to upload the new one.
 */
export function CertificateWarnings({ warnings }: { warnings: CertificateWarning[] }) {
    const { t: tc } = useUiTranslator();

    if (warnings.length === 0) {
        return null;
    }

    return (
        <section
            aria-label={tc(
                'console.expiring_saml_certificates_f8d6cdf6',
                'Expiring SAML certificates',
            )}
            className="rounded-xl border p-4"
            style={{
                borderColor: 'color-mix(in oklch, var(--warning) 45%, transparent)',
                background: 'color-mix(in oklch, var(--warning) 8%, transparent)',
            }}
        >
            <p className="flex items-center gap-2 text-sm font-semibold">
                <Icon name="warning" className="w-4 h-4" style={{ color: 'var(--warning)' }} />
                {warnings.length === 1
                    ? tc(
                          'console.a_saml_certificate_needs_renewing_a8bb7261',
                          'A SAML certificate needs renewing',
                      )
                    : `${warnings.length} SAML certificates need renewing`}
            </p>
            <ul className="mt-2 space-y-1 text-sm">
                {warnings.map((warning) => (
                    <li key={warning.connection_id}>
                        <Link
                            href={warning.href}
                            className="font-medium"
                            style={{ color: 'var(--accent)' }}
                        >
                            {warning.name}
                        </Link>{' '}
                        {warning.expired
                            ? `stopped working on ${formatDate(warning.expires_at)}.`
                            : warning.days_remaining === 0
                              ? tc('console.stops_working_today_3f4502eb', 'stops working today.')
                              : `stops working in ${warning.days_remaining} ${warning.days_remaining === 1 ? 'day' : 'days'} (${formatDate(warning.expires_at)}).`}
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {tc(
                    'console.send_their_it_administrator_an_admin_portal__59852a2e',
                    'Send their IT administrator an Admin Portal link for SAML certificate renewal, or stage the new certificate yourself through the API.',
                )}
            </p>
        </section>
    );
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
}
