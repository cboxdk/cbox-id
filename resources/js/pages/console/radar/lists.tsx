import type { Translator } from '@/i18n';
import { useTranslator } from '@/i18n';
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

const PLACEHOLDER = (tc: Translator['t']): Record<Entry['kind'], string> => ({
    ip: '203.0.113.0/24',
    email: 'someone@example.com',
    email_domain: 'example.com',
    device: tc('console.the_64_character_id_from_a_decision_023b6826'),
});

/**
 * What is always refused and always let through. The deny list is asked first and wins; the
 * allow list skips every rule, built-in ones included — use it for your own office and your
 * test accounts, not for a whole country.
 */
export default function RadarLists({ help, tabs, entries, kinds, storeHref }: Props) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

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
            description={tc('console.asked_before_every_rule_a_deny_entry_blocks__e80b3f61')}
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
                <Field label={tc('console.list_6f202f54')} error={form.errors.list}>
                    <Select
                        value={form.data.list}
                        onValueChange={(value) => form.setData('list', value)}
                        options={[
                            { value: 'deny', label: tc('console.deny_05a2d733') },
                            { value: 'allow', label: tc('console.allow_e213c161') },
                        ]}
                    />
                </Field>
                <Field label={tc('console.kind_f5387f9b')} error={form.errors.kind}>
                    <Select
                        value={form.data.kind}
                        onValueChange={(value) => form.setData('kind', value)}
                        options={kinds}
                    />
                </Field>
                <Field
                    label={tc('console.value_8e37953d')}
                    className="sm:col-span-2"
                    error={form.errors.value}
                >
                    <Input
                        className="mono"
                        value={form.data.value}
                        placeholder={PLACEHOLDER(tc)[form.data.kind]}
                        onChange={(event) => form.setData('value', event.target.value)}
                    />
                </Field>
                <Field
                    label={tc('console.expires_f6725f3a')}
                    error={form.errors.expiresAt}
                    hint={tc('console.optional_a9aaf97c')}
                >
                    <Input
                        type="date"
                        value={form.data.expiresAt}
                        onChange={(event) => form.setData('expiresAt', event.target.value)}
                    />
                </Field>
                <Field label={tc('console.note_d8da2c49')} error={form.errors.note}>
                    <Input
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                    />
                </Field>
                <div className="sm:col-span-6">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.add_to_list_c84c5a54')}
                    </Button>
                </div>
            </form>

            <div className="mt-6">
                {entries.length === 0 ? (
                    <div className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                        <EmptyState
                            icon="shield"
                            equivalent="radar.lists.add"
                            title={tc('console.both_lists_are_empty_746c845b')}
                            description={tc(
                                'console.nothing_is_always_refused_and_nothing_skips__6699d9d9',
                            )}
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
                                {!entry.active && <Badge>{tc('console.expired_fa64ea1e')}</Badge>}
                                {entry.note !== null && (
                                    <span className="text-xs" style={{ color: 'var(--muted)' }}>
                                        {entry.note}
                                    </span>
                                )}
                                <span className="ml-auto text-xs" style={{ color: 'var(--faint)' }}>
                                    {entry.expires_at !== null
                                        ? tc('console.until_value0_607ce3f2', {
                                              value0: absoluteTime(entry.expires_at, locale),
                                          })
                                        : tc('console.no_expiry_dd848d43')}
                                </span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setRemoving(entry)}
                                >
                                    {tc('console.remove_c3812fc4')}
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
                            ? tc('console.from_the_next_attempt_this_is_judged_by_the__c6aad838')
                            : tc('console.from_the_next_attempt_this_is_judged_by_the__8374d2aa')
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
