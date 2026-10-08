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
                title={schema === null ? 'New audit log schema' : schema.action}
                badge={schema !== null ? <Badge>v{schema.version}</Badge> : undefined}
                description="Metadata schemas are a subset of JSON Schema: an object whose properties are strings, numbers, integers, booleans or null, with enum, length, range, pattern and format. Saving a change makes a new version; events already recorded keep the one they were checked against."
                actions={
                    <Button asChild>
                        <Link href={indexHref}>
                            <Icon name="arrow-left" className="w-4 h-4" />
                            Schemas
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
                        label="Action"
                        error={form.errors.action}
                        hint="Dotted words, as your app sends it."
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
                    label="Event metadata schema"
                    optional
                    error={form.errors.metadata}
                    hint="Leave empty to accept any metadata."
                >
                    <Textarea
                        className="mono text-xs"
                        rows={9}
                        placeholder={METADATA_EXAMPLE}
                        value={form.data.metadata}
                        onChange={(event) => form.setData('metadata', event.target.value)}
                    />
                </Field>
                <Field label="Actor metadata schema" optional error={form.errors.actorMetadata}>
                    <Textarea
                        className="mono text-xs"
                        rows={5}
                        value={form.data.actorMetadata}
                        onChange={(event) => form.setData('actorMetadata', event.target.value)}
                    />
                </Field>
                <Field
                    label="Target types"
                    optional
                    error={form.errors.targets}
                    hint="A list of the target types this action may name, each with an optional metadata schema. Leave empty to allow any."
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
                        {schema === null ? 'Create schema' : 'Save new version'}
                    </Button>
                    {schema !== null && (
                        <Button type="button" variant="ghost" onClick={() => setDeleting(true)}>
                            <Icon name="trash" className="w-4 h-4" />
                            Delete schema
                        </Button>
                    )}
                </div>
            </form>

            <section className="mt-10">
                <h2 className="text-sm font-semibold">Recent events of this action</h2>
                {recent.length === 0 ? (
                    <p className="mt-2 text-sm" style={{ color: 'var(--muted)' }}>
                        None received yet.
                    </p>
                ) : (
                    <div className="mt-3">
                        <AuditEventList
                            events={recent}
                            labels={{
                                caption: 'Recent events of this action',
                                actor: 'Actor',
                                targets: 'Target',
                                location: 'Location',
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
                    title={`Delete the schema for ${schema.action}?`}
                    consequence="Its events are accepted unchecked from now on — or refused, if strict mode is on."
                    onConfirm={() => router.delete(schema.destroyHref)}
                />
            )}
        </>
    );
}

AuditLogSchemaEditor.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
