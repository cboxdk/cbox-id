import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { relativeTime } from '@/lib/time';
import type { HelpContent, PageProps } from '@/types';
import { Badge, Button, Checkbox, EmptyState, Field, Icon, Input, PageHeader } from '@/ui';

interface SchemaRow {
    action: string;
    version: number;
    targets: string[];
    updatedAt: string;
    href: string;
}

interface SeenAction {
    action: string;
    count: number;
    createHref: string;
}

type Props = PageProps<{
    help: HelpContent;
    schemas: SchemaRow[];
    /** Actions among the newest events that have no schema yet. */
    unschematized: SeenAction[];
    settings: { retentionDays: number; strictSchemas: boolean; updateHref: string };
    createHref: string;
    eventsHref: string;
}>;

/**
 * The environment's decisions about its audit logs: the schemas events of each action are
 * checked against, and how long events are kept.
 */
export default function AuditLogSchemas({
    schemas,
    unschematized,
    settings,
    createHref,
    eventsHref,
    help,
}: Props) {
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.a_schema_says_what_an_action_s_events_must_l_d27be344')}
                actions={
                    <div className="flex items-center gap-2">
                        <Button asChild>
                            <Link href={eventsHref}>
                                <Icon name="arrow-left" className="w-4 h-4" />
                                {tc('console.events_8d14f6e7')}
                            </Link>
                        </Button>
                        <Button asChild variant="primary">
                            <Link href={createHref}>
                                <Icon name="plus" className="w-4 h-4" />
                                {tc('console.new_schema_d5c1fa19')}
                            </Link>
                        </Button>
                    </div>
                }
            />

            <section className="mt-6">
                {schemas.length === 0 ? (
                    <div className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                        <EmptyState
                            icon="layers"
                            equivalent="audit_logs.schemas.create"
                            actions={
                                <Button asChild variant="primary">
                                    <Link href={createHref}>
                                        <Icon name="plus" className="w-4 h-4" />
                                        {tc('console.new_schema_d5c1fa19')}
                                    </Link>
                                </Button>
                            }
                            title={tc('console.no_schemas_yet_5d0ea9f3')}
                            description={tc(
                                'console.every_action_is_accepted_as_your_app_sends_i_fce48faa',
                            )}
                        />
                    </div>
                ) : (
                    <ul className="rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                        {schemas.map((schema, index) => (
                            <li
                                key={schema.action}
                                style={
                                    index < schemas.length - 1
                                        ? { borderBottom: '1px solid var(--border)' }
                                        : undefined
                                }
                            >
                                <Link
                                    href={schema.href}
                                    className="flex flex-wrap items-center gap-3 p-4 hover:bg-[var(--surface-2)]"
                                >
                                    <span className="mono text-sm font-medium">
                                        {schema.action}
                                    </span>
                                    <Badge>
                                        {tc('console.v_4c94485e')}
                                        {schema.version}
                                    </Badge>
                                    {schema.targets.map((target) => (
                                        <Badge key={target} tone="info">
                                            {target}
                                        </Badge>
                                    ))}
                                    <span
                                        className="ml-auto text-xs"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        {tc('console.updated_a919c7c2')}
                                        {relativeTime(schema.updatedAt, locale)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {unschematized.length > 0 && (
                <section className="mt-8">
                    <h2 className="text-sm font-semibold">
                        {tc('console.recent_actions_without_a_schema_fee7781b')}
                    </h2>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                        {tc('console.seen_among_the_newest_events_each_is_accepte_1b4df3ab')}
                    </p>
                    <ul className="mt-3 flex flex-wrap gap-2">
                        {unschematized.map((seen) => (
                            <li key={seen.action}>
                                <Button asChild size="sm">
                                    <Link href={seen.createHref}>
                                        <span className="mono">{seen.action}</span>
                                        <span style={{ color: 'var(--faint)' }}>
                                            × {seen.count}
                                        </span>
                                    </Link>
                                </Button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <SettingsForm settings={settings} />
        </>
    );
}

function SettingsForm({ settings }: { settings: Props['settings'] }) {
    const { t: tc } = useTranslator();

    const form = useForm({
        retentionDays: settings.retentionDays,
        strictSchemas: settings.strictSchemas,
    });
    const shortening = form.data.retentionDays < settings.retentionDays;

    return (
        <section className="mt-10 card p-5">
            <h2 className="text-sm font-semibold">
                {tc('console.retention_and_strict_mode_bd4d0e96')}
            </h2>
            <form
                className="mt-4 grid gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(settings.updateHref, { preserveScroll: true });
                }}
            >
                <Field
                    label={tc('console.keep_events_for_days_84ce0a6f')}
                    error={form.errors.retentionDays}
                    hint={
                        shortening
                            ? tc('console.shorter_than_today_events_older_than_this_ar_8cf235f8')
                            : tc('console.counted_from_when_each_event_arrived_1_to_36_d2725cda')
                    }
                >
                    <Input
                        type="number"
                        min={1}
                        max={3650}
                        style={{ maxWidth: '10rem' }}
                        value={form.data.retentionDays}
                        onChange={(event) =>
                            form.setData(
                                'retentionDays',
                                Number.parseInt(event.target.value, 10) || 0,
                            )
                        }
                    />
                </Field>
                <Checkbox
                    checked={form.data.strictSchemas}
                    onCheckedChange={(checked) => form.setData('strictSchemas', checked)}
                    label={tc('console.strict_mode_9f19fe13')}
                    hint={tc('console.refuse_events_whose_action_has_no_schema_tur_c5af42c9')}
                />
                {form.errors.strictSchemas !== undefined && (
                    <p role="alert" className="field-error">
                        {form.errors.strictSchemas}
                    </p>
                )}
                <div>
                    <Button
                        type="submit"
                        variant={shortening ? 'danger' : 'primary'}
                        loading={form.processing}
                        disabled={!form.isDirty}
                    >
                        {tc('console.save_settings_7f3a3b14')}
                    </Button>
                </div>
            </form>
        </section>
    );
}

AuditLogSchemas.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
