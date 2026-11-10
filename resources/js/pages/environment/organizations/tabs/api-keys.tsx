import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <Panel
            title={consoleT('console.member_api_keys_66d7cf0e')}
            description={consoleT(
                'console.keys_this_organization_s_people_have_created_for_its_ap_9d7e0611',
            )}
            flush={apiKeys.length > 0}
        >
            <AppApiKeyList
                keys={apiKeys}
                empty={{
                    title: consoleText('console.no_api_keys_860f0852'),
                    description: (
                        <>
                            {consoleRich(
                                'console.nobody_in_this_organization_has_created_a_key_for_one_o_d0240315',
                                {
                                    part1: (
                                        <Link href={appsHref}>
                                            {consoleT('console.see_your_apps_83cc472a')}
                                        </Link>
                                    ),
                                },
                            )}
                        </>
                    ),
                }}
                consequence={consoleUiText(
                    'console.whatever_the_holder_has_wired_this_key_into_stops_worki_789a1199',
                )}
            />
        </Panel>
    );
}

OrganizationApiKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
