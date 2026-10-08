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
    return (
        <>
            {/*
                WHOSE APPLICATION THIS IS, asked first. An environment-wide application lets
                every person with an account here sign in to it — every customer's people,
                not only the one whose app it is — so the empty choice says so in words
                rather than passing for "not set".
            */}
            <Panel
                title="Who can sign in"
                description="Only the active members of the organization you choose are signed in to this application. Everyone else is refused, and the refusal is in the audit log."
            >
                <div className="space-y-3">
                    <Field label="For which organization?" error={form.errors.organizationId}>
                        <Combobox
                            aria-label="Owning organization"
                            value={form.data.organizationId}
                            onValueChange={(organization) =>
                                form.setData('organizationId', organization)
                            }
                            options={organizations}
                            placeholder="Find an organization…"
                            searchPlaceholder="Search organizations…"
                            emptyMessage="No organization matches that."
                        />
                    </Field>

                    {form.data.organizationId === '' && (
                        <output
                            className="block text-sm"
                            style={{ color: 'var(--warning-strong)' }}
                        >
                            Environment-wide: anyone with an account in this environment, in any
                            organization, can sign in to this application. Name an organization
                            unless that is what you mean.
                        </output>
                    )}
                </div>
            </Panel>

            <Panel title="Configuration">
                <div className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="SP entity ID"
                            hint="The identifier the application calls itself in its AuthnRequests."
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
                            label="Assertion Consumer Service URL"
                            hint="Where the signed assertion is posted back. Must be https."
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

                        <Field label="NameID format" error={form.errors.nameIdFormat}>
                            <Select
                                value={form.data.nameIdFormat}
                                onValueChange={(format) => form.setData('nameIdFormat', format)}
                                options={formats}
                            />
                        </Field>

                        <Field
                            label="NameID attribute"
                            hint="The field on the person that becomes the assertion's subject."
                            error={form.errors.nameIdAttribute}
                        >
                            <Input
                                name="nameIdAttribute"
                                className="mono"
                                placeholder="email"
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
                        label="Attribute mappings"
                        hint="Each claim the assertion carries, and the field on the person it is read from."
                        keyLabel="SAML attribute"
                        valueLabel="User field"
                        addLabel="Add attribute"
                        rows={form.data.attributeMappings}
                        onChange={(rows) => form.setData('attributeMappings', rows)}
                    />
                </div>
            </Panel>

            <Panel
                title="Signed requests"
                description="Verify that AuthnRequests really come from this application, using its own signing certificate."
            >
                <div className="space-y-4">
                    <Checkbox
                        checked={form.data.wantAuthnRequestsSigned}
                        onCheckedChange={(checked) =>
                            form.setData('wantAuthnRequestsSigned', checked)
                        }
                        label="Require signed AuthnRequests"
                        hint="A certificate is required — without one there is nothing to verify against."
                    />

                    {form.data.wantAuthnRequestsSigned && (
                        <Field
                            label="SP signing certificate"
                            hint={
                                hasCertificate
                                    ? 'A certificate is on file. Paste a new one to replace it, or leave this blank to keep it.'
                                    : 'The application’s public certificate, in PEM.'
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
