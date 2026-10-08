import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import PortalLayout from '@/layouts/PortalLayout';
import type { PageProps } from '@/types';
import { Button, ConfirmDelete, CopyButton, Field, Icon, Input, Pill } from '@/ui';
import { router } from '@inertiajs/react';
import {
    ChosenProvider,
    FormError,
    type GuideField,
    GuideSteps,
    GuideValues,
    type PortalChrome,
    PortalHeader,
    ProviderPicker,
    Step,
} from './parts';

interface Guide {
    key: string;
    name: string;
    fields: GuideField[];
    docs: string | null;
    steps: string[];
}

interface DirectoryRow {
    id: string;
    name: string;
    active: boolean;
    lastSyncedAt: string | null;
    rotateHref: string;
}

type Props = PageProps<{
    portal: PortalChrome;
    guides: Guide[];
    provider: string | null;
    scimBaseUrl: string;
    /** Our SCIM base URL in every form a guide asks for — whole, or as host and path. */
    scimValues: Record<string, string>;
    directories: DirectoryRow[];
    urls: { self: string; create: string };
}>;

/**
 * DIRECTORY SYNC — pick the directory, create ours, carry the SCIM base URL and the bearer
 * token across. The token is on the flash channel: it is shown on the render that minted it
 * and never again, so the page says so in the warning colour.
 */
export default function PortalDirectorySync({
    portal,
    guides,
    provider,
    scimValues,
    directories,
    urls,
}: Props) {
    const { t } = useTranslator();
    const { newToken, newTokenName } = usePage().flash;
    const guide = guides.find((candidate) => candidate.key === provider) ?? null;
    const token = typeof newToken === 'string' ? newToken : null;

    return (
        <div>
            <PortalHeader
                portal={portal}
                title={t('portal.directory.title')}
                lead={t('portal.directory.lead')}
            />

            <Step number={1} title={t('portal.directory.step_provider')} done={guide !== null}>
                {guide === null ? (
                    <ProviderPicker
                        providers={guides}
                        href={urls.self}
                        label={t('portal.directory.step_provider')}
                    />
                ) : (
                    <ChosenProvider
                        providerKey={guide.key}
                        name={guide.name}
                        href={urls.self}
                        changeLabel={t('portal.directory.change_provider')}
                    />
                )}
            </Step>

            <Step
                number={2}
                title={t('portal.directory.step_create')}
                done={directories.length > 0}
            >
                <CreateDirectory
                    href={urls.create}
                    defaultName={guide === null ? '' : `${guide.name} SCIM`}
                />
            </Step>

            {guide !== null && (
                <Step
                    number={3}
                    title={t('portal.directory.step_connect', { provider: guide.name })}
                >
                    {token !== null && (
                        <div
                            className="card p-4 mb-4"
                            style={{
                                borderColor: 'color-mix(in srgb, var(--warning) 40%, transparent)',
                            }}
                        >
                            <p className="flex items-center gap-2 font-semibold text-sm">
                                <Icon name="key" className="w-4 h-4" />{' '}
                                {t('portal.directory.token_heading', { name: newTokenName ?? '' })}
                            </p>
                            <p className="mt-1 text-sm" style={{ color: 'var(--warning-strong)' }}>
                                {t('portal.directory.token_once')}
                            </p>
                        </div>
                    )}
                    <GuideValues
                        fields={guide.fields}
                        values={{ ...scimValues, scim_token: token ?? undefined }}
                        lead={t('portal.directory.values_lead', { provider: guide.name })}
                    />
                    {token === null && (
                        <p
                            className="text-xs -mt-2 mb-4"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {t('portal.directory.token_pending')}
                        </p>
                    )}
                    <GuideSteps steps={guide.steps} docs={guide.docs} provider={guide.name} />
                </Step>
            )}

            {guide === null && token !== null && (
                <div className="card p-4 mb-6">
                    <p className="text-sm font-semibold">
                        {t('portal.directory.token_heading', { name: newTokenName ?? '' })}
                    </p>
                    <p className="mt-1 text-sm" style={{ color: 'var(--warning-strong)' }}>
                        {t('portal.directory.token_once')}
                    </p>
                    <div className="mt-2 flex items-start gap-2">
                        <code
                            className="mono text-xs rounded-lg px-3 py-2 select-all break-all flex-1 min-w-0"
                            style={{
                                background: 'var(--secondary)',
                                border: '1px solid var(--border)',
                            }}
                        >
                            {token}
                        </code>
                        <CopyButton
                            value={token}
                            label={t('portal.directory.copy_token')}
                            copiedLabel={t('portal.copy.copied')}
                            failedLabel={t('portal.copy.failed')}
                        />
                    </div>
                </div>
            )}

            <DirectoryList directories={directories} />
        </div>
    );
}

function CreateDirectory({ href, defaultName }: { href: string; defaultName: string }) {
    const form = useForm({ name: defaultName });
    const { t } = useTranslator();

    return (
        <form
            className="card p-4 flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, { preserveScroll: true });
            }}
        >
            <div className="flex-1" style={{ minWidth: '12rem' }}>
                <Field label={t('portal.directory.name_label')} error={form.errors.name}>
                    <Input
                        name="name"
                        placeholder="Okta SCIM"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>
            <Button type="submit" variant="primary" loading={form.processing}>
                {t('portal.directory.register')}
            </Button>
        </form>
    );
}

function DirectoryList({ directories }: { directories: DirectoryRow[] }) {
    const { t, locale } = useTranslator();
    const [rotating, setRotating] = useState<DirectoryRow | null>(null);
    const { errors } = usePage().props;
    const rotateVerb = t('portal.directory.rotate');

    return (
        <section className="mt-6 border-t pt-6" style={{ borderColor: 'var(--border)' }}>
            <h2 className="text-sm font-semibold mb-3">
                {t('portal.directory.directories_heading')}
            </h2>
            {directories.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {t('portal.directory.empty')}
                </p>
            ) : (
                <ul className="space-y-2">
                    {directories.map((directory) => (
                        <li
                            key={directory.id}
                            className="card p-3 flex flex-wrap items-center justify-between gap-3"
                        >
                            <div className="min-w-0">
                                <p className="font-medium truncate">{directory.name}</p>
                                <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                                    {directory.lastSyncedAt === null
                                        ? t('portal.directory.never')
                                        : t('portal.directory.last_sync', {
                                              when: new Date(directory.lastSyncedAt).toLocaleString(
                                                  locale,
                                              ),
                                          })}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <Pill tone={directory.active ? 'success' : 'warning'}>
                                    {directory.active
                                        ? t('portal.directory.active')
                                        : t('portal.directory.paused')}
                                </Pill>
                                <Button
                                    size="sm"
                                    aria-label={t('portal.directory.rotate_label', {
                                        name: directory.name,
                                    })}
                                    onClick={() => setRotating(directory)}
                                >
                                    {rotateVerb}
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <FormError
                message={typeof errors.directory === 'string' ? errors.directory : undefined}
            />

            <ConfirmDelete
                open={rotating !== null}
                onOpenChange={(open) => !open && setRotating(null)}
                name={rotating?.name ?? ''}
                verb={rotateVerb}
                title={t('portal.directory.rotate_title', { name: rotating?.name ?? '' })}
                consequence={t('portal.directory.rotate_consequence')}
                environment={null}
                cancelLabel={t('portal.confirm.cancel')}
                typeToConfirmLabel={t('portal.confirm.type_to_confirm', {
                    name: rotating?.name ?? '',
                })}
                hint={t('portal.confirm.hint', { action: rotateVerb })}
                onConfirm={() => {
                    const directory = rotating;
                    setRotating(null);

                    if (directory !== null) {
                        router.post(directory.rotateHref, {}, { preserveScroll: true });
                    }
                }}
            />
        </section>
    );
}

PortalDirectorySync.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
