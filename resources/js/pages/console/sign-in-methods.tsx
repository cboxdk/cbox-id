import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Icon, PageHeader, Panel, Pill } from '@/ui';

interface MethodRow {
    key: string;
    label: string;
    state: 'on' | 'off' | 'optional';
    summary: string;
    /** Whose decision it is: this environment's form, each organization's, or the deployment's. */
    decidedBy: 'environment' | 'organization' | 'deployment';
    href: string | null;
    hrefLabel: string | null;
    /** The deployment variable that decides it, when the deployment does. */
    variable: string | null;
    /** What bounds the setting from above — the deployment's limit, or how inheritance works. */
    ceiling: string | null;
}

type Props = PageProps<{
    help: HelpContent;
    environmentName: string;
    /** On an organization's own console: whose sign-in this page describes. */
    organizationName: string | null;
    ssoOnly: boolean;
    sections: { title: string; description: string; rows: MethodRow[] }[];
    /** The per-organization table — the environment console only. */
    organizationsHref: string | null;
}>;

const STATE: Record<MethodRow['state'], { label: string; tone: 'success' | 'neutral' | 'info' }> = {
    on: { label: 'On', tone: 'success' },
    off: { label: 'Off', tone: 'neutral' },
    optional: { label: 'Optional', tone: 'info' },
};

/** Whose decision a row is — said from where the reader stands. */
function decidedBy(row: MethodRow, onOrganization: boolean): string {
    switch (row.decidedBy) {
        case 'environment':
            return onOrganization ? 'Set for the whole environment' : 'Set for this environment';
        case 'organization':
            return onOrganization ? 'Set for this organization' : 'Set per organization';
        default:
            return 'Set by the deployment';
    }
}

/**
 * AUTHENTICATION › SIGN-IN METHODS — every way in, on one page, with where each is changed.
 *
 * Read-only: each row links to the one page whose form writes it, so nothing here can
 * disagree with that page. A row the DEPLOYMENT decides says so and names the variable,
 * instead of drawing a switch the console cannot honour; a row the environment decides
 * under the deployment's limit says what that limit is.
 *
 * On an organization's console (a single-tenant install's whole administration) the page
 * describes that organization's sign-in, and rows only the environment can change carry no
 * link.
 */
export default function SignInMethods({
    help,
    environmentName,
    organizationName,
    ssoOnly,
    sections,
    organizationsHref,
}: Props) {
    const onOrganization = organizationName !== null;

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={
                    organizationsHref === null ? (
                        <>
                            Every way people sign in to {organizationName ?? environmentName},
                            whether it is on, and where it is changed.
                        </>
                    ) : (
                        <>
                            Every way people sign in to {environmentName}, whether it is on, and
                            where it is changed. Organizations can make these stricter —{' '}
                            <Link href={organizationsHref}>see which have</Link>.
                        </>
                    )
                }
            />

            {ssoOnly && (
                <p className="cbx-callout" role="note">
                    Enterprise SSO is required{' '}
                    {onOrganization ? `for ${organizationName}` : 'for this environment'}, so every
                    other way in is refused until that rule is relaxed on Authentication policy.
                </p>
            )}

            {sections.map((section) => (
                <Panel
                    key={section.title}
                    title={section.title}
                    description={section.description}
                    flush
                >
                    <ul className="cbx-method-list">
                        {section.rows.map((row) => (
                            <li key={row.key} className="cbx-method" data-method={row.key}>
                                <div className="cbx-method-main">
                                    <div className="cbx-method-title">
                                        <span className="font-medium">{row.label}</span>
                                        <Pill tone={STATE[row.state].tone}>
                                            {STATE[row.state].label}
                                        </Pill>
                                    </div>
                                    <p className="cbx-method-summary">{row.summary}</p>
                                    <p className="cbx-method-owner">
                                        {decidedBy(row, onOrganization)}
                                        {row.variable !== null && (
                                            <>
                                                {' · '}
                                                <code className="mono">{row.variable}</code>
                                            </>
                                        )}
                                    </p>
                                    {row.ceiling !== null && (
                                        <p className="cbx-method-owner">{row.ceiling}</p>
                                    )}
                                </div>
                                {row.href !== null && (
                                    <Link
                                        href={row.href}
                                        className="btn btn-secondary btn-sm cbx-method-action"
                                        aria-label={`${row.hrefLabel ?? 'Change'}: ${row.label}`}
                                    >
                                        {row.hrefLabel ?? 'Change'}
                                        <Icon
                                            name="chevron"
                                            className="w-3.5 h-3.5"
                                            style={{ transform: 'rotate(-90deg)' }}
                                        />
                                    </Link>
                                )}
                            </li>
                        ))}
                    </ul>
                </Panel>
            ))}
        </div>
    );
}

SignInMethods.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
