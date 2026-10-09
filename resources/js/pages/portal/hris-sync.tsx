import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useTranslator } from '@/i18n';
import PortalLayout from '@/layouts/PortalLayout';
import type { PageProps } from '@/types';
import { Button, Field, Input, Pill, type PillTone, Textarea } from '@/ui';
import {
    ChosenProvider,
    FormError,
    GuideSteps,
    messageKey,
    type PortalChrome,
    PortalHeader,
    ProviderPicker,
    Step,
} from './parts';

interface Credential {
    key: string;
    label: string;
    help: string;
    example: string;
    secret: boolean;
    required: boolean;
}

interface Guide {
    key: string;
    name: string;
    docs: string;
    incremental: boolean;
    steps: string[];
    credentials: Credential[];
}

interface HrisRow {
    id: string;
    name: string;
    provider: string;
    active: boolean;
    status: 'running' | 'succeeded' | 'partial' | 'failed' | null;
    lastSyncedAt: string | null;
    failed: number;
    error: string | null;
    syncHref: string;
}

type Props = PageProps<{
    portal: PortalChrome;
    guides: Guide[];
    provider: string | null;
    directories: HrisRow[];
    urls: { self: string; create: string; scim: string };
}>;

const TONES: Record<NonNullable<HrisRow['status']>, PillTone> = {
    running: 'info',
    succeeded: 'success',
    partial: 'warning',
    failed: 'destructive',
};

/**
 * DIRECTORY SYNC FROM AN HR SYSTEM — pick the system, follow its guide, paste what it
 * produced. The opposite direction from the SCIM page: here the customer hands US values,
 * so there is nothing of ours to copy and nothing shown once. What they paste is checked
 * with the HR system before it is stored, and never shown again — the secret fields are
 * password inputs and are not refilled after a refusal.
 */
export default function PortalHrisSync({ portal, guides, provider, directories, urls }: Props) {
    const { t } = useTranslator();
    const guide = guides.find((candidate) => candidate.key === provider) ?? null;

    return (
        <div>
            <PortalHeader
                portal={portal}
                title={t('portal.hris.title')}
                lead={t('portal.hris.lead')}
            />

            <p className="text-sm mb-6">
                <Link href={urls.scim} style={{ color: 'var(--accent)' }}>
                    {t('portal.hris.scim_link')}
                </Link>
            </p>

            <Step number={1} title={t('portal.hris.step_provider')} done={guide !== null}>
                {guide === null ? (
                    <ProviderPicker
                        providers={guides}
                        href={urls.self}
                        label={t('portal.hris.step_provider')}
                    />
                ) : (
                    <ChosenProvider
                        providerKey={guide.key}
                        name={guide.name}
                        href={urls.self}
                        changeLabel={t('portal.hris.change_provider')}
                    />
                )}
            </Step>

            {guide !== null && (
                <>
                    <Step
                        number={2}
                        title={t('portal.hris.step_prepare', { provider: guide.name })}
                    >
                        <GuideSteps steps={guide.steps} docs={guide.docs} provider={guide.name} />
                    </Step>

                    <Step
                        number={3}
                        title={t('portal.hris.step_connect', { provider: guide.name })}
                        done={directories.some((row) => row.provider === guide.key)}
                    >
                        <ConnectForm guide={guide} href={urls.create} />
                    </Step>
                </>
            )}

            <HrisList directories={directories} />
        </div>
    );
}

function ConnectForm({ guide, href }: { guide: Guide; href: string }) {
    const { t } = useTranslator();
    const { errors } = usePage().props;
    const form = useForm<{
        provider: string;
        credentials: Record<string, string>;
        customAttributes: string;
    }>({ provider: guide.key, credentials: {}, customAttributes: '' });

    return (
        <form
            className="card p-4 space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, {
                    preserveScroll: true,
                    // Never keep a secret in the page longer than the request needs it.
                    onFinish: () => form.setData('credentials', {}),
                });
            }}
        >
            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {t('portal.hris.connect_lead', { provider: guide.name })}
            </p>

            {guide.credentials.map((credential) => (
                <Field
                    key={credential.key}
                    label={
                        credential.required
                            ? credential.label
                            : `${credential.label} (${t('portal.hris.optional')})`
                    }
                    hint={credential.help}
                >
                    <Input
                        name={`credentials.${credential.key}`}
                        type={credential.secret ? 'password' : 'text'}
                        className="mono"
                        autoComplete="off"
                        spellCheck={false}
                        placeholder={credential.example}
                        value={form.data.credentials[credential.key] ?? ''}
                        onChange={(event) =>
                            form.setData('credentials', {
                                ...form.data.credentials,
                                [credential.key]: event.target.value,
                            })
                        }
                    />
                </Field>
            ))}

            <Field
                label={t('portal.hris.custom_label')}
                hint={t('portal.hris.custom_hint')}
                error={
                    typeof errors.customAttributes === 'string'
                        ? errors.customAttributes
                        : undefined
                }
            >
                <Textarea
                    name="customAttributes"
                    rows={2}
                    className="mono"
                    spellCheck={false}
                    value={form.data.customAttributes}
                    onChange={(event) => form.setData('customAttributes', event.target.value)}
                />
            </Field>

            <FormError
                message={
                    typeof errors.credentials === 'string'
                        ? errors.credentials
                        : typeof errors.provider === 'string'
                          ? errors.provider
                          : undefined
                }
            />

            <Button type="submit" variant="primary" loading={form.processing}>
                {t('portal.hris.connect')}
            </Button>
        </form>
    );
}

function HrisList({ directories }: { directories: HrisRow[] }) {
    const { t, locale } = useTranslator();
    const { errors } = usePage().props;

    return (
        <section className="mt-6 border-t pt-6" style={{ borderColor: 'var(--border)' }}>
            <h2 className="text-sm font-semibold mb-3">{t('portal.hris.connected_heading')}</h2>
            {directories.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {t('portal.hris.empty')}
                </p>
            ) : (
                <ul className="space-y-2">
                    {directories.map((directory) => (
                        <li key={directory.id} className="card p-3 space-y-2">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="min-w-0">
                                    <p className="font-medium truncate">{directory.name}</p>
                                    <p
                                        className="text-xs"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {directory.lastSyncedAt === null
                                            ? t('portal.hris.never')
                                            : t('portal.hris.last_sync', {
                                                  when: new Date(
                                                      directory.lastSyncedAt,
                                                  ).toLocaleString(locale),
                                              })}
                                        {directory.failed > 0 &&
                                            ` · ${t('portal.hris.problems', { count: directory.failed })}`}
                                    </p>
                                </div>
                                <div className="flex items-center gap-2">
                                    {!directory.active ? (
                                        <Pill tone="warning">{t('portal.hris.paused')}</Pill>
                                    ) : (
                                        <Pill
                                            tone={
                                                directory.status === null
                                                    ? 'neutral'
                                                    : TONES[directory.status]
                                            }
                                        >
                                            {t(
                                                messageKey(
                                                    'portal.hris.status',
                                                    directory.status ?? 'pending',
                                                ),
                                            )}
                                        </Pill>
                                    )}
                                    {directory.active && (
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    directory.syncHref,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            {t('portal.hris.sync_now')}
                                        </Button>
                                    )}
                                </div>
                            </div>
                            {directory.error !== null && directory.status !== 'succeeded' && (
                                <p className="text-xs mono" style={{ color: 'var(--destructive)' }}>
                                    {directory.error}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <FormError
                message={typeof errors.directory === 'string' ? errors.directory : undefined}
            />
        </section>
    );
}

PortalHrisSync.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
