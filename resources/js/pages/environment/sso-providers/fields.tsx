import { useTranslator } from '@/i18n';
import type { InertiaFormProps } from '@inertiajs/react';
import {
    Checkbox,
    Combobox,
    type ComboboxOption,
    Field,
    Input,
    type MetadataRow,
    MetadataRows,
    Panel,
    Select,
    Textarea,
} from '@/ui';

export interface ServiceProviderForm {
    entityId: string;
    acsUrl: string;
    nameIdFormat: string;
    nameIdAttribute: string;
    attributeMappings: MetadataRow[];
    wantAuthnRequestsSigned: boolean;
    certificate: string;
    /** The owning organization's id, or '' for an environment-wide application. */
    organizationId: string;
}

export type OrganizationOption = ComboboxOption<string>;

/**
 * THE SHAPE OF ONE SAML TRUST, asked once.
 *
 * The register form and the edit form ask for exactly the same seven things, and the two
 * blades that preceded this had drifted: they parsed the attribute map with the same
 * fifteen lines copied twice, and only one of them told you a certificate was already on
 * file. One component is what stops the next difference between them being a semantic one.
 */
export function ServiceProviderFields({
    form,
    formats,
    organizations,
    hasCertificate,
}: {
    form: InertiaFormProps<ServiceProviderForm>;
    formats: { value: string; label: string }[];
    organizations: OrganizationOption[];
    hasCertificate: boolean;
}) {
    const { t: tc } = useTranslator();

    return (
        <>
            {/*
                WHOSE APPLICATION THIS IS, asked first. An environment-wide application lets
                every person with an account here sign in to it — every customer's people,
                not only the one whose app it is — so the empty choice says so in words
                rather than passing for "not set".
            */}
            <Panel
                title={tc('console.who_can_sign_in_ab90f610')}
                description={tc('console.only_the_active_members_of_the_organization__a4a82dec')}
            >
                <div className="space-y-3">
                    <Field
                        label={tc('console.for_which_organization_aaca2bbd')}
                        error={form.errors.organizationId}
                    >
                        <Combobox
                            aria-label={tc('console.owning_organization_39ad6d9c')}
                            value={form.data.organizationId}
                            onValueChange={(organization) =>
                                form.setData('organizationId', organization)
                            }
                            options={organizations}
                            placeholder={tc('console.find_an_organization_eb2523db')}
                            searchPlaceholder={tc('console.search_organizations_0156eb39')}
                            emptyMessage={tc('console.no_organization_matches_that_a20012de')}
                        />
                    </Field>

                    {form.data.organizationId === '' && (
                        <output
                            className="block text-sm"
                            style={{ color: 'var(--warning-strong)' }}
                        >
                            {tc('console.environment_wide_anyone_with_an_account_in_t_c60d926c')}
                        </output>
                    )}
                </div>
            </Panel>

            <Panel title={tc('console.configuration_b332c349')}>
                <div className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label={tc('console.sp_entity_id_4ff9267b')}
                            hint={tc(
                                'console.the_identifier_the_application_calls_itself__afe05ddf',
                            )}
                            error={form.errors.entityId}
                        >
                            <Input
                                name="entityId"
                                className="mono"
                                placeholder="https://saml.salesforce.com"
                                value={form.data.entityId}
                                onChange={(event) => form.setData('entityId', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={tc('console.assertion_consumer_service_url_5dacfb65')}
                            hint={tc(
                                'console.where_the_signed_assertion_is_posted_back_mu_2dc6e396',
                            )}
                            error={form.errors.acsUrl}
                        >
                            <Input
                                name="acsUrl"
                                type="url"
                                className="mono"
                                placeholder="https://login.salesforce.com/?saml=..."
                                value={form.data.acsUrl}
                                onChange={(event) => form.setData('acsUrl', event.target.value)}
                            />
                        </Field>

                        <Field
                            label={tc('console.nameid_format_1f9977db')}
                            error={form.errors.nameIdFormat}
                        >
                            <Select
                                value={form.data.nameIdFormat}
                                onValueChange={(format) => form.setData('nameIdFormat', format)}
                                options={formats}
                            />
                        </Field>

                        <Field
                            label={tc('console.nameid_attribute_be652806')}
                            hint={tc(
                                'console.the_field_on_the_person_that_becomes_the_ass_cc8b8aae',
                            )}
                            error={form.errors.nameIdAttribute}
                        >
                            <Input
                                name="nameIdAttribute"
                                className="mono"
                                placeholder={tc('console.email_82244417')}
                                value={form.data.nameIdAttribute}
                                onChange={(event) =>
                                    form.setData('nameIdAttribute', event.target.value)
                                }
                            />
                        </Field>
                    </div>

                    {/*
                        ROWS, not a textarea of `name = field` lines. The parse behind that
                        textarea dropped any line without an `=` in silence, so a typo in an
                        attribute name looked exactly like a mapping nobody had typed — and
                        the assertion went out missing a claim the application was waiting
                        for.
                    */}
                    <MetadataRows
                        label={tc('console.attribute_mappings_8de25d26')}
                        hint={tc('console.each_claim_the_assertion_carries_and_the_fie_3bbc28c0')}
                        keyLabel={tc('console.saml_attribute_0feaa60e')}
                        valueLabel={tc('console.user_field_f8d3ac04')}
                        addLabel={tc('console.add_attribute_dd77952c')}
                        rows={form.data.attributeMappings}
                        onChange={(rows) => form.setData('attributeMappings', rows)}
                    />
                </div>
            </Panel>

            <Panel
                title={tc('console.signed_requests_b6cc80d3')}
                description={tc('console.verify_that_authnrequests_really_come_from_t_f3c58c70')}
            >
                <div className="space-y-4">
                    <Checkbox
                        checked={form.data.wantAuthnRequestsSigned}
                        onCheckedChange={(checked) =>
                            form.setData('wantAuthnRequestsSigned', checked)
                        }
                        label={tc('console.require_signed_authnrequests_b4dc0e7e')}
                        hint={tc('console.a_certificate_is_required_without_one_there__4c7c5647')}
                    />

                    {form.data.wantAuthnRequestsSigned && (
                        <Field
                            label={tc('console.sp_signing_certificate_45095350')}
                            hint={
                                hasCertificate
                                    ? tc(
                                          'console.a_certificate_is_on_file_paste_a_new_one_to__d7521100',
                                      )
                                    : tc(
                                          'console.the_application_s_public_certificate_in_pem_d0cce440',
                                      )
                            }
                            optional={hasCertificate}
                            error={form.errors.certificate}
                        >
                            <Textarea
                                name="certificate"
                                rows={4}
                                className="mono"
                                style={{ fontSize: '0.78rem' }}
                                placeholder="-----BEGIN CERTIFICATE-----"
                                value={form.data.certificate}
                                onChange={(event) =>
                                    form.setData('certificate', event.target.value)
                                }
                            />
                        </Field>
                    )}
                </div>
            </Panel>
        </>
    );
}
