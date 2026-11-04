import { useTranslator } from '@/i18n';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Button, Dialog, Field, Icon, Input, PageHeader, Pill } from '@/ui';

interface Operator {
    id: string;
    name: string | null;
    email: string;
    active: boolean;
    lastLogin: string | null;
    isSelf: boolean;
    toggleHref: string;
}

type Props = PageProps<{
    operators: Operator[];
    search: string;
    storeHref: string;
    help: HelpContent;
}>;

function listHref(search: string): string {
    return search === ''
        ? window.location.pathname
        : `${window.location.pathname}?q=${encodeURIComponent(search)}`;
}

export default function Operators({ operators, search, storeHref, help }: Props) {
    const { t: tc } = useTranslator();

    const [term, setTerm] = useState(search);
    const [creating, setCreating] = useState(false);
    const [confirming, setConfirming] = useState<Operator | null>(null);

    useEffect(() => {
        if (term === search) {
            return;
        }

        const timer = setTimeout(() => {
            router.get(
                listHref(term),
                {},
                { preserveState: true, preserveScroll: true, replace: true },
            );
        }, 300);

        return () => clearTimeout(timer);
    }, [term, search]);

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.platform_operators_administer_environments_a_e0a9f173')}
                actions={
                    <Button variant="primary" onClick={() => setCreating((open) => !open)}>
                        <Icon name="plus" className="w-4 h-4" />
                        {tc('console.new_operator_5dfa8aa1')}
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder={tc('console.search_by_name_or_email_6936e681')}
                    aria-label={tc('console.search_operators_0fde2f03')}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so this count is the only thing that can report a search that
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {operators.length}{' '}
                    {operators.length === 1
                        ? tc('console.operator_06e55b63')
                        : tc('console.operators_b2db06dc')}
                    {tc('console.found_599e25a3')}
                </output>
            </div>

            {creating && <CreateOperator href={storeHref} onDone={() => setCreating(false)} />}

            <div className="cbx-panel overflow-hidden mt-8">
                {operators.map((operator, index) => (
                    <div
                        key={operator.id}
                        className="px-5 py-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                        style={
                            index < operators.length - 1
                                ? { borderBottom: '1px solid var(--border)' }
                                : undefined
                        }
                    >
                        <div className="min-w-0">
                            <p className="text-sm font-semibold truncate">
                                {operator.name ?? operator.email}
                                {operator.isSelf && (
                                    <Pill tone="info" className="align-middle ml-1">
                                        {tc('console.you_08b04193')}
                                    </Pill>
                                )}
                                {!operator.active && (
                                    <Pill tone="destructive" className="align-middle ml-1">
                                        {tc('console.suspended_e392a389')}
                                    </Pill>
                                )}
                            </p>
                            <p className="text-xs truncate" style={{ color: 'var(--faint)' }}>
                                {operator.email} ·{' '}
                                {operator.lastLogin === null
                                    ? tc('console.never_signed_in_fde96b0e')
                                    : tc('console.last_in_value0_f91b3125', {
                                          value0: operator.lastLogin,
                                      })}
                            </p>
                        </div>

                        {/*
                            Suspending a colleague sat eight pixels from nothing, unconfirmed
                            and with no undo. It is reversible on this very screen, so a plain
                            dialog rather than the type-to-confirm one — that is for actions
                            with no way back — but it names the person and says what stops
                            working.
                        */}
                        {!operator.isSelf && (
                            <Button
                                size="sm"
                                className="shrink-0"
                                variant={operator.active ? 'danger' : undefined}
                                // Named for the row: a column of buttons all announced as
                                // "Suspend" says which action but never about whom.
                                aria-label={`${operator.active ? 'Suspend' : 'Reactivate'} ${operator.name ?? operator.email}`}
                                onClick={() => setConfirming(operator)}
                            >
                                {operator.active
                                    ? tc('console.suspend_4948e134')
                                    : tc('console.reactivate_2dc7478f')}
                            </Button>
                        )}
                    </div>
                ))}
            </div>

            <Dialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={
                    confirming === null
                        ? ''
                        : `${confirming.active ? 'Suspend' : 'Reactivate'} ${confirming.name ?? confirming.email}?`
                }
                description={
                    confirming === null
                        ? ''
                        : confirming.active
                          ? tc('console.they_lose_access_to_every_platform_page_on_t_0e71b790')
                          : tc('console.they_regain_full_platform_operator_access_to_925cd320')
                }
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant={confirming?.active === true ? 'danger' : 'primary'}
                            onClick={() => {
                                const operator = confirming;
                                setConfirming(null);

                                if (operator !== null) {
                                    router.post(operator.toggleHref, {}, { preserveScroll: true });
                                }
                            }}
                        >
                            {confirming?.active === true
                                ? tc('console.suspend_4948e134')
                                : tc('console.reactivate_2dc7478f')}
                        </Button>
                    </>
                }
            />
        </>
    );
}

function CreateOperator({ href, onDone }: { href: string; onDone: () => void }) {
    const { t: tc } = useTranslator();

    const form = useForm({ name: '', email: '', password: '' });

    return (
        <form
            className="card p-4 mb-5 mt-8 flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(href, { preserveScroll: true, onSuccess: () => onDone() });
            }}
        >
            <div className="flex-1" style={{ minWidth: '12rem' }}>
                <Field label={tc('console.name_dcd1d522')} error={form.errors.name}>
                    <Input
                        name="name"
                        placeholder={tc('console.grace_hopper_b2278a96')}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            <div className="flex-1" style={{ minWidth: '12rem' }}>
                <Field label={tc('console.email_969ccbd3')} error={form.errors.email}>
                    <Input
                        name="email"
                        type="email"
                        placeholder="grace@yourco.example"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                    />
                </Field>
            </div>

            <div className="flex-1" style={{ minWidth: '12rem' }}>
                <Field
                    label={tc('console.password_e7cf3ef4')}
                    hint={tc('console.at_least_12_characters_and_checked_against_k_6b10b5e5')}
                    error={form.errors.password}
                >
                    <Input
                        name="password"
                        type="password"
                        autoComplete="new-password"
                        placeholder={tc('console.at_least_12_characters_0ced89a6')}
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                </Field>
            </div>

            <Button type="submit" variant="primary" loading={form.processing}>
                {tc('console.create_4759498a')}
            </Button>
            <Button type="button" onClick={onDone}>
                {tc('console.cancel_19766ed6')}
            </Button>
        </form>
    );
}

Operators.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
