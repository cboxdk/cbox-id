import { useTranslator } from '@/i18n';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { OrganizationPicker, PageProps } from '@/types';
import {
    Breadcrumb,
    Button,
    Checkbox,
    EmptyState,
    Field,
    Input,
    OrganizationPickerField,
    Panel,
    RadioGroup,
    Textarea,
} from '@/ui';

type Props = PageProps<{
    /** "For which organization?" — the environment console only; null where the form is about one already. */
    organization: OrganizationPicker | null;
    entitled: boolean;
    mayScopeEnvironmentWide: boolean;
    indexHref: string;
    storeHref: string;
    importHref: string;
}>;

export default function CreateConnection({
    organization,
    entitled,
    mayScopeEnvironmentWide,
    indexHref,
    storeHref,
    importHref,
}: Props) {
    const { t: tc } = useTranslator();

    // The importer's answer, on the flash channel: it is the result of one action and it
    // fills the form rather than describing the page.
    const imported = usePage().flash.metadata;

    const form = useForm({
        name: '',
        type: 'saml',
        environmentWide: false,
        organization: organization?.selected?.id ?? '',
        idp_entity_id: '',
        idp_sso_url: '',
        idp_x509cert: '',
        sp_entity_id: '',
        sp_acs_url: '',
        issuer: '',
        client_id: '',
        client_secret: '',
        signing_key: '',
    });

    const metadata = useForm({ metadata: '' });

    // The import fills the SAML half and switches to it: a person who pasted an IdP's
    // metadata has answered "which kind" more clearly than the radio could.
    useEffect(() => {
        if (imported === undefined) {
            return;
        }

        form.setData((current) => ({
            ...current,
            type: 'saml',
            idp_entity_id: imported.idp_entity_id,
            idp_sso_url: imported.idp_sso_url,
            idp_x509cert: imported.idp_x509cert,
        }));
        // The flash is one-shot, so this runs once per import and never again.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [imported]);

    const saml = form.data.type === 'saml';

    return (
        <>
            <Breadcrumb href={indexHref} label={tc('console.enterprise_sso_0f8d7e8e')} />

            <h1 className="cbx-page-title mt-2">{tc('console.new_connection_729bceee')}</h1>
            <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {tc('console.point_cbox_id_at_the_identity_provider_your__f4f844a5')}
            </p>

            {!entitled ? (
                <div className="card mt-6">
                    <EmptyState
                        icon="connections"
                        title={tc('console.single_sign_on_is_an_enterprise_feature_26381303')}
                        description={tc(
                            'console.contact_your_account_team_to_enable_it_for_t_c4ba4d29',
                        )}
                    />
                </div>
            ) : (
                <form
                    className="mt-6 space-y-6"
                    style={{ maxWidth: '42rem' }}
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(storeHref);
                    }}
                >
                    <Panel>
                        {organization !== null && !form.data.environmentWide && (
                            <div className="mb-5">
                                <OrganizationPickerField
                                    picker={organization}
                                    error={form.errors.organization}
                                    onChange={(id) => form.setData('organization', id)}
                                    hint={tc(
                                        'console.whose_people_sign_in_through_it_its_domains__9c461a3e',
                                    )}
                                />
                            </div>
                        )}

                        <Field
                            label={tc('console.name_dcd1d522')}
                            hint={tc(
                                'console.what_your_team_will_see_on_the_sign_in_page_0d444954',
                            )}
                            error={form.errors.name}
                        >
                            <Input
                                name="name"
                                placeholder={tc('console.acme_entra_id_c166851c')}
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>

                        <div className="mt-5">
                            <RadioGroup
                                label={tc('console.protocol_cf088334')}
                                value={form.data.type}
                                onValueChange={(type) => form.setData('type', type)}
                                options={[
                                    {
                                        value: 'saml',
                                        label: 'SAML 2.0',
                                        hint: tc(
                                            'console.entra_id_okta_adfs_most_enterprise_idps_728a6133',
                                        ),
                                    },
                                    {
                                        value: 'oidc',
                                        label: tc('console.openid_connect_50a8a027'),
                                        hint: tc(
                                            'console.google_workspace_auth0_anything_oidc_native_adb52125',
                                        ),
                                    },
                                ]}
                            />
                        </div>

                        {mayScopeEnvironmentWide && !(organization?.locked ?? false) && (
                            <div className="mt-5">
                                <Checkbox
                                    checked={form.data.environmentWide}
                                    onCheckedChange={(checked) =>
                                        form.setData('environmentWide', checked)
                                    }
                                    label={tc(
                                        'console.owned_by_this_environment_rather_than_by_an__60be3a32',
                                    )}
                                    hint={tc(
                                        'console.for_an_environment_that_does_not_use_organiz_c25b56bc',
                                    )}
                                />
                            </div>
                        )}
                    </Panel>

                    {saml && (
                        <Panel
                            title={tc('console.import_from_metadata_a962413a')}
                            description={tc(
                                'console.paste_the_provider_s_metadata_xml_or_its_met_70d6c8b8',
                            )}
                        >
                            <Field
                                label={tc('console.metadata_9eddf573')}
                                error={metadata.errors.metadata}
                            >
                                <Textarea
                                    name="metadata"
                                    rows={3}
                                    className="mono"
                                    spellCheck={false}
                                    placeholder="https://login.microsoftonline.com/…/federationmetadata.xml"
                                    value={metadata.data.metadata}
                                    onChange={(event) =>
                                        metadata.setData('metadata', event.target.value)
                                    }
                                />
                            </Field>

                            <Button
                                type="button"
                                className="mt-3"
                                loading={metadata.processing}
                                onClick={() => metadata.post(importHref, { preserveScroll: true })}
                            >
                                {tc('console.import_2cff9baa')}
                            </Button>
                        </Panel>
                    )}

                    {saml ? (
                        <Panel title={tc('console.saml_configuration_e4e7d0f2')}>
                            <div className="space-y-4">
                                <Field
                                    label={tc('console.idp_entity_id_81723d4d')}
                                    error={form.errors.idp_entity_id}
                                >
                                    <Input
                                        name="idp_entity_id"
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.idp_entity_id}
                                        onChange={(event) =>
                                            form.setData('idp_entity_id', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label={tc('console.idp_sign_on_url_20143c91')}
                                    error={form.errors.idp_sso_url}
                                >
                                    <Input
                                        name="idp_sso_url"
                                        type="url"
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.idp_sso_url}
                                        onChange={(event) =>
                                            form.setData('idp_sso_url', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label={tc('console.idp_signing_certificate_c84000cd')}
                                    hint={tc(
                                        'console.the_x_509_certificate_pem_or_base64_06293319',
                                    )}
                                    error={form.errors.idp_x509cert}
                                >
                                    <Textarea
                                        name="idp_x509cert"
                                        rows={4}
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.idp_x509cert}
                                        onChange={(event) =>
                                            form.setData('idp_x509cert', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label={tc('console.service_provider_entity_id_ab95ad33')}
                                    error={form.errors.sp_entity_id}
                                >
                                    <Input
                                        name="sp_entity_id"
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.sp_entity_id}
                                        onChange={(event) =>
                                            form.setData('sp_entity_id', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label={tc('console.assertion_consumer_service_url_1810c53d')}
                                    hint={tc(
                                        'console.where_the_provider_posts_its_assertion_give__5b098f34',
                                    )}
                                    error={form.errors.sp_acs_url}
                                >
                                    <Input
                                        name="sp_acs_url"
                                        type="url"
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.sp_acs_url}
                                        onChange={(event) =>
                                            form.setData('sp_acs_url', event.target.value)
                                        }
                                    />
                                </Field>
                            </div>
                        </Panel>
                    ) : (
                        <Panel
                            title={tc('console.openid_connect_configuration_de16da78')}
                            description={tc(
                                'console.the_authorization_and_token_endpoints_are_di_f26237b1',
                            )}
                        >
                            <div className="space-y-4">
                                <Field
                                    label={tc('console.issuer_39e02c46')}
                                    error={form.errors.issuer}
                                >
                                    <Input
                                        name="issuer"
                                        type="url"
                                        className="mono"
                                        spellCheck={false}
                                        placeholder="https://accounts.google.com"
                                        value={form.data.issuer}
                                        onChange={(event) =>
                                            form.setData('issuer', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label={tc('console.client_id_8726db01')}
                                    error={form.errors.client_id}
                                >
                                    <Input
                                        name="client_id"
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.client_id}
                                        onChange={(event) =>
                                            form.setData('client_id', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label={tc('console.client_secret_4aded5fa')}
                                    error={form.errors.client_secret}
                                >
                                    <Input
                                        name="client_secret"
                                        type="password"
                                        className="mono"
                                        autoComplete="off"
                                        value={form.data.client_secret}
                                        onChange={(event) =>
                                            form.setData('client_secret', event.target.value)
                                        }
                                    />
                                </Field>

                                <Field
                                    label={tc('console.signing_key_49395b95')}
                                    optional
                                    hint={tc(
                                        'console.leave_it_empty_for_almost_every_provider_its_e39d64b9',
                                    )}
                                    error={form.errors.signing_key}
                                >
                                    <Textarea
                                        name="signing_key"
                                        rows={3}
                                        className="mono"
                                        spellCheck={false}
                                        value={form.data.signing_key}
                                        onChange={(event) =>
                                            form.setData('signing_key', event.target.value)
                                        }
                                    />
                                </Field>
                            </div>
                        </Panel>
                    )}

                    <div className="flex items-center gap-2">
                        <Button type="submit" variant="primary" loading={form.processing}>
                            {tc('console.create_connection_755acdc9')}
                        </Button>
                        <Button asChild>
                            <Link href={indexHref}>{tc('console.cancel_19766ed6')}</Link>
                        </Button>
                    </div>
                </form>
            )}
        </>
    );
}

CreateConnection.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
