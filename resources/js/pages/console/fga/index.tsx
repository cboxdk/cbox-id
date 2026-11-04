import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

    return (
        <>
            <PageHeader
                help={help}
                description={tc('console.your_app_s_own_access_model_resource_types_t_3dddc87d')}
                badge={
                    schema.defined ? (
                        <Badge>
                            {tc('console.v_4c94485e')}
                            {schema.version}
                        </Badge>
                    ) : undefined
                }
                actions={
                    <Button asChild variant="primary">
                        <Link href={schemaHref}>
                            <Icon name="pencil" className="w-4 h-4" />
                            {schema.defined
                                ? tc('console.edit_schema_8acbc598')
                                : tc('console.define_a_schema_f9a49822')}
                        </Link>
                    </Button>
                }
            />

            {!schema.defined ? (
                <div className="mt-6 rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                    <EmptyState
                        icon="layers"
                        equivalent="fga.schema.update"
                        actions={
                            <Button asChild variant="primary">
                                <Link href={schemaHref}>
                                    <Icon name="pencil" className="w-4 h-4" />
                                    {tc('console.define_a_schema_f9a49822')}
                                </Link>
                            </Button>
                        }
                        title={tc('console.no_schema_yet_400a4cc2')}
                        description={tc(
                            'console.start_from_the_documents_in_folders_example__ca2a0af5',
                        )}
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
    const { locale } = useTranslator();

    const { t: tc } = useTranslator();

    return (
        <section className="mt-6">
            <div className="flex items-baseline gap-3">
                <h2 className="text-sm font-semibold">{tc('console.schema_07b091a3')}</h2>
                {schema.updatedAt !== null && (
                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                        {tc('console.saved_3fb96660')}
                        {relativeTime(schema.updatedAt, locale)}
                        {tc('console.revision_8ba705b8')}{' '}
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
                                {tc('console.no_relations_only_ever_a_subject_70ab685e')}
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
    const { t: tc } = useTranslator();

    const [resource, setResource] = useState(check.resource);
    const [relation, setRelation] = useState(check.relation);
    const [subject, setSubject] = useState(check.subject);
    const [token, setToken] = useState(check.consistencyToken);

    return (
        <section className="mt-10 card p-5">
            <h2 className="text-sm font-semibold">{tc('console.check_9d60841e')}</h2>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                {tc('console.asked_exactly_as_your_app_asks_it_d44c5ec2')}
                <span className="mono">{tc('console.get_api_v1_fga_check_267d7f52')}</span>
                {tc('console.through_every_inheritance_the_schema_defines_4ec872d5')}
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
                <Field
                    label={tc('console.resource_eb7a842f')}
                    hint={tc('console.type_id_09c21e99')}
                >
                    <Input
                        className="mono"
                        value={resource}
                        placeholder={tc('console.document_readme_29e90756')}
                        onChange={(event) => setResource(event.target.value)}
                    />
                </Field>
                <Field label={tc('console.relation_1367485d')}>
                    <Input
                        className="mono"
                        value={relation}
                        placeholder={tc('console.viewer_d35ca505')}
                        onChange={(event) => setRelation(event.target.value)}
                    />
                </Field>
                <Field
                    label={tc('console.subject_68971283')}
                    hint={tc('console.type_id_or_type_id_relation_for_a_group_363e3da0')}
                >
                    <Input
                        className="mono"
                        value={subject}
                        placeholder={tc('console.user_alice_dabd1db8')}
                        onChange={(event) => setSubject(event.target.value)}
                    />
                </Field>
                <Button type="submit" variant="primary">
                    {tc('console.check_9d60841e')}
                </Button>
                <Field
                    label={tc('console.at_least_as_fresh_as_bb83225d')}
                    optional
                    hint={tc('console.a_consistency_token_a_write_returned_c5751c5a')}
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
                                {check.allowed === true
                                    ? tc('console.allowed_1bb201d1')
                                    : tc('console.denied_da404deb')}
                            </Badge>
                            <span className="mono">
                                {check.subject}{' '}
                                {check.allowed === true
                                    ? tc('console.has_9150c74c')
                                    : tc('console.does_not_have_6f862855')}{' '}
                                {check.relation}
                                {tc('console.on_0f580db4')}
                                {check.resource}
                            </span>
                            {check.decidedAt !== null && (
                                <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                    {tc('console.at_revision_d2c4ee8c')}
                                    <span className="mono">{check.decidedAt}</span>
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
    const { t: tc } = useTranslator();

    const form = useForm({ tuple: '' });
    const [resource, setResource] = useState(filters.resource);
    const [subject, setSubject] = useState(filters.subject);

    return (
        <section className="mt-10">
            <h2 className="text-sm font-semibold">{tc('console.tuples_8e21e2d6')}</h2>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                {tc('console.the_facts_your_app_wrote_what_they_add_up_to_3240d92d')}
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
                    label={tc('console.write_a_tuple_0f0a6dca')}
                    error={form.errors.tuple}
                    hint={tc('console.resource_relation_subject_e_g_document_readm_3c478660')}
                >
                    <Input
                        className="mono"
                        value={form.data.tuple}
                        placeholder={tc('console.document_readme_viewer_user_alice_f9405db5')}
                        onChange={(event) => form.setData('tuple', event.target.value)}
                    />
                </Field>
                <Button type="submit" loading={form.processing} disabled={form.data.tuple === ''}>
                    <Icon name="plus" className="w-4 h-4" />
                    {tc('console.write_3f00927a')}
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
                <Field
                    label={tc('console.resource_eb7a842f')}
                    hint={tc('console.type_or_type_id_a1a0604f')}
                >
                    <Input
                        className="mono"
                        value={resource}
                        placeholder={tc('console.document_readme_29e90756')}
                        onChange={(event) => setResource(event.target.value)}
                    />
                </Field>
                <Field
                    label={tc('console.subject_68971283')}
                    hint={tc('console.type_type_id_or_type_id_relation_d5306ef0')}
                >
                    <Input
                        className="mono"
                        value={subject}
                        placeholder={tc('console.user_alice_dabd1db8')}
                        onChange={(event) => setSubject(event.target.value)}
                    />
                </Field>
                <Button type="submit">{tc('console.filter_638e249f')}</Button>
            </form>

            {tuples.length === 0 ? (
                <div className="mt-4 rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                    <EmptyState
                        icon="shield"
                        equivalent="fga.tuples.write"
                        title={
                            filters.resource !== '' || filters.subject !== ''
                                ? tc('console.no_tuples_match_fbd3e0b2')
                                : tc('console.no_tuples_yet_4dc745c4')
                        }
                        description={tc(
                            'console.write_one_above_or_from_your_app_s_backend_i_9ca507a7',
                        )}
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
                                    aria-label={tc('console.delete_value0_8388c5c2', {
                                        value0: tuple,
                                    })}
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
                                {tc('console.first_page_0bdbb750')}
                            </Link>
                        </Button>
                    )}
                    {nextHref !== null && (
                        <Button asChild size="sm">
                            <Link href={nextHref} preserveScroll>
                                {tc('console.next_page_c08ac736')}
                            </Link>
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}

FineGrainedAuthorization.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
