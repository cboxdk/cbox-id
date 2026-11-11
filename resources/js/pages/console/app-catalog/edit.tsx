import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { CatalogAppIcon } from '@/chrome/CatalogAppIcon';
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
    hues: string[];
}>;
export default function EditApp({
    entry,
    indexHref,
    saveBaseHref,
    lookupHref,
    selectedOptions,
    features,
    hues,
}: Props) {
    const { t } = useTranslator();
    const [removing, setRemoving] = useState(false);
    const [iconChanged, setIconChanged] = useState(false);
    const [iconDarkChanged, setIconDarkChanged] = useState(false);
    const form = useForm({
        key: entry?.key ?? '',
        name: entry?.name ?? '',
        description: entry?.description ?? '',
        purpose: entry?.purpose ?? '',
        hue: entry?.hue ?? '',
        launch_url: entry?.launch_url ?? '',
        admission_url: entry?.admission_url ?? '',
        promote_when_unavailable: entry?.promote_when_unavailable ?? true,
        enabled: entry?.enabled ?? false,
        position: entry?.position ?? 0,
        audience: entry?.audience ?? 'everyone',
        organization_ids: entry?.organization_ids ?? [],
        user_ids: entry?.user_ids ?? [],
        client_ids: entry?.client_ids ?? [],
        required_feature: entry?.required_feature ?? '',
        version: entry?.version ?? 0,
        icon: null as string | null,
        icon_dark: null as string | null,
        form: '',
    });
    const href = `${saveBaseHref}/${encodeURIComponent(form.data.key)}`;
    const fallbackName = t('console.app_0d04bfeb');
    const lightIcon = iconChanged ? form.data.icon : (entry?.icon_url ?? null);
    const darkIcon = iconDarkChanged ? form.data.icon_dark : (entry?.icon_dark_url ?? null);
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
                    // An untouched icon is omitted, which keeps it; null clears it.
                    form.transform(({ icon, icon_dark, ...data }) => ({
                        ...data,
                        required_feature: data.required_feature || null,
                        purpose: data.purpose || null,
                        hue: data.hue || null,
                        admission_url: data.admission_url || null,
                        ...(iconChanged ? { icon } : {}),
                        ...(iconDarkChanged ? { icon_dark } : {}),
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
                            label={t('console.catalog_purpose')}
                            optional
                            error={form.errors.purpose}
                            hint={t('console.catalog_purpose_hint')}
                        >
                            <Input
                                value={form.data.purpose}
                                maxLength={60}
                                onChange={(event) => form.setData('purpose', event.target.value)}
                            />
                        </Field>
                        <Field
                            label={t('console.catalog_hue')}
                            optional
                            error={form.errors.hue}
                            hint={t('console.catalog_hue_hint')}
                        >
                            <Select
                                value={form.data.hue || '__none'}
                                onValueChange={(value) =>
                                    form.setData('hue', value === '__none' ? '' : value)
                                }
                                options={[
                                    { value: '__none', label: t('console.catalog_no_hue') },
                                    ...hues.map((hue) => ({ value: hue, label: hue })),
                                ]}
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
                        <IconUpload
                            label={t('console.catalog_icon')}
                            hint={t('console.catalog_icon_hint')}
                            removeLabel={t('console.catalog_remove_icon')}
                            error={form.errors.icon}
                            onError={(message) => form.setError('icon', message)}
                            onChange={(icon) => {
                                form.setData('icon', icon);
                                setIconChanged(true);
                                form.clearErrors('icon');
                            }}
                            preview={
                                <CatalogAppIcon
                                    app={{
                                        name: form.data.name || fallbackName,
                                        icon_url: lightIcon,
                                    }}
                                />
                            }
                        />
                        <IconUpload
                            label={t('console.catalog_icon_dark')}
                            hint={t('console.catalog_icon_dark_hint')}
                            removeLabel={t('console.catalog_remove_icon_dark')}
                            error={form.errors.icon_dark}
                            onError={(message) => form.setError('icon_dark', message)}
                            onChange={(icon) => {
                                form.setData('icon_dark', icon);
                                setIconDarkChanged(true);
                                form.clearErrors('icon_dark');
                            }}
                            preview={
                                // Previewed on a dark surface whatever the console's own theme,
                                // showing what dark mode will paint: the light icon when none is set.
                                <span
                                    data-testid="dark-icon-preview"
                                    className="flex h-12 w-12 items-center justify-center rounded-lg bg-[oklch(0.185_0.008_80)]"
                                >
                                    {(darkIcon ?? lightIcon) ? (
                                        <img
                                            src={(darkIcon ?? lightIcon)!}
                                            alt=""
                                            className="h-8 w-8 rounded-lg object-contain"
                                        />
                                    ) : (
                                        <span className="text-sm font-semibold text-[oklch(0.965_0.006_90)]">
                                            {(form.data.name || fallbackName)
                                                .slice(0, 2)
                                                .toUpperCase()}
                                        </span>
                                    )}
                                </span>
                            }
                        />
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
                            label={t('console.catalog_admission_url')}
                            optional
                            error={form.errors.admission_url}
                            hint={t('console.catalog_admission_hint')}
                        >
                            <Input
                                type="url"
                                value={form.data.admission_url}
                                placeholder="https://app.example.com/signup"
                                onChange={(event) =>
                                    form.setData('admission_url', event.target.value)
                                }
                            />
                        </Field>
                        <Checkbox
                            label={t('console.catalog_promote')}
                            hint={t('console.catalog_promote_hint')}
                            checked={form.data.promote_when_unavailable}
                            onCheckedChange={(checked) =>
                                form.setData('promote_when_unavailable', checked)
                            }
                        />
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
/** One raster upload: read in the browser as a data URI, checked for size, with a preview. */
function IconUpload({
    label,
    hint,
    removeLabel,
    error,
    preview,
    onChange,
    onError,
}: {
    label: string;
    hint: string;
    removeLabel: string;
    error?: string;
    preview: React.ReactNode;
    onChange: (icon: string | null) => void;
    onError: (message: string) => void;
}) {
    const { t } = useTranslator();
    return (
        <>
            <Field label={label} optional error={error} hint={hint}>
                <Input
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={(event) => {
                        const file = event.target.files?.[0];
                        if (!file) return;
                        if (file.size > 131072) {
                            onError(t('console.catalog_icon_size'));
                            return;
                        }
                        const reader = new FileReader();
                        reader.onload = () => {
                            if (typeof reader.result === 'string') onChange(reader.result);
                        };
                        reader.onerror = () => onError(t('console.catalog_icon_read'));
                        reader.readAsDataURL(file);
                    }}
                />
            </Field>
            <div className="flex items-center gap-3">
                {preview}
                <Button onClick={() => onChange(null)}>{removeLabel}</Button>
            </div>
        </>
    );
}

EditApp.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
