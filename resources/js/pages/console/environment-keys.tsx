import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    ConfirmDelete,
    CopyButton,
    EmptyState,
    Field,
    Input,
    PageHeader,
    Panel,
    Select,
} from '@/ui';

interface KeyRow {
    id: string;
    name: string;
    scopes: string[];
    lastUsedAt: string | null;
    revokeHref: string;
}

interface ScopeOption {
    value: string;
    /** True for anything that is not `:read` — the distinction that matters here. */
    writes: boolean;
}

type Props = PageProps<{
    environments: { id: string; name: string }[];
    selected: string;
    keys: KeyRow[];
    scopes: ScopeOption[];
    defaultScopes: string[];
    storeHref: string;
}>;

export default function EnvironmentKeys({
    environments,
    selected,
    keys,
    scopes,
    defaultScopes,
    storeHref,
}: Props) {
    // Shown once: only a hash is stored, and props are written into the browser's history
    // entry, so the plaintext rides the flash channel and nowhere else. The SAME key the
    // account-plane API keys page uses — one management-plane credential channel, so the
    // shell cannot end up with two spellings of the same one-time reveal.
    const freshKey = usePage().flash.freshKey;

    const [revoking, setRevoking] = useState<KeyRow | null>(null);

    const form = useForm({
        environment: selected,
        name: '',
        scopes: defaultScopes,
    });

    // The environment travels in the URL, so the form's copy follows it rather than
    // drifting: minting against one environment while the list shows another is the one
    // mistake this page can make that nobody would notice.
    useEffect(() => {
        form.setData('environment', selected);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [selected]);

    const toggleScope = (value: string, checked: boolean): void => {
        form.setData(
            'scopes',
            checked ? [...form.data.scopes, value] : form.data.scopes.filter((s) => s !== value),
        );
    };

    return (
        <>
            <PageHeader
                description="Machine credentials for the per-environment management API — provision organizations and users inside one environment. Each key carries explicit scopes."
                actions={
                    <Button asChild size="sm" className="shrink-0">
                        <a href="/api/v1/environment/openapi.yaml" target="_blank" rel="noreferrer">
                            API reference ↗
                        </a>
                    </Button>
                }
            />

            {environments.length === 0 ? (
                <div className="card mt-6">
                    <EmptyState
                        icon="layers"
                        title="No environments yet"
                        description="Create an environment first, then you can issue keys scoped to it."
                    />
                </div>
            ) : (
                <div className="mt-6 space-y-6">
                    <Field label="Environment" className="max-w-sm">
                        <Select
                            value={selected}
                            onValueChange={(environment) =>
                                router.get(
                                    window.location.pathname,
                                    { environment },
                                    { preserveState: true, preserveScroll: true, replace: true },
                                )
                            }
                            options={environments.map((environment) => ({
                                value: environment.id,
                                label: environment.name,
                            }))}
                        />
                    </Field>

                    {freshKey !== undefined && <RevealedKey value={freshKey} />}

                    <Panel title="Keys" flush={keys.length > 0}>
                        {keys.length === 0 ? (
                            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                No keys for this environment yet.
                            </p>
                        ) : (
                            <div>
                                {keys.map((key, index) => (
                                    <div
                                        key={key.id}
                                        className="flex items-center gap-3 flex-wrap px-4 py-3"
                                        style={
                                            index === keys.length - 1
                                                ? undefined
                                                : { borderBottom: '1px solid var(--border)' }
                                        }
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="font-medium truncate">{key.name}</p>
                                            <div className="mt-1 flex flex-wrap gap-1.5">
                                                {key.scopes.map((scope) => (
                                                    <Badge key={scope} className="mono">
                                                        {scope}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </div>

                                        <span
                                            className="text-xs shrink-0"
                                            style={{ color: 'var(--faint)' }}
                                        >
                                            {key.lastUsedAt === null
                                                ? 'never used'
                                                : `used ${key.lastUsedAt}`}
                                        </span>

                                        <Button
                                            size="sm"
                                            variant="danger"
                                            className="shrink-0"
                                            onClick={() => setRevoking(key)}
                                        >
                                            Revoke
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Panel>

                    <Panel
                        title="Issue a key"
                        description="The key can do only what its scopes allow. Read never implies write."
                    >
                        <form
                            className="space-y-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(storeHref, {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('name'),
                                });
                            }}
                        >
                            <Field label="Name" error={form.errors.name}>
                                <Input
                                    name="name"
                                    placeholder="Provisioning worker"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            </Field>

                            <div>
                                <fieldset>
                                    <legend className="label">Scopes</legend>
                                    <div className="mt-1 grid gap-2 sm:grid-cols-2">
                                        {scopes.map((scope) => (
                                            <Checkbox
                                                key={scope.value}
                                                checked={form.data.scopes.includes(scope.value)}
                                                onCheckedChange={(checked) =>
                                                    toggleScope(scope.value, checked)
                                                }
                                                label={
                                                    <span className="flex items-center gap-2">
                                                        <span className="mono text-sm">
                                                            {scope.value}
                                                        </span>
                                                        {/*
                                                            The one distinction that matters
                                                            when ticking boxes for a credential
                                                            that can provision people.
                                                        */}
                                                        {scope.writes && (
                                                            <Badge tone="warn">writes</Badge>
                                                        )}
                                                    </span>
                                                }
                                            />
                                        ))}
                                    </div>
                                </fieldset>
                                {form.errors.scopes !== undefined && (
                                    <p className="field-error" role="alert">
                                        {form.errors.scopes}
                                    </p>
                                )}
                            </div>

                            <Button type="submit" variant="primary" loading={form.processing}>
                                Issue key
                            </Button>
                        </form>
                    </Panel>
                </div>
            )}

            <ConfirmDelete
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                name={revoking?.name ?? ''}
                verb="Revoke"
                consequence="Whatever is using this key stops working immediately. This cannot be undone."
                onConfirm={() => {
                    const key = revoking;
                    setRevoking(null);

                    if (key !== null) {
                        router.delete(key.revokeHref, {
                            data: { environment: selected },
                            preserveScroll: true,
                        });
                    }
                }}
            />
        </>
    );
}

/**
 * The key, shown exactly once.
 *
 * It brings itself into view and takes focus with it: the form that mints it is at the
 * bottom of the page and the key appears above the list, so the only feedback in the
 * viewport was otherwise a toast in the opposite corner.
 */
function RevealedKey({ value }: { value: string }) {
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
                Copy your key now — you won't be able to see it again.
            </p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                Only a hash is stored. If it is lost, revoke it and issue another.
            </p>

            <div className="mt-4 flex items-start gap-2">
                <code className="mono text-sm break-all select-all flex-1">{value}</code>
                <CopyButton value={value} variant="primary" label="Copy key" />
            </div>
        </div>
    );
}

EnvironmentKeys.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
