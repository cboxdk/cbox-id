import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { type DomainClaim, DomainClaims } from '@/ui';

type Props = PageProps<{
    domains: DomainClaim[];
    addDomainHref: string;
}>;

/**
 * AN ORGANIZATION › DOMAINS. The header and tabs are the layout's (`../frame.tsx`); the list
 * is the same one the organization console's own Domains page draws.
 */
export default function OrganizationDomains({ domains, addDomainHref }: Props) {
    return <DomainClaims domains={domains} addHref={addDomainHref} />;
}

OrganizationDomains.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
