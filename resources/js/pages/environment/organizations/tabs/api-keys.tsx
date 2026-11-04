import { useTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { type AppApiKey, AppApiKeyList, Panel } from '@/ui';

type Props = PageProps<{
    /** Every API key the organization's people hold for its apps. Seen and revoked, never minted here. */
    apiKeys: AppApiKey[];
    /** Applications, where an app turns member API keys on. */
    appsHref: string;
}>;

/**
 * AN ORGANIZATION › API KEYS — the keys its people created for its apps. The header and tabs
 * are the layout's (`../frame.tsx`).
 */
export default function OrganizationApiKeys({ apiKeys, appsHref }: Props) {
    const { t: tc } = useTranslator();

    return (
        <Panel
            title={tc('console.member_api_keys_66d7cf0e')}
            description={tc('console.keys_this_organization_s_people_have_created_9d7e0611')}
            flush={apiKeys.length > 0}
        >
            <AppApiKeyList
                keys={apiKeys}
                empty={{
                    title: tc('console.no_api_keys_860f0852'),
                    description: (
                        <>
                            {tc('console.nobody_in_this_organization_has_created_a_ke_327ef1b5')}
                            <Link href={appsHref}>{tc('console.see_your_apps_83cc472a')}</Link>.
                        </>
                    ),
                }}
                consequence={tc('console.whatever_the_holder_has_wired_this_key_into__789a1199')}
            />
        </Panel>
    );
}

OrganizationApiKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
