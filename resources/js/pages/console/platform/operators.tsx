import { router, useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
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
}>;

function listHref(search: string): string {
    return search === ''
        ? window.location.pathname
        : `${window.location.pathname}?q=${encodeURIComponent(search)}`;
}

export default function Operators({ operators, search, storeHref }: Props) {
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
                description="Platform operators administer environments across the whole install."
                actions={
                    <Button variant="primary" onClick={() => setCreating((open) => !open)}>
                        <Icon name="plus" className="w-4 h-4" />
                        New operator
                    </Button>
                }
            />

            <div className="mt-6">
                <Input
                    type="search"
                    style={{ maxWidth: '24rem' }}
                    placeholder="Search by name or email"
                    aria-label="Search operators"
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                />
                {/*
                    SC 4.1.3: the list is replaced on a debounced keystroke with no focus
                    change, so this count is the only thing that can report a search that
                    narrowed to nothing.
                */}
                <output className="sr-only">
                    {operators.length} {operators.length === 1 ? 'operator' : 'operators'} found.
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
                                        You
                                    </Pill>
                                )}
                                {!operator.active && (
                                    <Pill tone="destructive" className="align-middle ml-1">
                                        Suspended
                                    </Pill>
                                )}
                            </p>
                            <p className="text-xs truncate" style={{ color: 'var(--faint)' }}>
                                {operator.email} ·{' '}
                                {operator.lastLogin === null
                                    ? 'never signed in'
                                    : `last in ${operator.lastLogin}`}
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
                                {operator.active ? 'Suspend' : 'Reactivate'}
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
                          ? 'They lose access to every platform page on this install immediately, and their existing sessions stop working on the next request. You can reactivate them here.'
                          : 'They regain full platform-operator access to this install.'
                }
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>Cancel</Button>
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
                            {confirming?.active === true ? 'Suspend' : 'Reactivate'}
                        </Button>
                    </>
                }
            />
        </>
    );
}

function CreateOperator({ href, onDone }: { href: string; onDone: () => void }) {
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
                <Field label="Name" error={form.errors.name}>
                    <Input
                        name="name"
                        placeholder="Grace Hopper"
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
            </div>

            <div className="flex-1" style={{ minWidth: '12rem' }}>
                <Field label="Email" error={form.errors.email}>
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
                    label="Password"
                    hint="At least 12 characters, and checked against known breach corpora."
                    error={form.errors.password}
                >
                    <Input
                        name="password"
                        type="password"
                        autoComplete="new-password"
                        placeholder="At least 12 characters"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                    />
                </Field>
            </div>

            <Button type="submit" variant="primary" loading={form.processing}>
                Create
            </Button>
            <Button type="button" onClick={onDone}>
                Cancel
            </Button>
        </form>
    );
}

Operators.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
