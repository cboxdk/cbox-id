import { useTranslator } from '@/i18n';
import PortalLayout from '@/layouts/PortalLayout';
import type { PageProps } from '@/types';
import { DomainManager, type DomainRow, type PortalChrome, PortalHeader } from './parts';

type Props = PageProps<{
    portal: PortalChrome;
    domains: DomainRow[];
    addHref: string;
}>;

/**
 * DOMAIN VERIFICATION — add a domain, publish the TXT record, check it. The whole flow is
 * {@see DomainManager}, which single sign-on's page draws too.
 */
export default function PortalDomains({ portal, domains, addHref }: Props) {
    const { t } = useTranslator();

    return (
        <div>
            <PortalHeader
                portal={portal}
                title={t('portal.domains.title')}
                lead={t('portal.domains.lead')}
            />
            <DomainManager domains={domains} addHref={addHref} />
        </div>
    );
}

PortalDomains.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
