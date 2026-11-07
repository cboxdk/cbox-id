import { useTranslator as usePublicTranslator } from '@/i18n';
import type { Translator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
export const HEALTH = (
    t: Translator['t'],
): Record<StreamHealth, { label: string; tone: PillTone }> => ({
    healthy: { label: t('console.delivering_a0760d3d'), tone: 'success' },
    degraded: { label: t('console.retrying_f8fe6f86'), tone: 'warning' },
    paused: { label: t('console.paused_after_failures_d3f375a7'), tone: 'warning' },
    action_required: { label: t('console.action_required_adf69e75'), tone: 'destructive' },
});

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
function secretLabel(destination: string, scheme: string, tc: Translator['t']): string {
    switch (destination) {
        case 'datadog':
            return tc('console.api_key_16f0ee47');
        case 's3':
            return tc('console.secret_access_key_3de6b754');
        case 'gcs':
            return tc('console.service_account_key_json_a67ba73d');
        case 'splunk_hec':
            return scheme === 'hmac'
                ? tc('console.signing_key_49395b95')
                : tc('console.hec_token_fd0b2750');
        default:
            return scheme === 'hmac' ? tc('console.signing_key_49395b95') : 'Token';
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
    const { t: tc } = usePublicTranslator();
    const { t: consoleAttributeText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
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
    const keepHint = hasSecret ? tc('console.leave_it_empty_to_keep_the_current_one_130dfeb6') : '';

    return (
        <>
            <Panel title={consoleT('console.where_it_goes_4d1ca74f')}>
                <div className="space-y-4">
                    <Field
                        label={consoleT('console.destination_293d404a')}
                        hint={consoleT(
                            'console.splunk_elastic_graylog_cef_and_generic_json_are_http_co_8d405816',
                        )}
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
                        <Field
                            label={consoleT('console.endpoint_url_2578179d')}
                            error={errors.endpointUrl}
                        >
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
                                label={consoleT('console.datadog_site_e01eb56a')}
                                hint={consoleT(
                                    'console.the_site_your_account_lives_on_the_domain_of_the_datado_7f128cbf',
                                )}
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
                                    label={consoleT('console.service_d677190e')}
                                    optional
                                    hint={consoleT(
                                        'console.the_service_attribute_defaults_to_this_platform_s_name_23d483a1',
                                    )}
                                    error={errors['options.service']}
                                >
                                    <Input {...text('service')} className="mono" />
                                </Field>
                                <Field
                                    label={consoleT('console.source_0e570ca6')}
                                    optional
                                    hint={consoleT(
                                        'console.the_ddsource_attribute_defaults_to_cbox_8743f5cd',
                                    )}
                                    error={errors['options.source']}
                                >
                                    <Input {...text('source')} className="mono" />
                                </Field>
                            </div>
                            <Field
                                label={consoleT('console.tags_1331275b')}
                                optional
                                hint={consoleT(
                                    'console.key_value_tags_separated_by_commas_b8006cb4',
                                )}
                                error={errors['options.tags']}
                            >
                                <Input
                                    {...text('tags')}
                                    className="mono"
                                    placeholder={tc('console.env_prod_team_security_4d2fdf93')}
                                />
                            </Field>
                            <Field
                                label={consoleT('console.hostname_2db53355')}
                                optional
                                hint={consoleT(
                                    'console.the_hostname_attribute_defaults_to_this_platform_s_host_03e46070',
                                )}
                                error={errors['options.hostname']}
                            >
                                <Input {...text('hostname')} className="mono" />
                            </Field>
                        </>
                    )}

                    {(data.destination === 's3' || data.destination === 'gcs') && (
                        <>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    label={consoleT('console.bucket_c83b6579')}
                                    error={errors['options.bucket']}
                                >
                                    <Input
                                        {...text('bucket')}
                                        className="mono"
                                        placeholder={consoleUiText(
                                            'console.acme_audit_logs_22d3d5bb',
                                        )}
                                    />
                                </Field>
                                {data.destination === 's3' && (
                                    <Field
                                        label={consoleT('console.aws_region_7e489ee6')}
                                        hint={consoleT(
                                            'console.for_example_eu_west_1_cloudflare_r2_uses_auto_d42aeb2a',
                                        )}
                                        error={errors['options.region']}
                                    >
                                        <Input
                                            {...text('region')}
                                            className="mono"
                                            placeholder={consoleUiText(
                                                'console.eu_west_1_d763c260',
                                            )}
                                        />
                                    </Field>
                                )}
                            </div>
                            <Field
                                label={consoleT('console.prefix_74feac5e')}
                                optional
                                hint={consoleT(
                                    'console.objects_are_written_to_prefix_yyyy_mm_dd_hh_batch_ndjso_5c6035ae',
                                )}
                                error={errors['options.prefix']}
                            >
                                <Input
                                    {...text('prefix')}
                                    className="mono"
                                    placeholder={tc('console.cbox_audit_b219da6d')}
                                />
                            </Field>
                            <Checkbox
                                label={consoleT('console.compress_objects_gzip_c8ae75a6')}
                                hint={consoleT('console.off_writes_plain_ndjson_57bf425a')}
                                checked={data.options.gzip}
                                onCheckedChange={(gzip) => option('gzip', gzip)}
                            />
                        </>
                    )}
                </div>
            </Panel>

            <Panel title={consoleT('console.how_it_authenticates_3288609b')}>
                <div className="space-y-4">
                    {!cloud && (
                        <Field
                            label={consoleT('console.auth_scheme_4a894695')}
                            error={errors.scheme}
                        >
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
                                label={consoleT('console.credentials_d48a617c')}
                                value={data.credential}
                                onValueChange={(credential) =>
                                    form.setData('credential', credential)
                                }
                                options={[
                                    {
                                        value: 'access_key',
                                        label: consoleText('console.access_key_8663b0d4'),
                                        hint: consoleText(
                                            'console.an_iam_user_allowed_only_s3_putobject_on_the_bucket_its_5337441e',
                                        ),
                                    },
                                    {
                                        value: 'role',
                                        label: consoleText('console.assume_an_iam_role_86a49309'),
                                        hint: choices.assumedRoleAvailable
                                            ? consoleAttributeText(
                                                  'console.no_secret_is_stored_the_role_trusts_this_platform_with_4b76cd90',
                                              )
                                            : consoleAttributeText(
                                                  'console.not_set_up_on_this_platform_it_has_no_aws_identity_of_i_c076f7f2',
                                              ),
                                        disabled:
                                            !choices.assumedRoleAvailable &&
                                            data.credential !== 'role',
                                    },
                                ]}
                            />
                            {data.credential === 'role' ? (
                                <Field
                                    label={consoleT('console.iam_role_arn_acaac048')}
                                    error={errors['options.role_arn']}
                                >
                                    <Input
                                        {...text('role_arn')}
                                        className="mono"
                                        placeholder={tc(
                                            'console.arn_aws_iam_123456789012_role_cbox_audit_wri_28b44d3d',
                                        )}
                                    />
                                </Field>
                            ) : (
                                <Field
                                    label={consoleT('console.access_key_id_14eb0f96')}
                                    error={errors['options.access_key_id']}
                                >
                                    <Input
                                        {...text('access_key_id')}
                                        className="mono"
                                        autoComplete="off"
                                        placeholder={consoleUiText('console.akia_b3941023')}
                                    />
                                </Field>
                            )}
                        </>
                    )}

                    {(cloud
                        ? !(data.destination === 's3' && data.credential === 'role')
                        : data.scheme !== 'none') && (
                        <Field
                            label={secretLabel(data.destination, data.scheme, tc)}
                            optional={generatesKey || hasSecret}
                            hint={
                                generatesKey
                                    ? consoleText(
                                          'console.left_empty_a_signing_key_is_generated_for_you_and_shown_7cc3e0ba',
                                      )
                                    : data.destination === 'gcs'
                                      ? consoleFinalText(
                                            'console.paste_the_whole_json_key_file_of_a_service_account_gran_6f4e93cd',
                                            { part1: keepHint },
                                        )
                                      : data.destination === 'datadog'
                                        ? consoleFinalText(
                                              'console.an_api_key_organization_settings_api_keys_not_an_applic_916c023d',
                                              { part1: keepHint },
                                          )
                                        : consoleFinalText(
                                              'console.stored_encrypted_and_never_shown_again_part1_2bdbba2d',
                                              { part1: keepHint },
                                          )
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
                                        hasSecret
                                            ? '••••••••'
                                            : tc('console.type_service_account_f31802f4')
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
                            <Field
                                label={consoleT('console.server_side_encryption_88986133')}
                                error={errors['options.sse']}
                            >
                                <Select
                                    name="options.sse"
                                    value={data.options.sse === '' ? 'default' : data.options.sse}
                                    onValueChange={(sse) =>
                                        option('sse', sse === 'default' ? '' : sse)
                                    }
                                    options={[
                                        {
                                            value: 'default',
                                            label: consoleText(
                                                'console.the_bucket_s_default_9720401e',
                                            ),
                                        },
                                        {
                                            value: 'AES256',
                                            label: consoleText('console.sse_s3_aes256_d64ae3ac'),
                                        },
                                        {
                                            value: 'aws:kms',
                                            label: consoleText('console.sse_kms_aws_kms_03857bf3'),
                                        },
                                    ]}
                                />
                            </Field>
                            {data.options.sse === 'aws:kms' && (
                                <Field
                                    label={consoleT('console.aws_kms_key_94d3792b')}
                                    optional
                                    hint={consoleT(
                                        'console.key_id_alias_or_arn_empty_uses_the_aws_managed_key_6cf3c54f',
                                    )}
                                    error={errors['options.kms_key_id']}
                                >
                                    <Input
                                        {...text('kms_key_id')}
                                        className="mono"
                                        placeholder={tc('console.alias_audit_2f50242f')}
                                    />
                                </Field>
                            )}
                        </div>
                    )}
                </div>
            </Panel>

            {cloud && data.destination !== 'datadog' && (
                <Panel
                    title={consoleT('console.custom_endpoint_5fb0795e')}
                    description={
                        data.destination === 's3'
                            ? consoleText(
                                  'console.only_for_an_s3_compatible_store_such_as_minio_or_cloudf_266ca7ae',
                              )
                            : consoleText(
                                  'console.empty_uses_https_storage_googleapis_com_a6ee9d11',
                              )
                    }
                >
                    <div className="space-y-4">
                        <Field
                            label={consoleT('console.endpoint_url_2578179d')}
                            optional
                            error={errors.endpointUrl}
                        >
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
                                label={consoleT('console.force_path_style_addressing_380b7ab2')}
                                hint={consoleT(
                                    'console.the_bucket_in_the_path_rather_than_the_host_name_a_cust_070b78d0',
                                )}
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
