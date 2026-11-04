import { useTranslator } from '@/i18n';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    CopyButton,
    EmptyState,
    Field,
    Icon,
    Input,
    type LinkTab,
    LinkTabs,
    PageHeader,
    Panel,
    Pill,
    RadioGroup,
    Textarea,
} from '@/ui';

interface KeyRow {
    id: string;
    name: string;
    /** In full, deliberately — see the panel copy. */
    key: string;
    mode: string;
    origins: string[];
    active: boolean;
    urls: { origins: string; revoke: string };
}

type Props = PageProps<{
    tabs: LinkTab[];
    keys: KeyRow[];
    modes: { value: string; label: string }[];
    storeHref: string;
    help: HelpContent;
}>;

export default function FrontendKeys({ tabs, keys, modes, storeHref, help }: Props) {
    const { t: tc } = useTranslator();

    const [creating, setCreating] = useState(false);
    const [revoking, setRevoking] = useState<KeyRow | null>(null);

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.keys_your_browser_side_app_presents_to_the_f_ff8c1e2c')}
                actions={
                    !creating ? (
                        <Button
                            variant="primary"
                            className="shrink-0"
                            onClick={() => setCreating(true)}
                        >
                            <Icon name="plus" className="w-4 h-4" />
                            {tc('console.new_key_eda5496b')}
                        </Button>
                    ) : undefined
                }
            />

            <div className="mt-4">
                <LinkTabs tabs={tabs} label={tc('console.key_types_0562e0e6')} />
            </div>

            <div className="mt-6 space-y-6">
                {creating && (
                    <NewKey modes={modes} href={storeHref} onDone={() => setCreating(false)} />
                )}

                {keys.length === 0 && !creating ? (
                    <div className="card">
                        <EmptyState
                            icon="key"
                            equivalent="frontend_keys.create"
                            title={tc('console.no_publishable_keys_yet_7c4cd2c4')}
                            description={tc(
                                'console.a_publishable_key_lets_a_page_sign_people_in_f1562297',
                            )}
                            steps={[
                                tc('console.name_the_key_and_choose_test_or_live_d84234ea'),
                                tc('console.list_the_origins_it_may_be_presented_from_on_76b78615'),
                                tc('console.paste_it_into_your_frontend_you_can_change_t_3d61f29f'),
                            ]}
                            actions={
                                <Button variant="primary" onClick={() => setCreating(true)}>
                                    <Icon name="plus" className="w-4 h-4" />
                                    {tc('console.new_key_eda5496b')}
                                </Button>
                            }
                        />
                    </div>
                ) : (
                    keys.map((key) => (
                        <KeyCard key={key.id} entry={key} onRevoke={() => setRevoking(key)} />
                    ))
                )}
            </div>

            <ConfirmDelete
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                name={revoking?.name ?? ''}
                verb="Revoke"
                consequence={tc('console.pages_still_holding_this_key_stop_working_im_f2eb0d93')}
                onConfirm={() => {
                    const entry = revoking;
                    setRevoking(null);

                    if (entry !== null) {
                        router.delete(entry.urls.revoke, { preserveScroll: true });
                    }
                }}
            />
        </>
    );
}

function NewKey({
    modes,
    href,
    onDone,
}: {
    modes: { value: string; label: string }[];
    href: string;
    onDone: () => void;
}) {
    const { t: tc } = useTranslator();

    const form = useForm({ name: '', mode: modes[0]?.value ?? 'test', origins: '' });

    return (
        <Panel title={tc('console.new_key_eda5496b')}>
            <form
                className="space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(href, {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            onDone();
                        },
                    });
                }}
            >
                <Field label={tc('console.name_dcd1d522')} error={form.errors.name}>
                    <Input
                        name="name"
                        placeholder={tc('console.marketing_site_945dad06')}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>

                <RadioGroup
                    label={tc('console.mode_5e23ec6a')}
                    value={form.data.mode}
                    onValueChange={(mode) => form.setData('mode', mode)}
                    options={modes.map((mode) => ({
                        value: mode.value,
                        label: mode.label,
                        hint:
                            mode.value === 'test'
                                ? tc(
                                      'console.for_development_refused_on_anything_but_the__113f24ad',
                                  )
                                : tc('console.for_production_traffic_625c032b'),
                    }))}
                />

                <Origins
                    value={form.data.origins}
                    error={form.errors.origins}
                    onChange={(origins) => form.setData('origins', origins)}
                />

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.create_key_b6cde903')}
                    </Button>
                    <Button
                        type="button"
                        onClick={() => {
                            form.reset();
                            onDone();
                        }}
                    >
                        {tc('console.cancel_19766ed6')}
                    </Button>
                </div>
            </form>
        </Panel>
    );
}

/** One key: its value, and the allow-list that is the actual control. */
function KeyCard({ entry, onRevoke }: { entry: KeyRow; onRevoke: () => void }) {
    const { t: tc } = useTranslator();

    const [editing, setEditing] = useState(false);
    const form = useForm({ origins: entry.origins.join('\n') });

    return (
        <Panel
            title={entry.name}
            description={
                <span className="flex items-center gap-2 flex-wrap">
                    <Badge>{entry.mode}</Badge>
                    <Pill tone={entry.active ? 'success' : 'destructive'}>
                        {entry.active
                            ? tc('console.active_92340695')
                            : tc('console.revoked_f6f738d0')}
                    </Pill>
                </span>
            }
            action={
                entry.active ? (
                    <Button size="sm" variant="danger" className="shrink-0" onClick={onRevoke}>
                        {tc('console.revoke_87e6d00b')}
                    </Button>
                ) : undefined
            }
        >
            <div className="space-y-4">
                <div>
                    <p className="label">{tc('console.key_99a52df3')}</p>
                    {/*
                        IN FULL, ALWAYS. Every other credential here is revealed once and
                        then masked, because it is a secret. Masking this one would teach
                        the opposite of the truth — somebody who cannot re-read their
                        publishable key concludes it must be sensitive, and ends up
                        proxying it through a backend, which is the entire thing this
                        key exists to avoid.
                    */}
                    <div className="mt-1 flex items-center gap-2">
                        <code className="flex-1 min-w-0 truncate mono text-sm select-all">
                            {entry.key}
                        </code>
                        <CopyButton value={entry.key} />
                    </div>
                </div>

                <div>
                    <div className="flex items-center justify-between gap-3">
                        <p className="label">{tc('console.allowed_origins_83915d03')}</p>
                        {entry.active && !editing && (
                            <Button size="sm" onClick={() => setEditing(true)}>
                                {tc('console.edit_464c4ffd')}
                            </Button>
                        )}
                    </div>

                    {editing ? (
                        <form
                            className="mt-2 space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.put(entry.urls.origins, {
                                    preserveScroll: true,
                                    onSuccess: () => setEditing(false),
                                });
                            }}
                        >
                            <Origins
                                value={form.data.origins}
                                error={form.errors.origins}
                                onChange={(origins) => form.setData('origins', origins)}
                            />
                            <div className="flex items-center gap-2">
                                <Button
                                    type="submit"
                                    variant="primary"
                                    size="sm"
                                    loading={form.processing}
                                >
                                    {tc('console.save_origins_0a70d44c')}
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() => {
                                        form.reset();
                                        setEditing(false);
                                    }}
                                >
                                    {tc('console.cancel_19766ed6')}
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {entry.origins.length === 0 ? (
                                <span className="text-sm" style={{ color: 'var(--faint)' }}>
                                    {tc(
                                        'console.none_this_key_is_presentable_from_nowhere_25a0f961',
                                    )}
                                </span>
                            ) : (
                                entry.origins.map((origin) => (
                                    <Badge key={origin} className="mono">
                                        {origin}
                                    </Badge>
                                ))
                            )}
                        </div>
                    )}
                </div>
            </div>
        </Panel>
    );
}

/** The allow-list field, shared by the create form and the edit form. */
function Origins({
    value,
    error,
    onChange,
}: {
    value: string;
    error: string | undefined;
    onChange: (value: string) => void;
}) {
    const { t: tc } = useTranslator();

    return (
        <Field
            label={tc('console.allowed_origins_83915d03')}
            hint={tc('console.one_per_line_including_the_scheme_https_app__71de2531')}
            error={error}
        >
            <Textarea
                name="origins"
                rows={3}
                className="mono"
                spellCheck={false}
                placeholder={tc('console.https_app_example_com_https_staging_example__ad77bfc7')}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </Field>
    );
}

FrontendKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
