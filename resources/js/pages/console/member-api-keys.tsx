import { useTranslator as useConsoleTranslator } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { type AppApiKey, AppApiKeyList, PageHeader, Panel } from '@/ui';

type Props = PageProps<{
    help: HelpContent;
    keys: AppApiKey[];
}>;

/**
 * Every API key this organization's people hold for its apps. Administrators see and
 * revoke; nobody creates a key for somebody else — each person makes their own under
 * My account.
 */
export default function MemberApiKeys({ help, keys }: Props) {
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={consoleT(
                    'console.every_api_key_the_people_in_this_organization_have_crea_15e73625',
                )}
            />

            <Panel title={consoleT('console.keys_f0d66a79')} flush={keys.length > 0}>
                <AppApiKeyList
                    keys={keys}
                    empty={{
                        title: consoleText('console.nobody_here_has_created_a_key_yet_84e776e8'),
                        description: consoleText(
                            'console.when_somebody_creates_an_api_key_for_one_of_your_apps_i_15c6370c',
                        ),
                    }}
                    consequence={consoleUiText(
                        'console.whatever_the_holder_has_wired_this_key_into_stops_worki_e14495d2',
                    )}
                />
            </Panel>
        </div>
    );
}

MemberApiKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
