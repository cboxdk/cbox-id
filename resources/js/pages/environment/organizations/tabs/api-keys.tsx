import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { type AppApiKey, AppApiKeyList, Panel } from '@/ui';

type Props = PageProps<{
    /** Every API key the organization's people hold for its apps. Seen and revoked, never minted here. */
    apiKeys: AppApiKey[];
}>;

/**
 * AN ORGANIZATION › API KEYS — the keys its people created for its apps. The header and tabs
 * are the layout's (`../frame.tsx`).
 */
export default function OrganizationApiKeys({ apiKeys }: Props) {
    return (
        <Panel
            title="Member API keys"
            description="Keys this organization's people have created for its apps, with what each may do. People create their own; revoke one that leaked or is no longer used."
            flush={apiKeys.length > 0}
        >
            <AppApiKeyList
                keys={apiKeys}
                empty={{
                    title: 'No API keys',
                    description:
                        'Nobody in this organization has created a key for one of its apps.',
                }}
                consequence="Whatever the holder has wired this key into stops working immediately. This cannot be undone."
            />
        </Panel>
    );
}

OrganizationApiKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
