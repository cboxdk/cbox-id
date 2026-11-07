import { useTranslator as usePublicTranslator } from '@/i18n';
import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    /** Authentication policy, where the require-SSO rule is relaxed. */
    policyHref: string;
    sections: { title: string; description: string; rows: MethodRow[] }[];
    /** The per-organization table — the environment console only. */
    organizationsHref: string | null;
}>;

const STATE = (
    t: Translator['t'],
): Record<MethodRow['state'], { label: string; tone: 'success' | 'neutral' | 'info' }> => ({
    on: { label: t('console.on_13001175'), tone: 'success' },
    off: { label: t('console.off_ca7981b4'), tone: 'neutral' },
    optional: { label: t('console.optional_59be7133'), tone: 'info' },
});

/** Whose decision a row is — said from where the reader stands. */
function decidedBy(row: MethodRow, onOrganization: boolean, tc: Translator['t']): string {
    switch (row.decidedBy) {
        case 'environment':
            return onOrganization
                ? tc('console.set_for_the_whole_environment_3cd0904a')
                : tc('console.set_for_this_environment_fd574a4a');
        case 'organization':
            return onOrganization
                ? tc('console.set_for_this_organization_df0a603f')
                : tc('console.set_per_organization_382d12ad');
        default:
            return tc('console.set_by_the_deployment_f0937a66');
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
    policyHref,
    sections,
    organizationsHref,
}: Props) {
    const { t: tc } = usePublicTranslator();
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleOptionText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const onOrganization = organizationName !== null;

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={
                    organizationsHref === null ? (
                        <>
                            {consoleRich(
                                'console.every_way_people_sign_in_to_part1_whether_it_is_on_and_5da64a1a',
                                { part1: organizationName ?? environmentName },
                            )}
                        </>
                    ) : (
                        <>
                            {consoleRich(
                                'console.every_way_people_sign_in_to_part1_whether_it_is_on_and_4dda4364',
                                {
                                    part1: environmentName,
                                    part2: ' ',
                                    part3: (
                                        <Link href={organizationsHref}>
                                            {consoleT('console.see_which_have_18f4c612')}
                                        </Link>
                                    ),
                                },
                            )}
                        </>
                    )
                }
            />

            {ssoOnly && (
                <p className="cbx-callout" role="note">
                    {consoleRich(
                        onOrganization
                            ? 'console.sso_required_organization'
                            : 'console.sso_required_environment',
                        {
                            organization: organizationName,
                            policy: (
                                <Link href={policyHref}>
                                    {consoleT('console.authentication_policy_07e21daf')}
                                </Link>
                            ),
                        },
                    )}
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
                                        <Pill tone={STATE(consoleOptionText)[row.state].tone}>
                                            {STATE(consoleOptionText)[row.state].label}
                                        </Pill>
                                    </div>
                                    <p className="cbx-method-summary">{row.summary}</p>
                                    <p className="cbx-method-owner">
                                        {decidedBy(row, onOrganization, tc)}
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
                                        aria-label={`${row.hrefLabel ?? consoleShortText('console.change_c0bf75bd')}: ${row.label}`}
                                    >
                                        {row.hrefLabel ??
                                            consoleShortText('console.change_c0bf75bd')}
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
