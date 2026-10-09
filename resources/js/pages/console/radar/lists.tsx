import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { absoluteTime } from '@/lib/time';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    ConfirmDelete,
    EmptyState,
    Field,
    Input,
    type LinkTab,
    Pill,
    Select,
} from '@/ui';
import { RadarFrame } from './frame';

interface Entry {
    id: string;
    list: 'allow' | 'deny';
    kind: 'ip' | 'email' | 'email_domain' | 'device';
    value: string;
    note: string | null;
    expires_at: string | null;
    active: boolean;
    created_at: string;
    destroyHref: string;
}

type Props = PageProps<{
    help: HelpContent;
    tabs: LinkTab[];
    entries: Entry[];
    kinds: { value: Entry['kind']; label: string }[];
    storeHref: string;
}>;

const PLACEHOLDER: Record<Entry['kind'], string> = {
    ip: '203.0.113.0/24',
    email: 'someone@example.com',
    email_domain: 'example.com',
    device: 'the 64-character id from a decision',
};

/**
 * What is always refused and always let through. The deny list is asked first and wins; the
 * allow list skips every rule, built-in ones included — use it for your own office and your
 * test accounts, not for a whole country.
 */
export default function RadarLists({ help, tabs, entries, kinds, storeHref }: Props) {
    const [removing, setRemoving] = useState<Entry | null>(null);
    const form = useForm({
        list: 'deny' as Entry['list'],
        kind: 'ip' as Entry['kind'],
        value: '',
        note: '',
        expiresAt: '',
    });

    return (
        <RadarFrame
            help={help}
            tabs={tabs}
            description="Asked before every rule. A deny entry blocks; an allow entry skips every rule, built-in ones included. Both on one attempt: deny wins."
        >
            <form
                className="mt-6 card p-5 grid gap-3 sm:grid-cols-6 items-end"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, {
                        preserveScroll: true,
                        onSuccess: () => form.reset('value', 'note', 'expiresAt'),
                    });
                }}
            >
                <Field label="List" error={form.errors.list}>
                    <Select
                        value={form.data.list}
                        onValueChange={(value) => form.setData('list', value)}
                        options={[
                            { value: 'deny', label: 'Deny' },
                            { value: 'allow', label: 'Allow' },
                        ]}
                    />
                </Field>
                <Field label="Kind" error={form.errors.kind}>
                    <Select
                        value={form.data.kind}
                        onValueChange={(value) => form.setData('kind', value)}
                        options={kinds}
                    />
                </Field>
                <Field label="Value" className="sm:col-span-2" error={form.errors.value}>
                    <Input
                        className="mono"
                        value={form.data.value}
                        placeholder={PLACEHOLDER[form.data.kind]}
                        onChange={(event) => form.setData('value', event.target.value)}
                    />
                </Field>
                <Field label="Expires" error={form.errors.expiresAt} hint="Optional.">
                    <Input
                        type="date"
                        value={form.data.expiresAt}
                        onChange={(event) => form.setData('expiresAt', event.target.value)}
                    />
                </Field>
                <Field label="Note" error={form.errors.note}>
                    <Input
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                    />
                </Field>
                <div className="sm:col-span-6">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Add to list
                    </Button>
                </div>
            </form>

            <div className="mt-6">
                {entries.length === 0 ? (
                    <div className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                        <EmptyState
                            icon="shield"
                            equivalent="radar.lists.add"
                            title="Both lists are empty"
                            description="Nothing is always refused and nothing skips the rules. Deny an address range you have seen attack you, or allow your office network past the velocity rules."
                        />
                    </div>
                ) : (
                    <ul className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                        {entries.map((entry, index) => (
                            <li
                                key={entry.id}
                                className="flex flex-wrap items-center gap-3 p-4"
                                style={
                                    index < entries.length - 1
                                        ? { borderBottom: '1px solid var(--border)' }
                                        : undefined
                                }
                            >
                                <Pill tone={entry.list === 'deny' ? 'destructive' : 'success'}>
                                    {entry.list}
                                </Pill>
                                <Badge tone="info">{entry.kind.replace('_', ' ')}</Badge>
                                <span className="mono text-sm break-all">{entry.value}</span>
                                {!entry.active && <Badge>expired</Badge>}
                                {entry.note !== null && (
                                    <span className="text-xs" style={{ color: 'var(--muted)' }}>
                                        {entry.note}
                                    </span>
                                )}
                                <span className="ml-auto text-xs" style={{ color: 'var(--faint)' }}>
                                    {entry.expires_at !== null
                                        ? `until ${absoluteTime(entry.expires_at)}`
                                        : 'no expiry'}
                                </span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setRemoving(entry)}
                                >
                                    Remove
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>

            {removing !== null && (
                <ConfirmDelete
                    open
                    onOpenChange={(open) => setRemoving(open ? removing : null)}
                    name={removing.value}
                    verb="Remove"
                    consequence={
                        removing.list === 'deny'
                            ? 'From the next attempt this is judged by the rules again.'
                            : 'From the next attempt this is judged by the rules again — including the ones that block.'
                    }
                    onConfirm={() =>
                        router.delete(removing.destroyHref, {
                            preserveScroll: true,
                            onFinish: () => setRemoving(null),
                        })
                    }
                />
            )}
        </RadarFrame>
    );
}

RadarLists.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
