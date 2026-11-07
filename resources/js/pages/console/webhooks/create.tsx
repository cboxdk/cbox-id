import { useTranslator as useConsoleTranslator } from '@/i18n';
import { useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { OrganizationPicker, PageProps } from '@/types';
import {
    Breadcrumb,
    Button,
    Checkbox,
    Field,
    Input,
    OrganizationPickerField,
    Panel,
    RadioGroup,
} from '@/ui';
import { type SignatureScheme, signatureSchemeOptions } from './schemes';

type Props = PageProps<{
    events: string[];
    indexHref: string;
    /** Where the form posts. Resolved by the server, which is the half that knows the plane. */
    storeHref: string;
    /**
     * Whether this administrator may register an endpoint the ENVIRONMENT owns, which
     * receives every organization's events. Offered on the environment plane only — and
     * the server refuses it from anywhere else regardless, because a control that is
     * merely unrendered is not a control that is enforced.
     */
    mayScopeEnvironmentWide: boolean;
    /** "For which organization?" — the environment console only; null where the form is about one already. */
    organization: OrganizationPicker | null;
}>;

export default function CreateWebhook({
    events,
    indexHref,
    storeHref,
    mayScopeEnvironmentWide,
    organization,
}: Props) {
    const { t: consoleOptionText } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        url: '',
        eventTypes: [] as string[],
        environmentWide: false,
        signatureScheme: 'cbox' as SignatureScheme,
        organization: organization?.selected?.id ?? '',
    });

    const toggle = (event: string, checked: boolean): void => {
        form.setData(
            'eventTypes',
            checked
                ? [...form.data.eventTypes, event]
                : form.data.eventTypes.filter((type) => type !== event),
        );
    };

    return (
        <>
            <Breadcrumb href={indexHref} label={consoleT('console.webhooks_45808d75')} />

            <h1 className="mt-2 cbx-page-title">{consoleT('console.new_webhook_d58b5d64')}</h1>
            <p className="cbx-page-desc">
                {consoleT(
                    'console.the_signing_secret_is_shown_once_right_after_you_create_aa1c7a87',
                )}
            </p>

            <form
                className="mt-6"
                style={{ maxWidth: '36rem' }}
                onSubmit={(submitted) => {
                    submitted.preventDefault();
                    form.post(storeHref);
                }}
            >
                <Panel>
                    <div style={{ display: 'grid', gap: '16px' }}>
                        <Field
                            label={consoleT('console.endpoint_url_2578179d')}
                            error={form.errors.url}
                            required
                        >
                            <Input
                                type="url"
                                className="mono"
                                placeholder="https://example.com/webhooks/cbox"
                                value={form.data.url}
                                onChange={(event) => form.setData('url', event.target.value)}
                            />
                        </Field>

                        {mayScopeEnvironmentWide && (
                            <Checkbox
                                checked={form.data.environmentWide}
                                onCheckedChange={(checked) =>
                                    form.setData('environmentWide', checked)
                                }
                                label={consoleT('console.environment_wide_cd1204b6')}
                                hint={consoleT(
                                    'console.send_this_endpoint_every_organization_s_events_in_this_ea696387',
                                )}
                            />
                        )}

                        {organization !== null && !form.data.environmentWide && (
                            <OrganizationPickerField
                                picker={organization}
                                error={form.errors.organization}
                                onChange={(id) => form.setData('organization', id)}
                                hint={consoleT(
                                    'console.whose_events_it_is_sent_or_tick_environment_wide_above_7ab3be5b',
                                )}
                            />
                        )}

                        <div>
                            <RadioGroup
                                label={consoleT('console.signature_scheme_c432c67a')}
                                name="signatureScheme"
                                value={form.data.signatureScheme}
                                onValueChange={(scheme) => form.setData('signatureScheme', scheme)}
                                options={signatureSchemeOptions(consoleOptionText)}
                            />
                            <p
                                className="field-error"
                                role="alert"
                                hidden={!form.errors.signatureScheme}
                            >
                                {form.errors.signatureScheme}
                            </p>
                        </div>

                        <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
                            {/*
                                A real fieldset and legend. The checkbox grid is one
                                question — "what should this endpoint hear about?" — and
                                without it a screen reader announces twenty-four unrelated
                                checkboxes with no idea what they belong to.
                            */}
                            <legend className="label">
                                {consoleT('console.event_types_8f338cc0')}
                            </legend>

                            <div
                                style={{
                                    display: 'grid',
                                    gap: '8px',
                                    gridTemplateColumns: 'repeat(auto-fill, minmax(17rem, 1fr))',
                                }}
                            >
                                {events.map((event) => (
                                    <Checkbox
                                        key={event}
                                        checked={form.data.eventTypes.includes(event)}
                                        onCheckedChange={(checked) => toggle(event, checked)}
                                        label={<span className="mono text-xs">{event}</span>}
                                    />
                                ))}
                            </div>

                            <p
                                className="field-error"
                                role="alert"
                                hidden={!form.errors.eventTypes}
                            >
                                {form.errors.eventTypes}
                            </p>
                        </fieldset>

                        <div>
                            <Button type="submit" variant="primary" loading={form.processing}>
                                {consoleT('console.create_endpoint_fbd43477')}
                            </Button>
                        </div>
                    </div>
                </Panel>
            </form>
        </>
    );
}

CreateWebhook.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
