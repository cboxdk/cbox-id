import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    CopyButton,
    EmptyState,
    Field,
    Icon,
    Input,
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
    keys: KeyRow[];
    modes: { value: string; label: string }[];
    storeHref: string;
}>;

export default function FrontendKeys({ keys, modes, storeHref }: Props) {
    const [creating, setCreating] = useState(false);
    const [revoking, setRevoking] = useState<KeyRow | null>(null);

    return (
        <>
            <PageHeader
                description="Keys your browser-side app presents to the Frontend API. They ship in a JavaScript bundle — the allow-list of origins is the control, not the key."
                actions={
                    !creating ? (
                        <Button
                            variant="primary"
                            className="shrink-0"
                            onClick={() => setCreating(true)}
                        >
                            <Icon name="plus" className="w-4 h-4" />
                            New key
                        </Button>
                    ) : undefined
                }
            />

            <div className="mt-6 space-y-6">
                {creating && (
                    <NewKey
                        modes={modes}
                        href={storeHref}
                        onDone={() => setCreating(false)}
                    />
                )}

                {keys.length === 0 && !creating ? (
                    <div className="card">
                        <EmptyState
                            icon="key"
                            title="No frontend keys yet"
                            description="A publishable key lets a page sign people in without a backend of your own. It is public on purpose — what stops anybody else using it is the list of origins you allow it from."
                            steps={[
                                'Name the key and choose test or live.',
                                'List the origins it may be presented from — one per line, including the scheme.',
                                'Paste it into your frontend. You can change the origins later without reissuing.',
                            ]}
                            actions={
                                <Button variant="primary" onClick={() => setCreating(true)}>
                                    <Icon name="plus" className="w-4 h-4" />
                                    New key
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
                consequence="Pages still holding this key stop working immediately. This cannot be undone — issue a new key and ship it."
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
    const form = useForm({ name: '', mode: modes[0]?.value ?? 'test', origins: '' });

    return (
        <Panel title="New key">
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
                <Field label="Name" error={form.errors.name}>
                    <Input
                        name="name"
                        placeholder="Marketing site"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>

                <RadioGroup
                    label="Mode"
                    value={form.data.mode}
                    onValueChange={(mode) => form.setData('mode', mode)}
                    options={modes.map((mode) => ({
                        value: mode.value,
                        label: mode.label,
                        hint:
                            mode.value === 'test'
                                ? 'For development. Refused on anything but the origins you list, same as live.'
                                : 'For production traffic.',
                    }))}
                />

                <Origins
                    value={form.data.origins}
                    error={form.errors.origins}
                    onChange={(origins) => form.setData('origins', origins)}
                />

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Create key
                    </Button>
                    <Button
                        type="button"
                        onClick={() => {
                            form.reset();
                            onDone();
                        }}
                    >
                        Cancel
                    </Button>
                </div>
            </form>
        </Panel>
    );
}

/** One key: its value, and the allow-list that is the actual control. */
function KeyCard({ entry, onRevoke }: { entry: KeyRow; onRevoke: () => void }) {
    const [editing, setEditing] = useState(false);
    const form = useForm({ origins: entry.origins.join('\n') });

    return (
        <Panel
            title={entry.name}
            description={
                <span className="flex items-center gap-2 flex-wrap">
                    <Badge>{entry.mode}</Badge>
                    <Pill tone={entry.active ? 'success' : 'destructive'}>
                        {entry.active ? 'Active' : 'Revoked'}
                    </Pill>
                </span>
            }
            action={
                entry.active ? (
                    <Button size="sm" variant="danger" className="shrink-0" onClick={onRevoke}>
                        Revoke
                    </Button>
                ) : undefined
            }
        >
            <div className="space-y-4">
                <div>
                    <p className="label">Key</p>
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
                        <p className="label">Allowed origins</p>
                        {entry.active && !editing && (
                            <Button size="sm" onClick={() => setEditing(true)}>
                                Edit
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
                                    Save origins
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() => {
                                        form.reset();
                                        setEditing(false);
                                    }}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    ) : (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {entry.origins.length === 0 ? (
                                <span className="text-sm" style={{ color: 'var(--faint)' }}>
                                    None — this key is presentable from nowhere.
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
    return (
        <Field
            label="Allowed origins"
            hint="One per line, including the scheme — https://app.example.com. The whole list is refused if any line is unusable, rather than the bad line being dropped silently."
            error={error}
        >
            <Textarea
                name="origins"
                rows={3}
                className="mono"
                spellCheck={false}
                placeholder={'https://app.example.com\nhttps://staging.example.com'}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </Field>
    );
}

FrontendKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
