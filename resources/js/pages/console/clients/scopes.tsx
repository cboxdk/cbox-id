import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Badge, Button, Checkbox, Field, Input, Panel } from '@/ui';
import { AppFrame, type AppHeaderData } from './frame';

interface ScopeOption {
    key: string;
    label: string;
    description: string;
}

/** `App\Http\Props\Console\ApiScopeGroupProps` */
interface ApiGroup {
    id: string;
    name: string;
    identifier: string;
    owner: string;
    scopes: { key: string; description: string | null }[];
}

/** `App\Http\Props\Console\AppAudienceProps` */
interface Audience {
    shape: 'issuer' | 'api' | 'several';
    issuer: string;
    identifiers: string[];
    withIssuer: boolean;
    unowned: string[];
    refused: string[];
}

type Props = PageProps<{
    appHeader: AppHeaderData;
    stored: { catalogue: string[]; api: string[]; custom: string; all: string[] };
    scopeGroups: Record<string, ScopeOption[]>;
    apiGroups: ApiGroup[];
    audience: Audience;
    mayManage: boolean;
    updateHref: string;
    /** The environment console's APIs page; null on an organization's console. */
    apisHref: string | null;
}>;

export default function ClientScopes({
    appHeader,
    stored,
    scopeGroups,
    apiGroups,
    audience,
    mayManage,
    updateHref,
    apisHref,
}: Props) {
    const form = useForm({
        scopes: [...stored.catalogue, ...stored.api],
        customScopes: stored.custom,
    });

    const toggle = (key: string, checked: boolean): void =>
        form.setData(
            'scopes',
            checked ? [...form.data.scopes, key] : form.data.scopes.filter((s) => s !== key),
        );

    return (
        <AppFrame app={appHeader}>
            <AudiencePanel audience={audience} />

            {!mayManage ? (
                <Panel title="Scopes">
                    {stored.all.length === 0 ? (
                        <span className="text-sm" style={{ color: 'var(--faint)' }}>
                            —
                        </span>
                    ) : (
                        <div className="flex flex-wrap gap-1.5">
                            {stored.all.map((scope) => (
                                <Badge key={scope} className="mono">
                                    {scope}
                                </Badge>
                            ))}
                        </div>
                    )}
                </Panel>
            ) : (
                <form
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(updateHref, { preserveScroll: true });
                    }}
                >
                    <Panel
                        title="What this app may ask for"
                        description="The ceiling on this app's access. Narrowing it takes effect on the next token, and a device or agent request naming a scope you remove is refused rather than quietly given less."
                    >
                        <div className="space-y-4">
                            {Object.entries(scopeGroups).map(([group, scopes]) => (
                                <div key={group}>
                                    <p className="cbx-nav-group mb-2">{group}</p>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {scopes.map((scope) => (
                                            <ScopeBox key={scope.key}>
                                                <Checkbox
                                                    checked={form.data.scopes.includes(scope.key)}
                                                    onCheckedChange={(checked) =>
                                                        toggle(scope.key, checked)
                                                    }
                                                    label={
                                                        <span className="flex items-center gap-2 flex-wrap">
                                                            {scope.label}
                                                            <ScopeKey>{scope.key}</ScopeKey>
                                                        </span>
                                                    }
                                                    hint={scope.description}
                                                />
                                            </ScopeBox>
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Panel>

                    <Panel
                        title="Your APIs"
                        description="Scopes of the APIs registered in this environment that this app may hold. A token for one of them names that API as its audience."
                    >
                        {apiGroups.length === 0 ? (
                            <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                                No registered API offers scopes to this app yet.{' '}
                                {apisHref !== null ? (
                                    <Link
                                        href={apisHref}
                                        className="underline"
                                        style={{ color: 'var(--accent-strong)' }}
                                    >
                                        Register an API
                                    </Link>
                                ) : (
                                    'The environment’s administrators register APIs and decide which of their scopes your apps may request.'
                                )}
                            </p>
                        ) : (
                            <div className="space-y-5">
                                {apiGroups.map((api) => (
                                    <fieldset key={api.id}>
                                        <legend className="w-full">
                                            <span className="font-medium">{api.name}</span>{' '}
                                            <span
                                                className="text-xs"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                · {api.owner}
                                            </span>
                                            <span
                                                className="block text-xs mono mt-0.5"
                                                style={{
                                                    color: 'var(--faint)',
                                                    overflowWrap: 'anywhere',
                                                }}
                                            >
                                                {api.identifier}
                                            </span>
                                        </legend>
                                        <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                            {api.scopes.map((scope) => (
                                                <ScopeBox key={scope.key}>
                                                    <Checkbox
                                                        checked={form.data.scopes.includes(
                                                            scope.key,
                                                        )}
                                                        onCheckedChange={(checked) =>
                                                            toggle(scope.key, checked)
                                                        }
                                                        label={<ScopeKey>{scope.key}</ScopeKey>}
                                                        hint={scope.description ?? undefined}
                                                    />
                                                </ScopeBox>
                                            ))}
                                        </div>
                                    </fieldset>
                                ))}
                            </div>
                        )}
                    </Panel>

                    <Panel title="Advanced">
                        <Field
                            label="Typed scopes"
                            hint="Comma- or space-separated. A scope you type is unowned: it reaches this app's tokens as it is, but it can never carry a registered API's audience — use the lists above for those."
                            error={form.errors.customScopes}
                        >
                            <Input
                                name="customScopes"
                                className="mono"
                                spellCheck={false}
                                placeholder="api.read, reports.export"
                                value={form.data.customScopes}
                                onChange={(event) =>
                                    form.setData('customScopes', event.target.value)
                                }
                            />
                        </Field>
                    </Panel>

                    {form.errors.scopes !== undefined && (
                        <div
                            role="alert"
                            className="rounded-xl border p-4 text-sm"
                            style={{
                                borderColor:
                                    'color-mix(in oklch, var(--destructive) 40%, transparent)',
                                background: 'var(--destructive-soft)',
                            }}
                        >
                            <p
                                className="font-medium"
                                style={{ color: 'var(--destructive-strong)' }}
                            >
                                Scopes not saved
                            </p>
                            <p className="mt-1">{form.errors.scopes}</p>
                        </div>
                    )}

                    <Button type="submit" variant="primary" loading={form.processing}>
                        Save scopes
                    </Button>
                </form>
            )}
        </AppFrame>
    );
}

/**
 * WHERE THIS APP'S TOKENS WILL GO, said before the first one is minted.
 *
 * A registered API's scope changes the `aud` of every token that carries it, and a scope
 * of a second API makes a request without `resource` fail. Both are true the moment the
 * boxes are saved, and both used to be found out at the resource server.
 */
function AudiencePanel({ audience }: { audience: Audience }) {
    return (
        <Panel title="Token audience">
            <div className="space-y-2 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {audience.shape === 'issuer' && (
                    <p>
                        Access tokens for this app name this environment's issuer,{' '}
                        <Mono>{audience.issuer}</Mono>, as their audience. That changes when it
                        holds a scope of one of your registered APIs.
                    </p>
                )}
                {audience.shape === 'api' && (
                    <p>
                        Access tokens for this app name <Mono>{audience.identifiers[0]}</Mono> as
                        their audience
                        {audience.withIssuer ? (
                            <>
                                , together with the issuer <Mono>{audience.issuer}</Mono> because
                                the app also signs people in
                            </>
                        ) : null}
                        . That API should accept a token only when it names it.
                    </p>
                )}
                {audience.shape === 'several' && (
                    <p>
                        This app holds scopes of more than one API:{' '}
                        {audience.identifiers.map((identifier, index) => (
                            <span key={identifier}>
                                {index > 0 && ', '}
                                <Mono>{identifier}</Mono>
                            </span>
                        ))}
                        . A token is for one API at a time, so the app names the one it wants with
                        the <Mono>resource</Mono> parameter — a request for scopes of two APIs at
                        once is refused.
                    </p>
                )}
                {audience.unowned.length > 0 && (
                    <p>
                        Typed scopes (<Mono>{audience.unowned.join(' ')}</Mono>) are unowned: they
                        travel as they are, and never on a registered API's audience.
                    </p>
                )}
                {audience.refused.length > 0 && (
                    <p role="note" style={{ color: 'var(--warning-strong)' }}>
                        <Mono>{audience.refused.join(' ')}</Mono> belong to an API this app may not
                        use. They are left out of every token; remove them from Typed scopes.
                    </p>
                )}
            </div>
        </Panel>
    );
}

function ScopeBox({ children }: { children: React.ReactNode }) {
    return (
        <div className="rounded-lg p-2.5" style={{ border: '1px solid var(--border)' }}>
            {children}
        </div>
    );
}

function ScopeKey({ children }: { children: React.ReactNode }) {
    return (
        <span
            className="text-xs rounded-full px-2 py-0.5 mono"
            style={{
                background: 'var(--surface-2)',
                color: 'var(--muted-foreground)',
                overflowWrap: 'anywhere',
            }}
        >
            {children}
        </span>
    );
}

function Mono({ children }: { children: React.ReactNode }) {
    return (
        <code className="mono" style={{ color: 'var(--foreground)', overflowWrap: 'anywhere' }}>
            {children}
        </code>
    );
}

ClientScopes.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
