import { useTranslator as useConsoleTranslator } from '@/i18n';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import {
    AuditEventList,
    type AuditEventRow,
    Badge,
    Button,
    ConfirmDelete,
    Field,
    Icon,
    Input,
    PageHeader,
    Textarea,
} from '@/ui';

interface ExistingSchema {
    action: string;
    version: number;
    /** Each box as pretty-printed JSON, '' for none. */
    metadata: string;
    actorMetadata: string;
    targets: string;
    updateHref: string;
    destroyHref: string;
}

type Props = PageProps<{
    schema: ExistingSchema | null;
    /** The action being edited, or the one the create form was opened for. */
    action: string;
    storeHref: string;
    indexHref: string;
    /** This action's newest events — what the schema will be checked against. */
    recent: AuditEventRow[];
}>;

const METADATA_EXAMPLE = `{
  "type": "object",
  "properties": {
    "total": { "type": "number", "minimum": 0 },
    "currency": { "type": "string", "enum": ["EUR", "DKK"] }
  },
  "required": ["currency"],
  "additionalProperties": false
}`;

const TARGETS_EXAMPLE = `[
  { "type": "invoice" },
  { "type": "account", "metadata": { "properties": { "tier": { "type": "string" } } } }
]`;

/**
 * Create or replace the schema one action's events are checked against, beside that
 * action's newest events — so the person writing it sees what the app actually sends.
 */
export default function AuditLogSchemaEditor({
    schema,
    action,
    storeHref,
    indexHref,
    recent,
}: Props) {
    const { t: consoleLastText } = useConsoleTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({
        action: schema?.action ?? action,
        metadata: schema?.metadata ?? '',
        actorMetadata: schema?.actorMetadata ?? '',
        targets: schema?.targets ?? '',
    });
    const [deleting, setDeleting] = useState(false);
    // A refusal about no one box (a duplicate schema, a bad action name) lands here.
    const formError = usePage().props.errors.form;

    const submit = (): void => {
        if (schema === null) {
            form.post(storeHref);
        } else {
            form.put(schema.updateHref, { preserveScroll: true });
        }
    };

    return (
        <>
            <PageHeader
                title={
                    schema === null
                        ? consoleText('console.new_audit_log_schema_615358bc')
                        : schema.action
                }
                badge={schema !== null ? <Badge>v{schema.version}</Badge> : undefined}
                description={consoleT(
                    'console.metadata_schemas_are_a_subset_of_json_schema_an_object_64eba270',
                )}
                actions={
                    <Button asChild>
                        <Link href={indexHref}>
                            {consoleRich('console._part1_schemas_c5c0c5c4', {
                                part1: <Icon name="arrow-left" className="w-4 h-4" />,
                            })}
                        </Link>
                    </Button>
                }
            />

            <form
                className="mt-6 grid gap-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                {typeof formError === 'string' && (
                    <p role="alert" className="field-error">
                        {formError}
                    </p>
                )}
                {schema === null && (
                    <Field
                        label={consoleT('console.action_64cff131')}
                        error={form.errors.action}
                        hint={consoleT('console.dotted_words_as_your_app_sends_it_cfc9781a')}
                    >
                        <Input
                            className="mono"
                            value={form.data.action}
                            placeholder="invoice.voided"
                            onChange={(event) => form.setData('action', event.target.value)}
                        />
                    </Field>
                )}
                <Field
                    label={consoleT('console.event_metadata_schema_a978eb8e')}
                    optional
                    error={form.errors.metadata}
                    hint={consoleT('console.leave_empty_to_accept_any_metadata_97c0eb7b')}
                >
                    <Textarea
                        className="mono text-xs"
                        rows={9}
                        placeholder={METADATA_EXAMPLE}
                        value={form.data.metadata}
                        onChange={(event) => form.setData('metadata', event.target.value)}
                    />
                </Field>
                <Field
                    label={consoleT('console.actor_metadata_schema_53ca9ca6')}
                    optional
                    error={form.errors.actorMetadata}
                >
                    <Textarea
                        className="mono text-xs"
                        rows={5}
                        value={form.data.actorMetadata}
                        onChange={(event) => form.setData('actorMetadata', event.target.value)}
                    />
                </Field>
                <Field
                    label={consoleT('console.target_types_eb291d0e')}
                    optional
                    error={form.errors.targets}
                    hint={consoleT(
                        'console.a_list_of_the_target_types_this_action_may_name_each_wi_26513896',
                    )}
                >
                    <Textarea
                        className="mono text-xs"
                        rows={6}
                        placeholder={TARGETS_EXAMPLE}
                        value={form.data.targets}
                        onChange={(event) => form.setData('targets', event.target.value)}
                    />
                </Field>
                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {schema === null
                            ? consoleText('console.create_schema_23cf9767')
                            : consoleText('console.save_new_version_20334a86')}
                    </Button>
                    {schema !== null && (
                        <Button type="button" variant="ghost" onClick={() => setDeleting(true)}>
                            {consoleRich('console._part1_delete_schema_7db72fc9', {
                                part1: <Icon name="trash" className="w-4 h-4" />,
                            })}
                        </Button>
                    )}
                </div>
            </form>

            <section className="mt-10">
                <h2 className="text-sm font-semibold">
                    {consoleT('console.recent_events_of_this_action_7846d4c1')}
                </h2>
                {recent.length === 0 ? (
                    <p className="mt-2 text-sm" style={{ color: 'var(--muted)' }}>
                        {consoleT('console.none_received_yet_4bb6befa')}
                    </p>
                ) : (
                    <div className="mt-3">
                        <AuditEventList
                            events={recent}
                            labels={{
                                caption: consoleLastText(
                                    'console.recent_events_of_this_action_7846d4c1',
                                ),
                                actor: consoleLastText('console.actor_449995c4'),
                                targets: consoleLastText('console.target_978354db'),
                                location: consoleLastText('console.location_15b61974'),
                            }}
                        />
                    </div>
                )}
            </section>

            {schema !== null && (
                <ConfirmDelete
                    open={deleting}
                    onOpenChange={setDeleting}
                    name={schema.action}
                    title={consoleFinalText('console.delete_the_schema_for_part1_5d4f21eb', {
                        part1: schema.action,
                    })}
                    consequence={consoleUiText(
                        'console.its_events_are_accepted_unchecked_from_now_on_or_refuse_af37acad',
                    )}
                    onConfirm={() => router.delete(schema.destroyHref)}
                />
            )}
        </>
    );
}

AuditLogSchemaEditor.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
