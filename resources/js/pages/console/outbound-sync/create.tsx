import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    Button,
    Checkbox,
    Field,
    Icon,
    Input,
    PageHeader,
    Panel,
    RadioGroup,
    Textarea,
} from '@/ui';

interface SchemeOption {
    value: string;
    label: string;
    hint: string;
}

type Props = PageProps<{
    schemes: SchemeOption[];
    mayScopeEnvironmentWide: boolean;
    organizationChosen: boolean;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateOutboundSync({
    schemes,
    mayScopeEnvironmentWide,
    organizationChosen,
    indexHref,
    storeHref,
}: Props) {
    const form = useForm({
        name: '',
        baseUrl: '',
        scheme: schemes[0]?.value ?? 'bearer',
        secret: '',
        environmentWide: false,
        tokenUrl: '',
        clientId: '',
        scope: '',
    });

    // OAuth needs a token endpoint and a client id; a bearer connection does not, and
    // asking for them anyway is how a form teaches people to fill in fields that mean
    // nothing. The server requires them for the same scheme and only that one.
    const usesClientCredentials = form.data.scheme === 'oauth2_client_credentials';

    const needsOrganization = !organizationChosen && !form.data.environmentWide;

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
                Sync users out
            </Link>

            <div className="mt-2">
                <PageHeader description="A SCIM endpoint we push your people to. Joins, changes and departures are sent as they happen." />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '40rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref, { onFinish: () => form.setData('secret', '') });
                }}
            >
                <Panel title="Where it goes">
                    <div className="space-y-4">
                        <Field label="Name" error={form.errors.name}>
                            <Input
                                name="name"
                                placeholder="Datadog"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <Field
                            label="SCIM base URL"
                            hint="The endpoint the app documents for SCIM — usually ending in /scim/v2."
                            error={form.errors.baseUrl}
                        >
                            <Input
                                name="baseUrl"
                                type="url"
                                className="mono"
                                placeholder="https://app.example.com/scim/v2"
                                value={form.data.baseUrl}
                                onChange={(event) => form.setData('baseUrl', event.target.value)}
                            />
                        </Field>

                        {mayScopeEnvironmentWide && (
                            <Checkbox
                                checked={form.data.environmentWide}
                                onCheckedChange={(checked) =>
                                    form.setData('environmentWide', checked)
                                }
                                label="Send every organization in this environment"
                                hint="Every tenant's people are pushed to this one target. Not the same as choosing an organization above — this is a platform-wide connection."
                            />
                        )}

                        {needsOrganization && (
                            <output
                                className="block text-sm"
                                style={{ color: 'var(--warning-strong)' }}
                            >
                                Choose an organization in the console header, or register the
                                connection for the whole environment.
                            </output>
                        )}
                    </div>
                </Panel>

                <Panel
                    title="How it authenticates"
                    description="What we present to the app on every push."
                >
                    <div className="space-y-4">
                        <RadioGroup
                            label="Auth scheme"
                            value={form.data.scheme}
                            onValueChange={(scheme) => form.setData('scheme', scheme)}
                            options={schemes.map((scheme) => ({
                                value: scheme.value,
                                label: scheme.label,
                                hint: scheme.hint,
                            }))}
                        />

                        <Field
                            label={usesClientCredentials ? 'Client secret' : 'Bearer token'}
                            hint="Sealed at rest and never shown again."
                            error={form.errors.secret}
                        >
                            <Textarea
                                name="secret"
                                rows={2}
                                className="mono"
                                spellCheck={false}
                                autoComplete="off"
                                value={form.data.secret}
                                onChange={(event) => form.setData('secret', event.target.value)}
                            />
                        </Field>

                        {usesClientCredentials && (
                            <>
                                <Field
                                    label="Token URL"
                                    hint="Where we exchange the client credentials for a short-lived token, before each batch."
                                    error={form.errors.tokenUrl}
                                >
                                    <Input
                                        name="tokenUrl"
                                        type="url"
                                        className="mono"
                                        placeholder="https://app.example.com/oauth/token"
                                        value={form.data.tokenUrl}
                                        onChange={(event) =>
                                            form.setData('tokenUrl', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field label="Client ID" error={form.errors.clientId}>
                                    <Input
                                        name="clientId"
                                        className="mono"
                                        value={form.data.clientId}
                                        onChange={(event) =>
                                            form.setData('clientId', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field label="Scope" optional error={form.errors.scope}>
                                    <Input
                                        name="scope"
                                        className="mono"
                                        placeholder="scim:write"
                                        value={form.data.scope}
                                        onChange={(event) =>
                                            form.setData('scope', event.target.value)
                                        }
                                    />
                                </Field>
                            </>
                        )}
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Register connection
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateOutboundSync.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
