import { useTranslator } from '@/i18n';
import { Link } from '@inertiajs/react';
import { CatalogAppIcon } from '@/chrome/AppSwitcher';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { CatalogApp, HelpContent, PageProps } from '@/types';
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
    help,
}: PageProps<{
    entries: (CatalogEntry & { editHref: string })[];
    createHref: string;
    help: HelpContent;
}>) {
    const { t } = useTranslator();
    return (
        <>
            <PageHeader
                title={t('console.catalog_heading')}
                help={help}
                description={t('console.catalog_intro')}
            />
            <Button asChild variant="primary">
                <Link href={createHref}>{t('console.catalog_add')}</Link>
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
                                    {app.enabled
                                        ? t('console.catalog_published')
                                        : t('console.draft_ebf12ef4')}{' '}
                                    ·{' '}
                                    {app.audience === 'everyone'
                                        ? t('console.catalog_everyone')
                                        : t(
                                              app.audience === 'organizations'
                                                  ? 'console.catalog_selected_organizations'
                                                  : 'console.catalog_selected_users',
                                          )}{' '}
                                    ·{' '}
                                    {t('console.catalog_position_value', {
                                        position: app.position,
                                    })}
                                </p>
                            </div>
                            <Button asChild>
                                <Link href={app.editHref}>{t('console.edit_464c4ffd')}</Link>
                            </Button>
                        </div>
                    </Panel>
                ))}
                {entries.length === 0 && (
                    <Panel>
                        <h2 className="font-semibold">{t('console.catalog_first')}</h2>
                        <p className="mt-2 text-sm text-[var(--muted-foreground)]">
                            {t('console.catalog_first_description')}
                        </p>
                    </Panel>
                )}
            </div>
        </>
    );
}
AppCatalog.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
