import { Link } from '@inertiajs/react';
import { CatalogAppIcon } from '@/chrome/AppSwitcher';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { CatalogApp, PageProps } from '@/types';
import { Button, PageHeader, Panel } from '@/ui';

export interface CatalogEntry extends CatalogApp {
    enabled: boolean;
    audience: 'everyone' | 'organizations' | 'users';
    organization_ids: string[];
    user_ids: string[];
    client_ids: string[];
    required_feature: string | null;
}

export default function AppCatalog({
    entries,
    createHref,
}: PageProps<{ entries: (CatalogEntry & { editHref: string })[]; createHref: string }>) {
    return (
        <>
            <PageHeader
                title="App catalog"
                description="Publish the apps people see in their app switcher. Each app still controls sign-in and access."
            />
            <Button asChild variant="primary">
                <Link href={createHref}>Add app</Link>
            </Button>
            <div className="mt-6 space-y-3">
                {entries.map((app) => (
                    <Panel key={app.key}>
                        <div className="flex items-center gap-3">
                            <CatalogAppIcon app={app} />
                            <div className="min-w-0 flex-1">
                                <Link href={app.editHref} className="font-semibold underline">
                                    {app.name}
                                </Link>
                                <p className="text-sm text-[var(--muted-foreground)]">
                                    {app.enabled ? 'Published' : 'Draft'} ·{' '}
                                    {app.audience === 'everyone'
                                        ? 'Everyone'
                                        : `Selected ${app.audience}`}{' '}
                                    · Position {app.position}
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={app.editHref}>Edit</Link>
                            </Button>
                        </div>
                    </Panel>
                ))}
                {entries.length === 0 && (
                    <Panel>
                        <h2 className="font-semibold">Publish your first app</h2>
                        <p className="mt-2 text-sm text-[var(--muted-foreground)]">
                            Add its name, launch URL and icon, then choose who should see it. OAuth
                            integrations are linked separately.
                        </p>
                    </Panel>
                )}
            </div>
        </>
    );
}
AppCatalog.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
