import type { InertiaFormProps } from '@inertiajs/react';
import { Checkbox, Field, Input, Panel, type PillTone, RadioGroup, Select, Textarea } from '@/ui';

export interface Option {
    value: string;
    label: string;
}

export interface DestinationOption extends Option {
    defaultAuth: string;
}

export interface StreamOptionsForm {
    site: string;
    service: string;
    source: string;
    /** One comma-separated line: `env:prod, team:security`. */
    tags: string;
    hostname: string;
    bucket: string;
    region: string;
    prefix: string;
    access_key_id: string;
    role_arn: string;
    sse: string;
    kms_key_id: string;
    path_style: boolean;
    gzip: boolean;
}

export interface StreamForm {
    name: string;
    destination: string;
    endpointUrl: string;
    scheme: string;
    secret: string;
    /** S3 only, and the form's own: which of the two credential fields it sends. */
    credential: 'access_key' | 'role';
    options: StreamOptionsForm;
}

export interface StreamChoices {
    destinations: DestinationOption[];
    schemes: Option[];
    datadogSites: Option[];
    /** Whether the platform has its own AWS identity to assume a customer's role with. */
    assumedRoleAvailable: boolean;
}

const CLOUD = ['datadog', 's3', 'gcs'];

export type StreamHealth = 'healthy' | 'degraded' | 'paused' | 'action_required';

/** A stream's delivery health, said in words — the tone never carries it alone. */
export const HEALTH: Record<StreamHealth, { label: string; tone: PillTone }> = {
    healthy: { label: 'Delivering', tone: 'success' },
    degraded: { label: 'Retrying', tone: 'warning' },
    paused: { label: 'Paused after failures', tone: 'warning' },
    action_required: { label: 'Action required', tone: 'destructive' },
};

export const emptyOptions: StreamOptionsForm = {
    site: 'datadoghq.com',
    service: '',
    source: '',
    tags: '',
    hostname: '',
    bucket: '',
    region: '',
    prefix: '',
    access_key_id: '',
    role_arn: '',
    sse: '',
    kms_key_id: '',
    path_style: false,
    gzip: true,
};

/** True for the destinations configured with options rather than a collector URL. */
export function isCloud(destination: string): boolean {
    return CLOUD.includes(destination);
}

/**
 * What the form sends: the S3 credential it is NOT using emptied, so an edit that moves a
 * stream from an access key to a role removes the key rather than keeping both.
 */
export function submittable(data: StreamForm): StreamForm {
    if (data.destination !== 's3') {
        return data;
    }

    return {
        ...data,
        secret: data.credential === 'role' ? '' : data.secret,
        options: {
            ...data.options,
            access_key_id: data.credential === 'role' ? '' : data.options.access_key_id,
            role_arn: data.credential === 'role' ? data.options.role_arn : '',
        },
    };
}

/**
 * The label the credential goes by, in the vocabulary of whoever issued it: a Splunk "HEC
 * token", a Datadog "API key", an AWS "secret access key", a Google "service account key".
 */
function secretLabel(destination: string, scheme: string): string {
    switch (destination) {
        case 'datadog':
            return 'API key';
        case 's3':
            return 'Secret access key';
        case 'gcs':
            return 'Service account key (JSON)';
        case 'splunk_hec':
            return scheme === 'hmac' ? 'Signing key' : 'HEC token';
        default:
            return scheme === 'hmac' ? 'Signing key' : 'Token';
    }
}

/**
 * THE DESTINATION, asked once for the new-stream form and the edit form alike: where the
 * entries go and how the destination knows they are ours. An HTTP collector is a URL and a
 * token; Datadog is a site and an API key; a bucket is a bucket, a region and a credential
 * — so the form changes with the destination rather than asking every question of every
 * one. `hasSecret` is the edit form's: there is a credential on file, and an empty field
 * keeps it.
 */
export function DestinationFields({
    form,
    choices,
    hasSecret = false,
}: {
    form: InertiaFormProps<StreamForm>;
    choices: StreamChoices;
    hasSecret?: boolean;
}) {
    const { data } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const cloud = isCloud(data.destination);
    const option = <K extends keyof StreamOptionsForm>(key: K, value: StreamOptionsForm[K]) =>
        form.setData((current) => ({ ...current, options: { ...current.options, [key]: value } }));
    const text = (key: Exclude<keyof StreamOptionsForm, 'path_style' | 'gzip'>) => ({
        name: `options.${key}`,
        value: data.options[key],
        onChange: (event: React.ChangeEvent<HTMLInputElement>) => option(key, event.target.value),
    });

    // Leaving the secret empty on the HMAC scheme is what asks for a generated key, and
    // that key is shown exactly once. Worth saying beside the field rather than leaving
    // somebody to discover it by submitting.
    const generatesKey = !cloud && data.scheme === 'hmac' && data.secret === '' && !hasSecret;
    const keepHint = hasSecret ? ' Leave it empty to keep the current one.' : '';

    return (
        <>
            <Panel title="Where it goes">
                <div className="space-y-4">
                    <Field
                        label="Destination"
                        hint="Splunk, Elastic, Graylog, CEF and Generic JSON are HTTP collectors you give a URL. Datadog, Amazon S3 and Google Cloud Storage take their own settings."
                        error={errors.destination}
                    >
                        <Select
                            name="destination"
                            value={data.destination}
                            onValueChange={(destination) => {
                                const chosen = choices.destinations.find(
                                    (candidate) => candidate.value === destination,
                                );
                                form.setData((current) => ({
                                    ...current,
                                    destination,
                                    scheme: chosen?.defaultAuth ?? current.scheme,
                                }));
                            }}
                            options={choices.destinations.map(({ value, label }) => ({
                                value,
                                label,
                            }))}
                        />
                    </Field>

                    {!cloud && (
                        <Field label="Endpoint URL" error={errors.endpointUrl}>
                            <Input
                                name="endpointUrl"
                                type="url"
                                className="mono"
                                placeholder="https://siem.example.com/services/collector"
                                value={data.endpointUrl}
                                onChange={(event) =>
                                    form.setData('endpointUrl', event.target.value)
                                }
                            />
                        </Field>
                    )}

                    {data.destination === 'datadog' && (
                        <>
                            <Field
                                label="Datadog site"
                                hint="The site your account lives on — the domain of the Datadog URL you sign in at. An API key only works on its own site."
                                error={errors['options.site']}
                            >
                                <Select
                                    name="options.site"
                                    value={data.options.site}
                                    onValueChange={(site) => option('site', site)}
                                    options={choices.datadogSites}
                                />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label="Service"
                                    optional
                                    hint="The service attribute. Defaults to this platform's name."
                                    error={errors['options.service']}
                                >
                                    <Input {...text('service')} className="mono" />
                                </Field>
                                <Field
                                    label="Source"
                                    optional
                                    hint="The ddsource attribute. Defaults to cbox."
                                    error={errors['options.source']}
                                >
                                    <Input {...text('source')} className="mono" />
                                </Field>
                            </div>
                            <Field
                                label="Tags"
                                optional
                                hint="key:value tags, separated by commas."
                                error={errors['options.tags']}
                            >
                                <Input
                                    {...text('tags')}
                                    className="mono"
                                    placeholder="env:prod, team:security"
                                />
                            </Field>
                            <Field
                                label="Hostname"
                                optional
                                hint="The hostname attribute. Defaults to this platform's host."
                                error={errors['options.hostname']}
                            >
                                <Input {...text('hostname')} className="mono" />
                            </Field>
                        </>
                    )}

                    {(data.destination === 's3' || data.destination === 'gcs') && (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Bucket" error={errors['options.bucket']}>
                                    <Input
                                        {...text('bucket')}
                                        className="mono"
                                        placeholder="acme-audit-logs"
                                    />
                                </Field>
                                {data.destination === 's3' && (
                                    <Field
                                        label="AWS Region"
                                        hint="For example eu-west-1. Cloudflare R2 uses auto."
                                        error={errors['options.region']}
                                    >
                                        <Input
                                            {...text('region')}
                                            className="mono"
                                            placeholder="eu-west-1"
                                        />
                                    </Field>
                                )}
                            </div>
                            <Field
                                label="Prefix"
                                optional
                                hint="Objects are written to prefix/yyyy/mm/dd/hh/batch.ndjson.gz, one event per line."
                                error={errors['options.prefix']}
                            >
                                <Input
                                    {...text('prefix')}
                                    className="mono"
                                    placeholder="cbox/audit"
                                />
                            </Field>
                            <Checkbox
                                label="Compress objects (gzip)"
                                hint="Off writes plain .ndjson."
                                checked={data.options.gzip}
                                onCheckedChange={(gzip) => option('gzip', gzip)}
                            />
                        </>
                    )}
                </div>
            </Panel>

            <Panel title="How it authenticates">
                <div className="space-y-4">
                    {!cloud && (
                        <Field label="Auth scheme" error={errors.scheme}>
                            <Select
                                name="scheme"
                                value={data.scheme}
                                onValueChange={(scheme) => form.setData('scheme', scheme)}
                                options={choices.schemes}
                            />
                        </Field>
                    )}

                    {data.destination === 's3' && (
                        <>
                            <RadioGroup
                                label="Credentials"
                                value={data.credential}
                                onValueChange={(credential) =>
                                    form.setData('credential', credential)
                                }
                                options={[
                                    {
                                        value: 'access_key',
                                        label: 'Access key',
                                        hint: 'An IAM user allowed only s3:PutObject on the bucket. Its secret access key is stored encrypted.',
                                    },
                                    {
                                        value: 'role',
                                        label: 'Assume an IAM role',
                                        hint: choices.assumedRoleAvailable
                                            ? 'No secret is stored. The role trusts this platform with an external ID you are shown once it is created.'
                                            : 'Not set up on this platform: it has no AWS identity of its own to assume a role with.',
                                        disabled:
                                            !choices.assumedRoleAvailable &&
                                            data.credential !== 'role',
                                    },
                                ]}
                            />
                            {data.credential === 'role' ? (
                                <Field label="IAM role ARN" error={errors['options.role_arn']}>
                                    <Input
                                        {...text('role_arn')}
                                        className="mono"
                                        placeholder="arn:aws:iam::123456789012:role/cbox-audit-writer"
                                    />
                                </Field>
                            ) : (
                                <Field
                                    label="Access key ID"
                                    error={errors['options.access_key_id']}
                                >
                                    <Input
                                        {...text('access_key_id')}
                                        className="mono"
                                        autoComplete="off"
                                        placeholder="AKIA…"
                                    />
                                </Field>
                            )}
                        </>
                    )}

                    {(cloud
                        ? !(data.destination === 's3' && data.credential === 'role')
                        : data.scheme !== 'none') && (
                        <Field
                            label={secretLabel(data.destination, data.scheme)}
                            optional={generatesKey || hasSecret}
                            hint={
                                generatesKey
                                    ? 'Left empty, a signing key is generated for you and shown once — it is stored encrypted and cannot be retrieved again.'
                                    : data.destination === 'gcs'
                                      ? `Paste the whole JSON key file of a service account granted roles/storage.objectCreator on the bucket. Stored encrypted and never shown again.${keepHint}`
                                      : data.destination === 'datadog'
                                        ? `An API key (Organization Settings › API Keys), not an application key. Stored encrypted and never shown again.${keepHint}`
                                        : `Stored encrypted and never shown again.${keepHint}`
                            }
                            error={errors.secret}
                        >
                            {data.destination === 'gcs' ? (
                                <Textarea
                                    name="secret"
                                    className="mono"
                                    rows={6}
                                    autoComplete="off"
                                    spellCheck={false}
                                    placeholder={
                                        hasSecret ? '••••••••' : '{ "type": "service_account", … }'
                                    }
                                    value={data.secret}
                                    onChange={(event) => form.setData('secret', event.target.value)}
                                />
                            ) : (
                                <Input
                                    name="secret"
                                    type="password"
                                    className="mono"
                                    autoComplete="off"
                                    placeholder={hasSecret ? '••••••••' : undefined}
                                    value={data.secret}
                                    onChange={(event) => form.setData('secret', event.target.value)}
                                />
                            )}
                        </Field>
                    )}

                    {data.destination === 's3' && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label="Server-side encryption" error={errors['options.sse']}>
                                <Select
                                    name="options.sse"
                                    value={data.options.sse === '' ? 'default' : data.options.sse}
                                    onValueChange={(sse) =>
                                        option('sse', sse === 'default' ? '' : sse)
                                    }
                                    options={[
                                        { value: 'default', label: "The bucket's default" },
                                        { value: 'AES256', label: 'SSE-S3 (AES256)' },
                                        { value: 'aws:kms', label: 'SSE-KMS (aws:kms)' },
                                    ]}
                                />
                            </Field>
                            {data.options.sse === 'aws:kms' && (
                                <Field
                                    label="AWS KMS key"
                                    optional
                                    hint="Key ID, alias or ARN. Empty uses the AWS managed key."
                                    error={errors['options.kms_key_id']}
                                >
                                    <Input
                                        {...text('kms_key_id')}
                                        className="mono"
                                        placeholder="alias/audit"
                                    />
                                </Field>
                            )}
                        </div>
                    )}
                </div>
            </Panel>

            {cloud && data.destination !== 'datadog' && (
                <Panel
                    title="Custom endpoint"
                    description={
                        data.destination === 's3'
                            ? 'Only for an S3-compatible store such as MinIO or Cloudflare R2. Empty uses the AWS endpoint for the region.'
                            : 'Empty uses https://storage.googleapis.com.'
                    }
                >
                    <div className="space-y-4">
                        <Field label="Endpoint URL" optional error={errors.endpointUrl}>
                            <Input
                                name="endpointUrl"
                                type="url"
                                className="mono"
                                placeholder={
                                    data.destination === 's3'
                                        ? 'https://<account>.r2.cloudflarestorage.com'
                                        : 'https://storage.googleapis.com'
                                }
                                value={data.endpointUrl}
                                onChange={(event) =>
                                    form.setData('endpointUrl', event.target.value)
                                }
                            />
                        </Field>
                        {data.destination === 's3' && (
                            <Checkbox
                                label="Force path-style addressing"
                                hint="The bucket in the path rather than the host name. A custom endpoint already uses it."
                                checked={data.options.path_style}
                                onCheckedChange={(pathStyle) => option('path_style', pathStyle)}
                            />
                        )}
                    </div>
                </Panel>
            )}
        </>
    );
}
