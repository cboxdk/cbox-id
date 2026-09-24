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
    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description="Every API key the people in this organization have created for its apps. Revoke one that leaked or is no longer used. People create their own keys under My account."
            />

            <Panel title="Keys" flush={keys.length > 0}>
                <AppApiKeyList
                    keys={keys}
                    empty={{
                        title: 'Nobody here has created a key yet',
                        description:
                            'When somebody creates an API key for one of your apps, it appears here with who holds it and what it may do.',
                    }}
                    consequence="Whatever the holder has wired this key into stops working immediately. Tell them first if you can. This cannot be undone."
                />
            </Panel>
        </div>
    );
}

MemberApiKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
