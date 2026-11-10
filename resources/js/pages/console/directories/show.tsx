import { useTranslator as useFormatTranslator } from '@/i18n';
import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Breadcrumb,
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    EmptyState,
    Field,
    Icon,
    Input,
    Panel,
    Pill,
    type PillTone,
    Select,
    Textarea,
} from '@/ui';

interface Group {
    id: string;
    name: string;
    roleIds: string[];
}

interface SetupCredential {
    key: string;
    label: string;
    help: string;
    example: string;
    secret: boolean;
    required: boolean;
}

interface SyncFailure {
    external_id: string | null;
    reason: string;
}

/** How a pull directory's sync is going — the same presenter the API answers with. */
interface SyncState {
    status: 'running' | 'succeeded' | 'partial' | 'failed' | null;
    startedAt: string | null;
    syncedAt: string | null;
    nextAt: string | null;
    intervalMinutes: number | null;
    stats: {
        mode: 'full' | 'incremental';
        provisioned: number;
        deprovisioned: number;
        groups: number;
        skipped: number;
        failed: number;
        failures: SyncFailure[];
    } | null;
    customAttributes: string[];
    active: boolean;
}

interface RoleOption {
    id: string;
    name: string;
    /** The app that declares it, when it is not the organization's own. */
    app: string | null;
}

type Props = PageProps<{
    directory: {
        id: string;
        name: string;
        provider: string;
        providerLabel: string;
        scim: boolean;
        active: boolean;
        status: string;
        lastSyncError: string | null;
        pull: boolean;
        hris: boolean;
        sync: SyncState | null;
    };
    setup: {
        steps: string[];
        docs: string;
        incremental: boolean;
        credentials: SetupCredential[];
    } | null;
    organizationName: string;
    scimBaseUrl: string;
    groups: Group[];
    roles: RoleOption[];
    mayChange: boolean;
    indexHref: string;
    /** The Roles page, where a role to map a group onto is defined; null where not served. */
    rolesHref: string | null;
    urls: {
        update: string;
        rotate: string;
        toggle: string;
        destroy: string;
        map: string;
        sync: string;
        syncSettings: string;
        credentials: string;
    };
}>;

export default function DirectoryDetail({
    directory,
    setup,
    organizationName,
    scimBaseUrl,
    groups,
    roles,
    mayChange,
    rolesHref,
    indexHref,
    urls,
}: Props) {
    const { t: consoleShortText } = useConsoleTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    // The bearer token, on the flash channel and nowhere else: it authenticates every
    // inbound provisioning call, and props are written into the history entry.
    const newToken = usePage().flash.newToken;

    const [confirming, setConfirming] = useState<'rotate' | 'delete' | null>(null);

    const nameForm = useForm({ name: directory.name });

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={indexHref} label={consoleT('console.directory_sync_ea18bba8')} />
                <div className="mt-2 flex items-center gap-3 flex-wrap">
                    <h1 className="cbx-page-title">{directory.name}</h1>
                    <Badge>{directory.providerLabel}</Badge>
                    <Pill tone={directory.active ? 'success' : 'warning'}>
                        {directory.active
                            ? consoleText('console.active_92340695')
                            : directory.status}
                    </Pill>
                </div>
                <p className="mt-1 text-sm" style={{ color: 'var(--faint)' }}>
                    {organizationName}
                </p>
            </div>

            {newToken !== undefined && <RevealedToken token={newToken} endpoint={scimBaseUrl} />}

            {/*
                A sync that has started failing is the one moment somebody needs the
                provider's own guide after setup: "Graph users request failed (403)" is a
                permission that was never granted or a secret that expired, and the steps
                that say which are the ones the create page showed.
            */}
            {directory.lastSyncError !== null && (
                <Panel
                    title={consoleT('console.last_sync_failed_d3f551a0')}
                    action={
                        setup !== null ? (
                            <Button asChild size="sm" className="shrink-0">
                                <a href={setup.docs} target="_blank" rel="noreferrer">
                                    {consoleRich('console.provider_guide_part1_f602d1f9', {
                                        part1: <Icon name="external" className="w-3.5 h-3.5" />,
                                    })}
                                </a>
                            </Button>
                        ) : undefined
                    }
                >
                    <p className="text-sm mono" style={{ color: 'var(--destructive)' }}>
                        {directory.lastSyncError}
                    </p>
                </Panel>
            )}

            {directory.sync !== null && (
                <SyncPanel
                    sync={directory.sync}
                    incremental={setup?.incremental === true}
                    mayChange={mayChange}
                    href={urls.sync}
                />
            )}

            {directory.scim && (
                <Panel
                    title={consoleT('console.scim_endpoint_59109538')}
                    description={consoleT(
                        'console.what_your_identity_provider_posts_to_the_bearer_token_i_f1cd1c83',
                    )}
                >
                    <div className="flex items-center gap-2">
                        <code className="flex-1 min-w-0 truncate mono text-sm">{scimBaseUrl}</code>
                        <CopyButton value={scimBaseUrl} />
                    </div>
                </Panel>
            )}

            {/*
                Group → role. This is what makes a directory worth connecting: access
                follows the group somebody is already in, so joining a team grants what the
                team has and leaving it takes it away.
            */}
            <Panel
                title={consoleT('console.groups_39bbb719')}
                description={consoleT(
                    'console.map_a_group_onto_the_roles_everyone_in_it_should_hold_m_1a407a38',
                )}
            >
                {groups.length === 0 ? (
                    <EmptyState
                        icon="directory"
                        title={consoleT('console.no_groups_synced_yet_910ebab2')}
                        description={consoleT(
                            'console.groups_appear_here_after_the_first_sync_that_includes_t_d8006c0f',
                        )}
                    />
                ) : (
                    <div className="space-y-4">
                        {groups.map((group) => (
                            <div
                                key={group.id}
                                className="rounded-lg border p-4"
                                style={{ borderColor: 'var(--border)' }}
                            >
                                <p className="font-medium">{group.name}</p>

                                {roles.length === 0 ? (
                                    <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                                        {consoleRich(
                                            'console.no_roles_to_map_onto_yet_define_one_under_part1_part2_o_9b9aba76',
                                            {
                                                part1: ' ',
                                                part2:
                                                    rolesHref === null ? (
                                                        consoleShortText('console.roles_c2533705')
                                                    ) : (
                                                        <Link href={rolesHref}>
                                                            {consoleT('console.roles_c2533705')}
                                                        </Link>
                                                    ),
                                            },
                                        )}
                                    </p>
                                ) : (
                                    <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                        {roles.map((role) => (
                                            <Checkbox
                                                key={role.id}
                                                disabled={!mayChange}
                                                checked={group.roleIds.includes(role.id)}
                                                onCheckedChange={(checked) =>
                                                    router.post(
                                                        urls.map,
                                                        {
                                                            group: group.id,
                                                            role: role.id,
                                                            mapped: checked,
                                                        },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                label={role.name}
                                                hint={role.app ?? undefined}
                                            />
                                        ))}
                                    </div>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </Panel>

            {mayChange && (
                <>
                    <Panel title={consoleT('console.details_45989de4')}>
                        <form
                            className="grid sm:grid-cols-[1fr_auto] gap-2 items-end"
                            onSubmit={(event) => {
                                event.preventDefault();
                                nameForm.patch(urls.update, { preserveScroll: true });
                            }}
                        >
                            <Field
                                label={consoleT('console.directory_name_96fff2c7')}
                                error={nameForm.errors.name}
                            >
                                <Input
                                    name="name"
                                    value={nameForm.data.name}
                                    onChange={(event) =>
                                        nameForm.setData('name', event.target.value)
                                    }
                                />
                            </Field>
                            <Button
                                type="submit"
                                variant="primary"
                                className="shrink-0"
                                loading={nameForm.processing}
                            >
                                {consoleT('console.save_1509f561')}
                            </Button>
                        </form>
                    </Panel>

                    {directory.sync !== null && (
                        <SyncSettings
                            sync={directory.sync}
                            hris={directory.hris}
                            href={urls.syncSettings}
                        />
                    )}

                    {directory.hris && setup !== null && (
                        <ReplaceCredentials
                            providerLabel={directory.providerLabel}
                            credentials={setup.credentials}
                            href={urls.credentials}
                        />
                    )}

                    <Panel
                        title={
                            directory.active
                                ? consoleText('console.pause_provisioning_236a712f')
                                : consoleText('console.resume_provisioning_d29e677b')
                        }
                        description={
                            directory.active
                                ? consoleText(
                                      'console.inbound_changes_stop_being_applied_nobody_is_deactivate_1aadb259',
                                  )
                                : consoleText(
                                      'console.inbound_changes_are_applied_again_from_the_next_sync_2ee5ab2e',
                                  )
                        }
                    >
                        <Button
                            size="sm"
                            onClick={() => router.post(urls.toggle, {}, { preserveScroll: true })}
                        >
                            {directory.active
                                ? consoleText('console.pause_858e4ba7')
                                : consoleText('console.resume_d640c742')}
                        </Button>
                    </Panel>

                    {/*
                        Only for a SCIM directory: a pull directory authenticates OUTWARD to
                        the provider's API and has no inbound token to rotate.
                    */}
                    {directory.scim && (
                        <Panel
                            title={consoleT('console.rotate_bearer_token_19caa3e4')}
                            description={consoleT(
                                'console.issue_a_fresh_token_the_one_your_identity_provider_hold_c37da34e',
                            )}
                        >
                            <Button size="sm" onClick={() => setConfirming('rotate')}>
                                {consoleT('console.rotate_token_4ade7882')}
                            </Button>
                        </Panel>
                    )}

                    <Panel
                        title={consoleT('console.delete_directory_b1d3db9c')}
                        description={consoleT(
                            'console.provisioning_stops_and_the_group_mappings_go_with_it_th_b562bdb6',
                        )}
                    >
                        <Button size="sm" variant="danger" onClick={() => setConfirming('delete')}>
                            {consoleT('console.delete_directory_b1d3db9c')}
                        </Button>
                    </Panel>
                </>
            )}

            <ConfirmDelete
                open={confirming === 'rotate'}
                onOpenChange={(open) => !open && setConfirming(null)}
                name={directory.name}
                verb={consoleShortText('console.rotate_c3613b17')}
                consequence={consoleUiText(
                    'console.the_token_your_identity_provider_currently_holds_stops_5458b580',
                )}
                onConfirm={() => {
                    setConfirming(null);
                    router.post(urls.rotate, {}, { preserveScroll: true });
                }}
            />

            <ConfirmDelete
                open={confirming === 'delete'}
                onOpenChange={(open) => !open && setConfirming(null)}
                name={directory.name}
                consequence={consoleUiText(
                    'console.provisioning_stops_immediately_and_every_group_mapping_7a323b54',
                )}
                onConfirm={() => {
                    setConfirming(null);
                    router.delete(urls.destroy);
                }}
            />
        </div>
    );
}

const STATUS = (
    t: Translator['t'],
): Record<NonNullable<SyncState['status']>, { label: string; tone: PillTone }> => ({
    running: { label: t('console.syncing_5c8b9e1c'), tone: 'info' },
    succeeded: { label: t('console.synced_5da22fe4'), tone: 'success' },
    partial: { label: t('console.synced_with_problems_4c8d98e6'), tone: 'warning' },
    failed: { label: t('console.failed_031a8f0f'), tone: 'destructive' },
});

function when(iso: string | null, locale: string): string {
    return iso === null ? '—' : new Date(iso).toLocaleString(locale);
}

/**
 * HOW THE SYNC IS GOING: the last run's outcome and counts, when the next one is due, and
 * the records it could not reconcile — by the provider's own employee id, which is what an
 * administrator can look up there. "Sync now" queues a pull; an incremental HR system can
 * also be asked for everybody, the only run that deprovisions people who disappeared.
 */
function SyncPanel({
    sync,
    incremental,
    mayChange,
    href,
}: {
    sync: SyncState;
    incremental: boolean;
    mayChange: boolean;
    href: string;
}) {
    const { t: consoleNestedText } = useConsoleTranslator();
    const { locale: consoleLocale } = useFormatTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleOptionText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const status = sync.status === null ? null : STATUS(consoleOptionText)[sync.status];
    const stats = sync.stats;

    return (
        <Panel
            title={consoleT('console.sync_8d261a37')}
            description={
                sync.intervalMinutes === null
                    ? undefined
                    : consoleFinalText('console.pulled_every_part1_minutes_part2_6b827aa7', {
                          part1: sync.intervalMinutes,
                          part2: incremental
                              ? consoleText(
                                    'console._asking_only_for_what_changed_between_daily_full_pulls_ab388149',
                                )
                              : '',
                      })
            }
            action={
                mayChange && sync.active ? (
                    <div className="flex gap-2 shrink-0">
                        {incremental && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    router.post(href, { full: true }, { preserveScroll: true })
                                }
                            >
                                {consoleT('console.full_sync_8e3a6a14')}
                            </Button>
                        )}
                        <Button
                            size="sm"
                            variant="primary"
                            onClick={() => router.post(href, {}, { preserveScroll: true })}
                        >
                            {consoleT('console.sync_now_885e8f48')}
                        </Button>
                    </div>
                ) : undefined
            }
        >
            <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <span>
                    {status === null ? (
                        <Pill tone="neutral">{consoleT('console.not_synced_yet_c1052985')}</Pill>
                    ) : (
                        <Pill tone={status.tone}>{status.label}</Pill>
                    )}
                </span>
                <span style={{ color: 'var(--muted-foreground)' }}>
                    {consoleRich('console.last_run_part1_2c2c51d1', {
                        part1: when(sync.startedAt, consoleLocale),
                    })}
                </span>
                <span style={{ color: 'var(--muted-foreground)' }}>
                    {consoleRich('console.next_part1_c0df1ece', {
                        part1: when(sync.nextAt, consoleLocale),
                    })}
                </span>
            </div>

            {stats !== null && (
                <p className="mt-3 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {consoleRich(
                        'console._part1_part2_up_to_date_part3_deprovisioned_part4_part5_5604a19e',
                        {
                            part1:
                                stats.mode === 'incremental'
                                    ? consoleText('console.changes_only_8cd33e8f')
                                    : consoleText('console.everybody_d980065b'),
                            part2: stats.provisioned,
                            part3: stats.deprovisioned,
                            part4: ' ',
                            part5: stats.skipped,
                            part6: stats.groups,
                            part7:
                                stats.failed > 0
                                    ? consoleNestedText(
                                          'console._part1_could_not_be_synced_a1be610d',
                                          { part1: stats.failed },
                                      )
                                    : '',
                        },
                    )}
                </p>
            )}

            {stats !== null && stats.failures.length > 0 && (
                <ul className="mt-3 space-y-1 text-sm">
                    {stats.failures.map((failure) => (
                        <li key={`${failure.external_id ?? 'run'}: ${failure.reason}`}>
                            {failure.external_id !== null && (
                                <code className="mono text-xs mr-2">{failure.external_id}</code>
                            )}
                            {failure.reason}
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

const INTERVALS = ['15', '30', '60', '120', '240', '720', '1440'] as const;

/** The pace, and — for an HR system — the fields copied onto people verbatim. */
function SyncSettings({ sync, hris, href }: { sync: SyncState; hris: boolean; href: string }) {
    const { choice: consoleDuration } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        interval: String(sync.intervalMinutes ?? 60),
        customAttributes: sync.customAttributes.join('\n'),
    });

    return (
        <Panel
            title={consoleT('console.sync_settings_77b268e8')}
            description={
                hris
                    ? consoleText(
                          'console.how_often_to_pull_and_which_of_the_hr_system_s_own_fiel_54dd88fe',
                      )
                    : consoleText('console.how_often_to_pull_f8232314')
            }
        >
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, { preserveScroll: true });
                }}
            >
                <Field label={consoleT('console.pull_every_59fafc55')} error={form.errors.interval}>
                    <Select
                        name="interval"
                        value={form.data.interval}
                        onValueChange={(value) => form.setData('interval', value)}
                        options={INTERVALS.map((minutes) => ({
                            value: minutes,
                            label:
                                Number(minutes) < 60
                                    ? consoleDuration('console.duration_minute', Number(minutes))
                                    : consoleDuration(
                                          'console.duration_hour',
                                          Number(minutes) / 60,
                                      ),
                        }))}
                    />
                </Field>
                {hris && (
                    <Field
                        label={consoleT('console.fields_to_pass_through_c1be63cc')}
                        hint={consoleT(
                            'console.the_hr_system_s_own_field_names_one_per_line_a77675b4',
                        )}
                        error={form.errors.customAttributes}
                    >
                        <Textarea
                            name="customAttributes"
                            rows={3}
                            className="mono"
                            spellCheck={false}
                            value={form.data.customAttributes}
                            onChange={(event) =>
                                form.setData('customAttributes', event.target.value)
                            }
                        />
                    </Field>
                )}
                <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                    {consoleT('console.save_1509f561')}
                </Button>
            </form>
        </Panel>
    );
}

/**
 * NEW CREDENTIALS without reconnecting — the key was rotated, the secret expired. The fields
 * are the HR system's own, from the framework's catalogue; they are verified against it
 * before the old ones are replaced, and never shown again.
 */
function ReplaceCredentials({
    providerLabel,
    credentials,
    href,
}: {
    providerLabel: string;
    credentials: SetupCredential[];
    href: string;
}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm<{ credentials: Record<string, string> }>({ credentials: {} });
    const credentialError = usePage().props.errors.credentials;

    return (
        <Panel
            title={consoleT('console.replace_credentials_ae90fd4a')}
            description={consoleFinalText(
                'console.new_part1_credentials_checked_against_part2_before_the_a56c958b',
                { part1: providerLabel, part2: providerLabel },
            )}
        >
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(href, { preserveScroll: true, onSuccess: () => form.reset() });
                }}
            >
                {credentials.map((credential, index) => (
                    <Field
                        key={credential.key}
                        label={
                            credential.label +
                            (credential.required ? '' : consoleText('console._optional_edbfc3dc'))
                        }
                        hint={credential.help}
                        error={index === 0 ? credentialError : undefined}
                    >
                        <Input
                            name={`credentials.${credential.key}`}
                            type={credential.secret ? 'password' : 'text'}
                            className="mono"
                            autoComplete="off"
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
                <Button type="submit" variant="primary" size="sm" loading={form.processing}>
                    {consoleT('console.verify_and_replace_c857fd95')}
                </Button>
            </form>
        </Panel>
    );
}

/**
 * The bearer token, shown exactly once.
 *
 * IT BRINGS ITSELF INTO VIEW, and takes focus with it: rotation is triggered from the
 * bottom of the page and reveals the new token at the top, so the only feedback in the
 * viewport was a toast in the opposite corner.
 */
function RevealedToken({ token, endpoint }: { token: string; endpoint: string }) {
    const { t: consoleT } = useConsoleTranslator();
    const card = useRef<HTMLDivElement>(null);

    useEffect(() => {
        card.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        card.current?.focus({ preventScroll: true });
    }, []);

    return (
        <div
            ref={card}
            tabIndex={-1}
            className="rounded-xl border p-5"
            style={{
                borderColor: 'color-mix(in oklch, var(--warning) 40%, transparent)',
                background: 'var(--warning-soft)',
            }}
        >
            <p className="text-sm font-semibold" style={{ color: 'var(--warning-strong)' }}>
                {consoleT('console.copy_this_bearer_token_now_48416103')}
            </p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {consoleT(
                    'console.only_a_hash_is_stored_so_it_will_not_be_shown_again_pas_901ca5cd',
                )}
            </p>

            <div className="mt-4 space-y-3">
                <div
                    className="rounded-lg p-3"
                    style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
                >
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {consoleT('console.scim_endpoint_59109538')}
                    </p>
                    <div className="mt-1 flex items-start gap-2">
                        <code className="mono text-sm break-all select-all flex-1">{endpoint}</code>
                        <CopyButton value={endpoint} />
                    </div>
                </div>

                <div
                    className="rounded-lg p-3"
                    style={{ background: 'var(--surface-2)', border: '1px solid var(--border)' }}
                >
                    <p className="text-xs font-semibold" style={{ color: 'var(--warning-strong)' }}>
                        {consoleT(
                            'console.bearer_token_copy_it_now_it_won_t_be_shown_again_9d0d35a9',
                        )}
                    </p>
                    <div className="mt-1 flex items-start gap-2">
                        <code className="mono text-sm break-all select-all flex-1">{token}</code>
                        <CopyButton
                            value={token}
                            variant="primary"
                            label={consoleT('console.copy_token_d35b4ef7')}
                        />
                    </div>
                </div>
            </div>
        </div>
    );
}

DirectoryDetail.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
