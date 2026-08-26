import { Link, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Checkbox, Field, Icon, Input, Panel, RadioGroup, Textarea } from '@/ui';

interface AppKindOption {
    value: string;
    label: string;
    description: string;
    needsRedirectUris: boolean;
    confidential: boolean;
    defaultScopes: string[];
    /** How this kind's sign-in actually goes, in a few lines with `<b>` emphasis. */
    flow: string[];
}

interface ScopeOption {
    key: string;
    label: string;
    description: string;
    category: string;
    recommended: boolean;
}

type Props = PageProps<{
    scopeGroups: Record<string, ScopeOption[]>;
    appKinds: AppKindOption[];
    /** Only a plane that CHOOSES an organization may register an environment-wide app. */
    mayScopeEnvironmentWide: boolean;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateClient({
    scopeGroups,
    appKinds,
    mayScopeEnvironmentWide,
    indexHref,
    storeHref,
}: Props) {
    const form = useForm({
        name: '',
        // The ONE question. Everything the specification calls a decision follows from it.
        kind: appKinds[0]?.value ?? 'web',
        type: 'confidential',
        grantAuthorizationCode: true,
        grantClientCredentials: false,
        scopes: appKinds[0]?.defaultScopes ?? ['openid', 'profile', 'email', 'offline_access'],
        customScopes: '',
        redirectUris: '',
        postLogoutRedirectUris: '',
        manifestUrl: '',
        firstParty: false,
        environmentWide: false,
    });

    const kind = useMemo(
        () => appKinds.find((candidate) => candidate.value === form.data.kind) ?? appKinds[0],
        [appKinds, form.data.kind],
    );

    const advanced = form.data.kind === 'advanced';

    // Asked of the KIND, not of a checkbox: a CLI has no callback URL, and the field
    // asking for one was what made people believe they had chosen wrong.
    const needsRedirectUris = advanced
        ? form.data.grantAuthorizationCode
        : kind?.needsRedirectUris === true;

    /**
     * Follow the chosen kind, unless the person has already disagreed with it.
     *
     * Re-applying the defaults on every change would silently undo a deliberate tick, so
     * the scopes are only rewritten while they still ARE the previous kind's defaults.
     * Somebody who has curated the list keeps their list.
     */
    const chooseKind = (next: string): void => {
        const previous = appKinds.find((candidate) => candidate.value === form.data.kind);
        const chosen = appKinds.find((candidate) => candidate.value === next);

        const untouched =
            previous !== undefined &&
            [...form.data.scopes].sort().join() === [...previous.defaultScopes].sort().join();

        form.setData((current) => ({
            ...current,
            kind: next,
            scopes: untouched && chosen !== undefined ? chosen.defaultScopes : current.scopes,
            // Only Advanced answers this by hand.
            type:
                chosen === undefined || next === 'advanced'
                    ? current.type
                    : chosen.confidential
                      ? 'confidential'
                      : 'public',
        }));
    };

    const toggleScope = (key: string, checked: boolean): void => {
        form.setData(
            'scopes',
            checked ? [...form.data.scopes, key] : form.data.scopes.filter((s) => s !== key),
        );
    };

    return (
        <>
            <Link
                href={indexHref}
                className="text-sm inline-flex items-center gap-1"
                style={{ color: 'var(--muted-foreground)' }}
            >
                <Icon
                    name="chevron"
                    className="w-3.5 h-3.5"
                    style={{ transform: 'rotate(90deg)' }}
                />
                Apps &amp; API keys
            </Link>

            <h1 className="cbx-page-title mt-2">New app</h1>
            {/*
                The old subtitle named exactly two modes — "signing people in" and
                "machine-to-machine" — which is the same two-checkbox model this form has
                stopped using, and it excluded a CLI and an agent by omission. It also
                promised a client secret, which a public app never receives.
            */}
            <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                Register an app so it can sign your people in, act on their behalf, or call the API
                as itself. Answer what kind it is and Cbox ID picks the flow, the credentials and
                the scopes to match.
            </p>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '42rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref);
                }}
            >
                <Panel>
                    <Field label="App name" error={form.errors.name}>
                        <Input
                            name="name"
                            placeholder="Acme Dashboard"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>

                    <div className="mt-5">
                        <RadioGroup
                            label="What kind of app is it?"
                            value={form.data.kind}
                            onValueChange={chooseKind}
                            options={appKinds.map((option) => ({
                                value: option.value,
                                label: option.label,
                                hint: option.description,
                            }))}
                        />
                    </div>

                    {/*
                        WHAT WILL HAPPEN, in the words of the flow this kind uses. The
                        picker asks one question and decides four things; this is where the
                        person can check that the answer matches the app in their head.
                    */}
                    {kind !== undefined && kind.flow.length > 0 && (
                        <div
                            className="mt-4 rounded-lg p-3 text-sm"
                            style={{ background: 'var(--secondary)' }}
                        >
                            <ol className="space-y-1" style={{ listStyle: 'decimal inside' }}>
                                {kind.flow.map((step) => (
                                    // The copy carries `<b>` emphasis from the server. It is
                                    // a fixed string in the enum, not user input — there is
                                    // no path by which a person's text reaches it.
                                    <li
                                        key={step}
                                        // eslint-disable-next-line react/no-danger
                                        dangerouslySetInnerHTML={{ __html: step }}
                                    />
                                ))}
                            </ol>
                            <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                                {kind.confidential
                                    ? 'It gets a client secret, shown once on the next screen.'
                                    : 'It uses PKCE and gets no secret — nothing to leak from a browser or a phone.'}
                            </p>
                        </div>
                    )}
                </Panel>

                {advanced && (
                    <Panel
                        title="Advanced"
                        description="Answer the specification's questions yourself. Everything above sets these for you."
                    >
                        <RadioGroup
                            label="Client type"
                            value={form.data.type}
                            onValueChange={(type) => form.setData('type', type)}
                            options={[
                                {
                                    value: 'confidential',
                                    label: 'Confidential',
                                    hint: 'Runs on a server and can keep a secret.',
                                },
                                {
                                    value: 'public',
                                    label: 'Public',
                                    hint: 'Runs in a browser, a phone or a terminal — PKCE, no secret.',
                                },
                            ]}
                        />

                        <div className="mt-4 space-y-2">
                            <Checkbox
                                checked={form.data.grantAuthorizationCode}
                                onCheckedChange={(checked) =>
                                    form.setData('grantAuthorizationCode', checked)
                                }
                                label="Signs people in (authorization code + refresh)"
                            />
                            <Checkbox
                                checked={form.data.grantClientCredentials}
                                onCheckedChange={(checked) =>
                                    form.setData('grantClientCredentials', checked)
                                }
                                label="Calls the API as itself (client credentials)"
                            />
                            {form.errors.grantAuthorizationCode !== undefined && (
                                <p className="field-error" role="alert">
                                    {form.errors.grantAuthorizationCode}
                                </p>
                            )}
                        </div>
                    </Panel>
                )}

                {needsRedirectUris && (
                    <Panel
                        title="Where Cbox ID may return people to"
                        description="One URL per line. Exact matches only — https, or http on localhost."
                    >
                        <Field label="Redirect URIs" error={form.errors.redirectUris}>
                            <Textarea
                                name="redirectUris"
                                rows={3}
                                spellCheck={false}
                                placeholder="https://app.example.com/auth/callback"
                                value={form.data.redirectUris}
                                onChange={(event) =>
                                    form.setData('redirectUris', event.target.value)
                                }
                            />
                        </Field>

                        <div className="mt-4">
                            <Field
                                label="Sign-out URIs"
                                hint="Optional. Where sign-out may send people back to — held to the same bar."
                                error={form.errors.postLogoutRedirectUris}
                            >
                                <Textarea
                                    name="postLogoutRedirectUris"
                                    rows={2}
                                    spellCheck={false}
                                    placeholder="https://app.example.com/signed-out"
                                    value={form.data.postLogoutRedirectUris}
                                    onChange={(event) =>
                                        form.setData('postLogoutRedirectUris', event.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    </Panel>
                )}

                <Panel
                    title="What it may ask for"
                    description="The ceiling on this app's tokens. It can ask for less; it can never ask for more."
                >
                    <div className="space-y-5">
                        {Object.entries(scopeGroups).map(([category, scopes]) => (
                            <div key={category}>
                                <p className="cbx-nav-group mb-2">{category}</p>
                                <div className="space-y-2">
                                    {scopes.map((scope) => (
                                        <Checkbox
                                            key={scope.key}
                                            checked={form.data.scopes.includes(scope.key)}
                                            onCheckedChange={(checked) =>
                                                toggleScope(scope.key, checked)
                                            }
                                            label={scope.label}
                                            hint={scope.description}
                                        />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>

                    {advanced && (
                        <div className="mt-5">
                            <Field
                                label="Custom scopes"
                                hint="Comma-separated. Only for keys this catalogue does not offer."
                                error={form.errors.customScopes}
                            >
                                <Input
                                    name="customScopes"
                                    spellCheck={false}
                                    placeholder="billing:read, reports:write"
                                    value={form.data.customScopes}
                                    onChange={(event) =>
                                        form.setData('customScopes', event.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    )}
                </Panel>

                <Panel
                    title="Roles"
                    description="Where this app publishes the roles it understands, so they can be assigned here."
                >
                    <Field
                        label="Manifest URL"
                        hint="Optional. Cbox ID fetches it now and on a schedule."
                        error={form.errors.manifestUrl}
                    >
                        <Input
                            name="manifestUrl"
                            type="url"
                            spellCheck={false}
                            placeholder="https://app.example.com/.well-known/cbox-roles.json"
                            value={form.data.manifestUrl}
                            onChange={(event) => form.setData('manifestUrl', event.target.value)}
                        />
                    </Field>
                </Panel>

                {/*
                    The environment plane's own capability. An app with no organization is
                    the platform's own: marked first-party it skips the consent screen for
                    EVERY organization here and appears in each of their launchers — so it
                    is offered only where somebody holds all of them.
                */}
                {mayScopeEnvironmentWide && (
                    <Panel title="Who owns it">
                        <div className="space-y-2">
                            <Checkbox
                                checked={form.data.environmentWide}
                                onCheckedChange={(checked) =>
                                    form.setData('environmentWide', checked)
                                }
                                label="Register it to this environment rather than to an organization"
                                hint="A platform app, not a tenant's."
                            />
                            <Checkbox
                                checked={form.data.firstParty}
                                onCheckedChange={(checked) => form.setData('firstParty', checked)}
                                label="First-party"
                                hint="Skips the consent screen and appears in every organization's launcher."
                            />
                        </div>
                    </Panel>
                )}

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Register app
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateClient.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
