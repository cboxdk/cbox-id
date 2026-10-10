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
}

type Props = PageProps<{
    help: HelpContent;
    environmentName: string;
    ssoOnly: boolean;
    sections: { title: string; description: string; rows: MethodRow[] }[];
    organizationsHref: string;
}>;

const STATE: Record<MethodRow['state'], { label: string; tone: 'success' | 'neutral' | 'info' }> = {
    on: { label: 'On', tone: 'success' },
    off: { label: 'Off', tone: 'neutral' },
    optional: { label: 'Optional', tone: 'info' },
};

const DECIDED_BY: Record<MethodRow['decidedBy'], string> = {
    environment: 'Set for this environment',
    organization: 'Set per organization',
    deployment: 'Set by the deployment',
};

/**
 * AUTHENTICATION › SIGN-IN METHODS — every way in, on one page, with where each is changed.
 *
 * Read-only: each row links to the one page whose form writes it, so nothing here can
 * disagree with that page. A row the DEPLOYMENT decides says so and names the variable,
 * instead of drawing a switch the console cannot honour.
 */
export default function SignInMethods({
    help,
    environmentName,
    ssoOnly,
    sections,
    organizationsHref,
}: Props) {
    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={
                    <>
                        Every way people sign in to {environmentName}, whether it is on, and where
                        it is changed. Organizations can make these stricter —{' '}
                        <Link href={organizationsHref}>see which have</Link>.
                    </>
                }
            />

            {ssoOnly && (
                <p className="cbx-callout" role="note">
                    Enterprise SSO is required for this environment, so every other way in is
                    refused until that rule is relaxed on Authentication policy.
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
                                        {DECIDED_BY[row.decidedBy]}
                                        {row.variable !== null && (
                                            <>
                                                {' · '}
                                                <code className="mono">{row.variable}</code>
                                            </>
                                        )}
                                    </p>
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
