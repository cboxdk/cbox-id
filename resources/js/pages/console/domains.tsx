import { useTranslator as useConsoleTranslator } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { type DomainClaim, DomainClaims, EmptyState, PageHeader } from '@/ui';

type Props = PageProps<{
    help: HelpContent;
    entitled: boolean;
    domains: DomainClaim[];
    addDomainHref: string;
}>;

/**
 * CONSOLE › DOMAINS — the email domains this organization claims, on a page of their own.
 *
 * The Enterprise SSO page lists them too, beside its connections; this is where somebody
 * who came for "domains" looks. Adding, verifying, capturing and removing are the SSO
 * page's own writes, gated on the same plan feature.
 */
export default function Domains({ help, entitled, domains, addDomainHref }: Props) {
    const { t: consoleT } = useConsoleTranslator();
    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={consoleT(
                    'console.prove_which_email_domains_your_organization_owns_a_veri_a1717fad',
                )}
            />

            {entitled ? (
                <DomainClaims domains={domains} addHref={addDomainHref} />
            ) : (
                <div className="card">
                    <EmptyState
                        icon="shield"
                        title={consoleT('console.domains_come_with_enterprise_sso_be43d549')}
                        help={help}
                        description={consoleT(
                            'console.verified_domains_route_people_to_your_own_identity_prov_127a9e78',
                        )}
                    />
                </div>
            )}
        </div>
    );
}

Domains.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
