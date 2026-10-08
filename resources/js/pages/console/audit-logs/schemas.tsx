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
    return (
        <>
            <PageHeader
                help={help}
                description="A schema says what an action's events must look like — which target types they name and what their metadata holds. Events of an action with a schema are checked when they arrive; without one they are accepted as sent, unless strict mode is on."
                actions={
                    <div className="flex items-center gap-2">
                        <Button asChild>
                            <Link href={eventsHref}>
                                <Icon name="arrow-left" className="w-4 h-4" />
                                Events
                            </Link>
                        </Button>
                        <Button asChild variant="primary">
                            <Link href={createHref}>
                                <Icon name="plus" className="w-4 h-4" />
                                New schema
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
                            title="No schemas yet"
                            description="Every action is accepted as your app sends it. Add a schema for an action to have its events checked on arrival — a consistent log is decided when an event is written, not when somebody reads it."
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
                                    <Badge>v{schema.version}</Badge>
                                    {schema.targets.map((target) => (
                                        <Badge key={target} tone="info">
                                            {target}
                                        </Badge>
                                    ))}
                                    <span
                                        className="ml-auto text-xs"
                                        style={{ color: 'var(--faint)' }}
                                    >
                                        Updated {relativeTime(schema.updatedAt)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {unschematized.length > 0 && (
                <section className="mt-8">
                    <h2 className="text-sm font-semibold">Recent actions without a schema</h2>
                    <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                        Seen among the newest events. Each is accepted as sent until it has one.
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
    const form = useForm({
        retentionDays: settings.retentionDays,
        strictSchemas: settings.strictSchemas,
    });
    const shortening = form.data.retentionDays < settings.retentionDays;

    return (
        <section className="mt-10 card p-5">
            <h2 className="text-sm font-semibold">Retention and strict mode</h2>
            <form
                className="mt-4 grid gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(settings.updateHref, { preserveScroll: true });
                }}
            >
                <Field
                    label="Keep events for (days)"
                    error={form.errors.retentionDays}
                    hint={
                        shortening
                            ? 'Shorter than today: events older than this are deleted for good at the next daily prune, for every organization.'
                            : 'Counted from when each event arrived. 1 to 3650.'
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
                    label="Strict mode"
                    hint="Refuse events whose action has no schema. Turn on once every action your app sends has one."
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
                        Save settings
                    </Button>
                </div>
            </form>
        </section>
    );
}

AuditLogSchemas.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
