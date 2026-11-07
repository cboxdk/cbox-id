import { useTranslator as useFormatTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { locale: consoleLocale } = useFormatTranslator();
    const { t: consoleFinalText, choice: consoleChoice } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    if (warnings.length === 0) {
        return null;
    }

    return (
        <section
            aria-label={consoleT('console.expiring_saml_certificates_f8d6cdf6')}
            className="rounded-xl border p-4"
            style={{
                borderColor: 'color-mix(in oklch, var(--warning) 45%, transparent)',
                background: 'color-mix(in oklch, var(--warning) 8%, transparent)',
            }}
        >
            <p className="flex items-center gap-2 text-sm font-semibold">
                <Icon name="warning" className="w-4 h-4" style={{ color: 'var(--warning)' }} />
                {consoleChoice('console.certificates_need_renewing', warnings.length)}
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
                            ? consoleFinalText('console.stopped_working_on_part1_0fe9d638', {
                                  part1: formatDate(warning.expires_at, consoleLocale),
                              })
                            : warning.days_remaining === 0
                              ? consoleText('console.stops_working_today_3f4502eb')
                              : consoleChoice(
                                    'console.certificate_expires_in_days',
                                    warning.days_remaining,
                                    { date: formatDate(warning.expires_at, consoleLocale) },
                                )}
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {consoleT(
                    'console.send_their_it_administrator_an_admin_portal_link_for_sa_59852a2e',
                )}
            </p>
        </section>
    );
}

function formatDate(iso: string, locale: string): string {
    return new Date(iso).toLocaleDateString(locale, { dateStyle: 'medium' });
}
