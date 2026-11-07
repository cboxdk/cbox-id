import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CatalogAppIcon } from '@/chrome/AppSwitcher';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Breadcrumb,
    Button,
    Checkbox,
    ConfirmDelete,
    Field,
    Input,
    PageHeader,
    Panel,
    Select,
    Textarea,
} from '@/ui';
import type { CatalogEntry } from './index';
import { type Option, Targets } from './Targets';

type Props = PageProps<{
    entry: CatalogEntry | null;
    indexHref: string;
    saveBaseHref: string;
    lookupHref: string;
    selectedOptions: { organizations: Option[]; users: Option[]; clients: Option[] };
    features: Option[];
}>;
export default function EditApp({
    entry,
    indexHref,
    saveBaseHref,
    lookupHref,
    selectedOptions,
    features,
}: Props) {
    const { t } = useTranslator();
    const [removing, setRemoving] = useState(false);
    const [iconChanged, setIconChanged] = useState(false);
    const form = useForm({
        key: entry?.key ?? '',
        name: entry?.name ?? '',
        description: entry?.description ?? '',
        launch_url: entry?.launch_url ?? '',
        enabled: entry?.enabled ?? false,
        position: entry?.position ?? 0,
        audience: entry?.audience ?? 'everyone',
        organization_ids: entry?.organization_ids ?? [],
        user_ids: entry?.user_ids ?? [],
        client_ids: entry?.client_ids ?? [],
        required_feature: entry?.required_feature ?? '',
        version: entry?.version ?? 0,
        icon: null as string | null,
        form: '',
    });
    const href = `${saveBaseHref}/${encodeURIComponent(form.data.key)}`;
    return (
        <>
            <Breadcrumb href={indexHref} label={t('console.catalog_heading')} />
            <PageHeader
                title={
                    entry
                        ? t('console.catalog_edit_named', { name: entry.name })
                        : t('console.catalog_add')
                }
                description={t('console.catalog_edit_description')}
            />
            <form
                className="mt-6 max-w-2xl space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.transform(({ icon, ...data }) => ({
                        ...data,
                        required_feature: data.required_feature || null,
                        ...(iconChanged ? { icon } : {}),
                    }));
                    form.put(href);
                }}
            >
                <Panel>
                    <div className="space-y-4">
                        <Field
                            label={t('console.catalog_key')}
                            required
                            error={form.errors.key}
                            hint={t('console.catalog_key_hint')}
                        >
                            <Input
                                value={form.data.key}
                                disabled={entry !== null}
                                maxLength={64}
                                onChange={(event) => form.setData('key', event.target.value)}
                            />
                        </Field>
                        <Field label={t('console.name_dcd1d522')} required error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                maxLength={120}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>
                        <Field
                            label={t('console.description_526e0087')}
                            optional
                            error={form.errors.description}
                        >
                            <Textarea
                                value={form.data.description}
                                maxLength={300}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label={t('console.catalog_launch_url')}
                            required
                            error={form.errors.launch_url}
                            hint={t('console.catalog_launch_hint')}
                        >
                            <Input
                                type="url"
                                value={form.data.launch_url}
                                placeholder="https://app.example.com"
                                onChange={(event) => form.setData('launch_url', event.target.value)}
                            />
                        </Field>
                        <Field
                            label={t('console.catalog_icon')}
                            optional
                            error={form.errors.icon}
                            hint={t('console.catalog_icon_hint')}
                        >
                            <Input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(event) => {
                                    const file = event.target.files?.[0];
                                    if (!file) return;
                                    if (file.size > 131072) {
                                        form.setError('icon', t('console.catalog_icon_size'));
                                        return;
                                    }
                                    const reader = new FileReader();
                                    reader.onload = () => {
                                        if (typeof reader.result === 'string') {
                                            form.setData('icon', reader.result);
                                            setIconChanged(true);
                                            form.clearErrors('icon');
                                        }
                                    };
                                    reader.onerror = () =>
                                        form.setError('icon', t('console.catalog_icon_read'));
                                    reader.readAsDataURL(file);
                                }}
                            />
                        </Field>
                        <div className="flex items-center gap-3">
                            <CatalogAppIcon
                                app={{
                                    name: form.data.name || t('console.app_0d04bfeb'),
                                    icon_url: iconChanged
                                        ? form.data.icon
                                        : (entry?.icon_url ?? null),
                                }}
                            />
                            <Button
                                onClick={() => {
                                    setIconChanged(true);
                                    form.setData('icon', null);
                                }}
                            >
                                {t('console.catalog_remove_icon')}
                            </Button>
                        </div>
                        <Field
                            label={t('console.catalog_position')}
                            error={form.errors.position}
                            hint={t('console.catalog_position_hint')}
                        >
                            <Input
                                type="number"
                                min={0}
                                max={10000}
                                value={form.data.position}
                                onChange={(event) =>
                                    form.setData('position', Number(event.target.value))
                                }
                            />
                        </Field>
                    </div>
                </Panel>
                <Panel>
                    <div className="space-y-4">
                        <Checkbox
                            label={t('console.catalog_published')}
                            hint={t('console.catalog_draft_hint')}
                            checked={form.data.enabled}
                            onCheckedChange={(checked) => form.setData('enabled', checked)}
                        />
                        <Field label={t('console.catalog_audience')} error={form.errors.audience}>
                            <Select
                                value={form.data.audience}
                                onValueChange={(value) => form.setData('audience', value)}
                                options={[
                                    {
                                        value: 'everyone',
                                        label: t('console.catalog_everyone_environment'),
                                    },
                                    {
                                        value: 'organizations',
                                        label: t('console.catalog_selected_organizations'),
                                    },
                                    { value: 'users', label: t('console.catalog_selected_users') },
                                ]}
                            />
                        </Field>
                        {form.data.audience === 'organizations' && (
                            <Targets
                                kind="organizations"
                                href={lookupHref}
                                selected={form.data.organization_ids}
                                initial={selectedOptions.organizations}
                                onChange={(ids) => form.setData('organization_ids', ids)}
                            />
                        )}
                        {form.data.audience === 'users' && (
                            <Targets
                                kind="users"
                                href={lookupHref}
                                selected={form.data.user_ids}
                                initial={selectedOptions.users}
                                onChange={(ids) => form.setData('user_ids', ids)}
                            />
                        )}
                        <Field
                            label={t('console.catalog_required_feature')}
                            optional
                            error={form.errors.required_feature}
                            hint={t('console.catalog_required_feature_hint')}
                        >
                            <Select
                                value={form.data.required_feature || '__none'}
                                onValueChange={(value) =>
                                    form.setData(
                                        'required_feature',
                                        value === '__none' ? '' : value,
                                    )
                                }
                                options={[
                                    { value: '__none', label: t('console.catalog_no_feature') },
                                    ...features,
                                ]}
                            />
                        </Field>
                    </div>
                </Panel>
                <Panel>
                    <Targets
                        kind="clients"
                        href={lookupHref}
                        selected={form.data.client_ids}
                        initial={selectedOptions.clients}
                        onChange={(ids) => form.setData('client_ids', ids)}
                    />
                    <p className="mt-3 text-sm text-[var(--muted-foreground)]">
                        {t('console.catalog_clients_hint')}
                    </p>
                </Panel>
                <p role="alert" className="field-error">
                    {form.errors.form}
                </p>
                <div className="flex flex-wrap gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {t('console.catalog_save')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{t('console.cancel_19766ed6')}</Link>
                    </Button>
                    {entry && (
                        <Button variant="danger" onClick={() => setRemoving(true)}>
                            {t('console.delete_app_70ddc821')}
                        </Button>
                    )}
                </div>
            </form>
            {entry && (
                <ConfirmDelete
                    open={removing}
                    onOpenChange={setRemoving}
                    name={entry.name}
                    consequence={t('console.catalog_delete_consequence')}
                    confirming={form.processing}
                    onConfirm={() => {
                        form.transform(() => ({ version: entry.version }));
                        form.delete(href, { onFinish: () => setRemoving(false) });
                    }}
                />
            )}
        </>
    );
}
EditApp.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
