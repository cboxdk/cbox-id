import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Checkbox, Field, Icon, Input, Panel } from '@/ui';

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
}>;

export default function CreateWebhook({
    events,
    indexHref,
    storeHref,
    mayScopeEnvironmentWide,
}: Props) {
    const form = useForm({
        url: '',
        eventTypes: [] as string[],
        environmentWide: false,
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
            <Link
                href={indexHref}
                className="text-sm inline-flex items-center gap-1"
                style={{ color: 'var(--muted-foreground)' }}
            >
                <Icon name="chevron" className="w-3.5 h-3.5" style={{ transform: 'rotate(90deg)' }} />
                Webhooks
            </Link>

            <h1 className="mt-2 cbx-page-title">New webhook</h1>
            <p className="cbx-page-desc">
                The signing secret is shown once, right after you create the endpoint.
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
                        <Field label="Endpoint URL" error={form.errors.url} required>
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
                                label="Environment-wide"
                                hint="Send this endpoint every organization's events in this environment, not just those of the one selected in the bar above."
                            />
                        )}

                        <fieldset style={{ border: 0, padding: 0, margin: 0 }}>
                            {/*
                                A real fieldset and legend. The checkbox grid is one
                                question — "what should this endpoint hear about?" — and
                                without it a screen reader announces twenty-four unrelated
                                checkboxes with no idea what they belong to.
                            */}
                            <legend className="label">Event types</legend>

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

                            <p className="field-error" role="alert" hidden={!form.errors.eventTypes}>
                                {form.errors.eventTypes}
                            </p>
                        </fieldset>

                        <div>
                            <Button type="submit" variant="primary" loading={form.processing}>
                                Create endpoint
                            </Button>
                        </div>
                    </div>
                </Panel>
            </form>
        </>
    );
}

CreateWebhook.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
