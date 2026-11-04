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
            <Breadcrumb href={indexHref} label="App catalog" />
            <PageHeader
                title={entry ? `Edit ${entry.name}` : 'Add app'}
                description="Publish one app entry across your products. Visibility in the switcher does not grant access to the app."
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
                            label="App key"
                            required
                            error={form.errors.key}
                            hint="Stable identifier shared by your apps. Lowercase letters, digits, hyphens and underscores; start with a letter."
                        >
                            <Input
                                value={form.data.key}
                                disabled={entry !== null}
                                maxLength={64}
                                onChange={(event) => form.setData('key', event.target.value)}
                            />
                        </Field>
                        <Field label="Name" required error={form.errors.name}>
                            <Input
                                value={form.data.name}
                                maxLength={120}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>
                        <Field label="Description" optional error={form.errors.description}>
                            <Textarea
                                value={form.data.description}
                                maxLength={300}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </Field>
                        <Field
                            label="Launch URL"
                            required
                            error={form.errors.launch_url}
                            hint="The HTTPS page people should land on when they open this app."
                        >
                            <Input
                                type="url"
                                value={form.data.launch_url}
                                placeholder="https://app.example.com"
                                onChange={(event) => form.setData('launch_url', event.target.value)}
                            />
                        </Field>
                        <Field
                            label="Icon"
                            optional
                            error={form.errors.icon}
                            hint="PNG, JPEG or WebP. Up to 128 KiB and 512 × 512 pixels. Icons are public brand assets."
                        >
                            <Input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                onChange={(event) => {
                                    const file = event.target.files?.[0];
                                    if (!file) return;
                                    if (file.size > 131072) {
                                        form.setError('icon', 'Use an icon up to 128 KiB.');
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
                                        form.setError(
                                            'icon',
                                            'The icon could not be read. Try again.',
                                        );
                                    reader.readAsDataURL(file);
                                }}
                            />
                        </Field>
                        <div className="flex items-center gap-3">
                            <CatalogAppIcon
                                app={{
                                    name: form.data.name || 'App',
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
                                Remove icon
                            </Button>
                        </div>
                        <Field
                            label="Position"
                            error={form.errors.position}
                            hint="Lower numbers appear first. Apps with the same position are ordered by key."
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
                            label="Published"
                            hint="Draft apps are hidden from everyone’s switcher."
                            checked={form.data.enabled}
                            onCheckedChange={(checked) => form.setData('enabled', checked)}
                        />
                        <Field label="Who can see this app?" error={form.errors.audience}>
                            <Select
                                value={form.data.audience}
                                onValueChange={(value) => form.setData('audience', value)}
                                options={[
                                    { value: 'everyone', label: 'Everyone in this environment' },
                                    { value: 'organizations', label: 'Selected organizations' },
                                    { value: 'users', label: 'Selected users' },
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
                            label="Required feature flag"
                            optional
                            error={form.errors.required_feature}
                            hint="The app is also hidden when this flag is off for the person and organization."
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
                                    { value: '__none', label: 'No feature flag required' },
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
                        Associate environment-wide OAuth integrations with this app. This does not
                        change sign-in permissions.
                    </p>
                </Panel>
                <p role="alert" className="field-error">
                    {form.errors.form}
                </p>
                <div className="flex flex-wrap gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Save app
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                    {entry && (
                        <Button variant="danger" onClick={() => setRemoving(true)}>
                            Delete app
                        </Button>
                    )}
                </div>
            </form>
            {entry && (
                <ConfirmDelete
                    open={removing}
                    onOpenChange={setRemoving}
                    name={entry.name}
                    consequence="The app disappears from the catalog. Its OAuth integrations and user access are unchanged."
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
