import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { relativeTime } from '@/lib/time';
import type { HelpContent, PageProps } from '@/types';
import {
    Badge,
    Button,
    CopyButton,
    EmptyState,
    Field,
    Icon,
    Input,
    PageHeader,
    AccessModelGuide,
} from '@/ui';

interface RelationRow {
    name: string;
    /** As it reads in the schema language: `[user, group#member] or editor`. */
    definition: string;
}

interface TypeRow {
    name: string;
    relations: RelationRow[];
}

interface CheckState {
    resource: string;
    relation: string;
    subject: string;
    consistencyToken: string;
    asked: boolean;
    allowed: boolean | null;
    error: string | null;
    /** The consistency token of the revision the answer was decided at. */
    decidedAt: string | null;
}

type Props = PageProps<{
    help: HelpContent;
    schema: {
        defined: boolean;
        version: number;
        updatedAt: string | null;
        consistencyToken: string;
        types: TypeRow[];
    };
    /** Stored tuples, in the notation: `document:readme#viewer@user:alice`. */
    tuples: string[];
    filters: { resource: string; subject: string };
    nextHref: string | null;
    firstHref: string | null;
    check: CheckState;
    checkHref: string;
    schemaHref: string;
    storeTupleHref: string;
    destroyTupleHref: string;
}>;

/**
 * The environment's relationship model at a glance: the schema's types and relations, the
 * tuples stored, a form to write or delete one, and a playground that asks a check exactly
 * as the API would.
 */
export default function FineGrainedAuthorization({
    help,
    schema,
    tuples,
    filters,
    nextHref,
    firstHref,
    check,
    checkHref,
    schemaHref,
    storeTupleHref,
    destroyTupleHref,
}: Props) {
    return (
        <>
            <PageHeader
                help={help}
                description="Your app's own access model: resource types, the relations on each, and how they inherit. Your app writes tuples — who relates to what — and asks checks on every request."
                badge={schema.defined ? <Badge>v{schema.version}</Badge> : undefined}
                actions={
                    <Button asChild variant="primary">
                        <Link href={schemaHref}>
                            <Icon name="pencil" className="w-4 h-4" />
                            {schema.defined ? 'Edit schema' : 'Define a schema'}
                        </Link>
                    </Button>
                }
            />

            {!schema.defined ? (
                <div className="mt-6 rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                    <EmptyState
                        icon="layers"
                        equivalent="fga.schema.update"
                        title="No schema yet"
                        description="Start from the documents-in-folders example: owners, editors and viewers, with a folder's access inherited by everything in it. Tuples and checks need a schema first."
                    />
                </div>
            ) : (
                <>
                    <SchemaSummary schema={schema} />
                    <Playground check={check} checkHref={checkHref} />
                    <Tuples
                        tuples={tuples}
                        filters={filters}
                        nextHref={nextHref}
                        firstHref={firstHref}
                        checkHref={checkHref}
                        storeTupleHref={storeTupleHref}
                        destroyTupleHref={destroyTupleHref}
                    />
                </>
            )}
            <div className="mt-8">
                <AccessModelGuide current="fga" />
            </div>
        </>
    );
}

function SchemaSummary({ schema }: { schema: Props['schema'] }) {
    return (
        <section className="mt-6">
            <div className="flex items-baseline gap-3">
                <h2 className="text-sm font-semibold">Schema</h2>
                {schema.updatedAt !== null && (
                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                        Saved {relativeTime(schema.updatedAt)} · revision{' '}
                        <span className="mono">{schema.consistencyToken}</span>
                    </span>
                )}
            </div>
            <div className="mt-3 grid gap-3 md:grid-cols-2">
                {schema.types.map((type) => (
                    <div key={type.name} className="card p-4">
                        <p className="mono text-sm font-semibold">{type.name}</p>
                        {type.relations.length === 0 ? (
                            <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                                No relations — only ever a subject.
                            </p>
                        ) : (
                            <dl className="mt-2 grid gap-1">
                                {type.relations.map((relation) => (
                                    <div
                                        key={relation.name}
                                        className="flex flex-wrap gap-2 text-xs"
                                    >
                                        <dt className="mono font-medium">{relation.name}</dt>
                                        <dd className="mono" style={{ color: 'var(--muted)' }}>
                                            {relation.definition}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                    </div>
                ))}
            </div>
        </section>
    );
}

function Playground({ check, checkHref }: { check: CheckState; checkHref: string }) {
    const [resource, setResource] = useState(check.resource);
    const [relation, setRelation] = useState(check.relation);
    const [subject, setSubject] = useState(check.subject);
    const [token, setToken] = useState(check.consistencyToken);

    return (
        <section className="mt-10 card p-5">
            <h2 className="text-sm font-semibold">Check</h2>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                Asked exactly as your app asks it (
                <span className="mono">GET /api/v1/fga/check</span>), through every inheritance the
                schema defines.
            </p>
            <form
                className="mt-4 grid gap-3 md:grid-cols-[1fr_10rem_1fr_auto] md:items-end"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get(
                        checkHref,
                        {
                            check_resource: resource,
                            check_relation: relation,
                            check_subject: subject,
                            ...(token !== '' ? { check_token: token } : {}),
                        },
                        { preserveScroll: true, preserveState: true },
                    );
                }}
            >
                <Field label="Resource" hint="type:id">
                    <Input
                        className="mono"
                        value={resource}
                        placeholder="document:readme"
                        onChange={(event) => setResource(event.target.value)}
                    />
                </Field>
                <Field label="Relation">
                    <Input
                        className="mono"
                        value={relation}
                        placeholder="viewer"
                        onChange={(event) => setRelation(event.target.value)}
                    />
                </Field>
                <Field label="Subject" hint="type:id, or type:id#relation for a group">
                    <Input
                        className="mono"
                        value={subject}
                        placeholder="user:alice"
                        onChange={(event) => setSubject(event.target.value)}
                    />
                </Field>
                <Button type="submit" variant="primary">
                    Check
                </Button>
                <Field
                    label="At least as fresh as"
                    optional
                    hint="A consistency token a write returned."
                >
                    <Input
                        className="mono"
                        value={token}
                        placeholder="42.9f3c1a7be2d0"
                        onChange={(event) => setToken(event.target.value)}
                    />
                </Field>
            </form>
            {check.asked && (
                <output className="mt-4 block">
                    {check.error !== null ? (
                        <p className="field-error">{check.error}</p>
                    ) : (
                        <p className="flex flex-wrap items-center gap-2 text-sm">
                            <Badge tone={check.allowed === true ? 'success' : 'danger'}>
                                {check.allowed === true ? 'Allowed' : 'Denied'}
                            </Badge>
                            <span className="mono">
                                {check.subject} {check.allowed === true ? 'has' : 'does not have'}{' '}
                                {check.relation} on {check.resource}
                            </span>
                            {check.decidedAt !== null && (
                                <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                    at revision <span className="mono">{check.decidedAt}</span>
                                </span>
                            )}
                        </p>
                    )}
                </output>
            )}
        </section>
    );
}

function Tuples({
    tuples,
    filters,
    nextHref,
    firstHref,
    checkHref,
    storeTupleHref,
    destroyTupleHref,
}: Pick<
    Props,
    | 'tuples'
    | 'filters'
    | 'nextHref'
    | 'firstHref'
    | 'checkHref'
    | 'storeTupleHref'
    | 'destroyTupleHref'
>) {
    const form = useForm({ tuple: '' });
    const [resource, setResource] = useState(filters.resource);
    const [subject, setSubject] = useState(filters.subject);

    return (
        <section className="mt-10">
            <h2 className="text-sm font-semibold">Tuples</h2>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                The facts your app wrote. What they add up to is what a check answers.
            </p>

            <form
                className="mt-4 grid gap-3 md:grid-cols-[1fr_auto] md:items-end"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeTupleHref, {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <Field
                    label="Write a tuple"
                    error={form.errors.tuple}
                    hint="resource#relation@subject — e.g. document:readme#viewer@group:eng#member"
                >
                    <Input
                        className="mono"
                        value={form.data.tuple}
                        placeholder="document:readme#viewer@user:alice"
                        onChange={(event) => form.setData('tuple', event.target.value)}
                    />
                </Field>
                <Button type="submit" loading={form.processing} disabled={form.data.tuple === ''}>
                    <Icon name="plus" className="w-4 h-4" />
                    Write
                </Button>
            </form>

            <form
                className="mt-6 flex flex-wrap items-end gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    router.get(
                        checkHref,
                        { resource, subject },
                        { preserveScroll: true, preserveState: true },
                    );
                }}
            >
                <Field label="Resource" hint="type or type:id">
                    <Input
                        className="mono"
                        value={resource}
                        placeholder="document:readme"
                        onChange={(event) => setResource(event.target.value)}
                    />
                </Field>
                <Field label="Subject" hint="type, type:id or type:id#relation">
                    <Input
                        className="mono"
                        value={subject}
                        placeholder="user:alice"
                        onChange={(event) => setSubject(event.target.value)}
                    />
                </Field>
                <Button type="submit">Filter</Button>
            </form>

            {tuples.length === 0 ? (
                <div className="mt-4 rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                    <EmptyState
                        icon="shield"
                        equivalent="fga.tuples.write"
                        title={
                            filters.resource !== '' || filters.subject !== ''
                                ? 'No tuples match'
                                : 'No tuples yet'
                        }
                        description="Write one above, or from your app's backend in batches of up to 100."
                    />
                </div>
            ) : (
                <ul className="mt-4 rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                    {tuples.map((tuple, index) => (
                        <li
                            key={tuple}
                            className="flex items-center gap-2 px-4 py-2"
                            style={
                                index < tuples.length - 1
                                    ? { borderBottom: '1px solid var(--border)' }
                                    : undefined
                            }
                        >
                            <span className="mono text-sm break-all">{tuple}</span>
                            <span className="ml-auto flex items-center gap-1">
                                <CopyButton value={tuple} />
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={`Delete ${tuple}`}
                                    onClick={() =>
                                        router.delete(destroyTupleHref, {
                                            data: { tuple },
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    <Icon name="trash" className="w-4 h-4" />
                                </Button>
                            </span>
                        </li>
                    ))}
                </ul>
            )}

            {(firstHref !== null || nextHref !== null) && (
                <div className="mt-3 flex gap-2">
                    {firstHref !== null && (
                        <Button asChild size="sm">
                            <Link href={firstHref} preserveScroll>
                                First page
                            </Link>
                        </Button>
                    )}
                    {nextHref !== null && (
                        <Button asChild size="sm">
                            <Link href={nextHref} preserveScroll>
                                Next page
                            </Link>
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}

FineGrainedAuthorization.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
