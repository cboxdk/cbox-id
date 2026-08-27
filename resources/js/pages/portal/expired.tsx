import PortalLayout from '@/layouts/PortalLayout';
import { Icon } from '@/ui';

/**
 * NO ENUMERATION DETAIL, deliberately. Expired, already used, revoked, or never real: the
 * page says the same thing to all four, because the difference is only useful to somebody
 * guessing at tokens.
 */
export default function PortalExpired() {
    return (
        <div className="card p-10 text-center">
            <div
                className="mx-auto grid place-items-center rounded-full"
                style={{
                    width: '2.75rem',
                    height: '2.75rem',
                    background: 'color-mix(in srgb, var(--warning) 15%, transparent)',
                    color: 'var(--warning-strong)',
                }}
            >
                <Icon name="shield" className="w-5 h-5" />
            </div>
            <h1 className="mt-4 text-lg font-semibold tracking-tight">
                This setup link is no longer valid
            </h1>
            <p
                className="mt-2 text-sm leading-relaxed mx-auto"
                style={{ color: 'var(--muted)', maxWidth: '28rem' }}
            >
                The link may have expired or already been used. Setup links are single-use and
                time-limited for security. Ask the person who invited you to send a new one.
            </p>
        </div>
    );
}

PortalExpired.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
