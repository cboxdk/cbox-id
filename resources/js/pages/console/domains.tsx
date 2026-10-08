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
    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description="Prove which email domains your organization owns. A verified domain can send everyone with an address on it straight to your Enterprise SSO connection."
            />

            {entitled ? (
                <DomainClaims domains={domains} addHref={addDomainHref} />
            ) : (
                <div className="card">
                    <EmptyState
                        icon="shield"
                        title="Domains come with Enterprise SSO"
                        help={help}
                        description="Verified domains route people to your own identity provider, which is part of the Enterprise plan. Contact your account team to enable it for this organization."
                    />
                </div>
            )}
        </div>
    );
}

Domains.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
