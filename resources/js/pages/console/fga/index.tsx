import { useTranslator as usePublicTranslator } from '@/i18n';
import { useTranslator as useFormatTranslator } from '@/i18n';
import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <>
            <PageHeader
                help={help}
                description={consoleT(
                    'console.your_app_s_own_access_model_resource_types_the_relation_3dddc87d',
                )}
                badge={schema.defined ? <Badge>v{schema.version}</Badge> : undefined}
                actions={
                    <Button asChild variant="primary">
                        <Link href={schemaHref}>
                            <Icon name="pencil" className="w-4 h-4" />
                            {schema.defined
                                ? consoleText('console.edit_schema_8acbc598')
                                : consoleText('console.define_a_schema_f9a49822')}
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
                                    {consoleRich('console._part1_define_a_schema_5c58bc70', {
                                        part1: <Icon name="pencil" className="w-4 h-4" />,
                                    })}
                                </Link>
                            </Button>
                        }
                        title={consoleT('console.no_schema_yet_400a4cc2')}
                        description={consoleT(
                            'console.start_from_the_documents_in_folders_example_owners_edit_ca2a0af5',
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
    const { locale: consoleLocale } = useFormatTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    return (
        <section className="mt-6">
            <div className="flex items-baseline gap-3">
                <h2 className="text-sm font-semibold">{consoleT('console.schema_07b091a3')}</h2>
                {schema.updatedAt !== null && (
                    <span className="text-xs" style={{ color: 'var(--faint)' }}>
                        {consoleRich('console.saved_part1_revision_part2_part3_1dfd713b', {
                            part1: relativeTime(schema.updatedAt, consoleLocale),
                            part2: ' ',
                            part3: <span className="mono">{schema.consistencyToken}</span>,
                        })}
                    </span>
                )}
            </div>
            <div className="mt-3 grid gap-3 md:grid-cols-2">
                {schema.types.map((type) => (
                    <div key={type.name} className="card p-4">
                        <p className="mono text-sm font-semibold">{type.name}</p>
                        {type.relations.length === 0 ? (
                            <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                                {consoleT('console.no_relations_only_ever_a_subject_70ab685e')}
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
    const { t: tc } = usePublicTranslator();
    const { t: consoleUiText } = useConsoleTranslator();
    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const [resource, setResource] = useState(check.resource);
    const [relation, setRelation] = useState(check.relation);
    const [subject, setSubject] = useState(check.subject);
    const [token, setToken] = useState(check.consistencyToken);

    return (
        <section className="mt-10 card p-5">
            <h2 className="text-sm font-semibold">{consoleT('console.check_9d60841e')}</h2>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                {consoleRich(
                    'console.asked_exactly_as_your_app_asks_it_part1_through_every_i_42253b49',
                    {
                        part1: (
                            <span className="mono">
                                {consoleT('console.get_api_v1_fga_check_267d7f52')}
                            </span>
                        ),
                    },
                )}
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
                    label={consoleT('console.resource_eb7a842f')}
                    hint={consoleT('console.type_id_09c21e99')}
                >
                    <Input
                        className="mono"
                        value={resource}
                        placeholder={tc('console.document_readme_29e90756')}
                        onChange={(event) => setResource(event.target.value)}
                    />
                </Field>
                <Field label={consoleT('console.relation_1367485d')}>
                    <Input
                        className="mono"
                        value={relation}
                        placeholder={consoleUiText('console.viewer_d35ca505')}
                        onChange={(event) => setRelation(event.target.value)}
                    />
                </Field>
                <Field
                    label={consoleT('console.subject_68971283')}
                    hint={consoleT('console.type_id_or_type_id_relation_for_a_group_363e3da0')}
                >
                    <Input
                        className="mono"
                        value={subject}
                        placeholder={tc('console.user_alice_dabd1db8')}
                        onChange={(event) => setSubject(event.target.value)}
                    />
                </Field>
                <Button type="submit" variant="primary">
                    {consoleT('console.check_9d60841e')}
                </Button>
                <Field
                    label={consoleT('console.at_least_as_fresh_as_bb83225d')}
                    optional
                    hint={consoleT('console.a_consistency_token_a_write_returned_c5751c5a')}
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
                                    ? consoleText('console.allowed_1bb201d1')
                                    : consoleText('console.denied_da404deb')}
                            </Badge>
                            <span className="mono">
                                {consoleRich(
                                    check.allowed === true
                                        ? 'console.fga_relation_allowed'
                                        : 'console.fga_relation_denied',
                                    {
                                        subject: check.subject,
                                        relation: check.relation,
                                        resource: check.resource,
                                    },
                                )}
                            </span>
                            {check.decidedAt !== null && (
                                <span className="text-xs" style={{ color: 'var(--faint)' }}>
                                    {consoleRich('console.at_revision_part1_14470163', {
                                        part1: <span className="mono">{check.decidedAt}</span>,
                                    })}
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
    const { t: tc } = usePublicTranslator();
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    const form = useForm({ tuple: '' });
    const [resource, setResource] = useState(filters.resource);
    const [subject, setSubject] = useState(filters.subject);

    return (
        <section className="mt-10">
            <h2 className="text-sm font-semibold">{consoleT('console.tuples_8e21e2d6')}</h2>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted)' }}>
                {consoleT(
                    'console.the_facts_your_app_wrote_what_they_add_up_to_is_what_a_3240d92d',
                )}
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
                    label={consoleT('console.write_a_tuple_0f0a6dca')}
                    error={form.errors.tuple}
                    hint={consoleT(
                        'console.resource_relation_subject_e_g_document_readme_viewer_gr_3c478660',
                    )}
                >
                    <Input
                        className="mono"
                        value={form.data.tuple}
                        placeholder={tc('console.document_readme_viewer_user_alice_f9405db5')}
                        onChange={(event) => form.setData('tuple', event.target.value)}
                    />
                </Field>
                <Button type="submit" loading={form.processing} disabled={form.data.tuple === ''}>
                    {consoleRich('console._part1_write_76920eab', {
                        part1: <Icon name="plus" className="w-4 h-4" />,
                    })}
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
                    label={consoleT('console.resource_eb7a842f')}
                    hint={consoleT('console.type_or_type_id_a1a0604f')}
                >
                    <Input
                        className="mono"
                        value={resource}
                        placeholder={tc('console.document_readme_29e90756')}
                        onChange={(event) => setResource(event.target.value)}
                    />
                </Field>
                <Field
                    label={consoleT('console.subject_68971283')}
                    hint={consoleT('console.type_type_id_or_type_id_relation_d5306ef0')}
                >
                    <Input
                        className="mono"
                        value={subject}
                        placeholder={tc('console.user_alice_dabd1db8')}
                        onChange={(event) => setSubject(event.target.value)}
                    />
                </Field>
                <Button type="submit">{consoleT('console.filter_638e249f')}</Button>
            </form>

            {tuples.length === 0 ? (
                <div className="mt-4 rounded-xl border" style={{ borderColor: 'var(--border)' }}>
                    <EmptyState
                        icon="shield"
                        equivalent="fga.tuples.write"
                        title={
                            filters.resource !== '' || filters.subject !== ''
                                ? consoleText('console.no_tuples_match_fbd3e0b2')
                                : consoleText('console.no_tuples_yet_4dc745c4')
                        }
                        description={consoleT(
                            'console.write_one_above_or_from_your_app_s_backend_in_batches_o_9ca507a7',
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
                                    aria-label={consoleFinalText('console.delete_part1_d70a6d4a', {
                                        part1: tuple,
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
                                {consoleT('console.first_page_0bdbb750')}
                            </Link>
                        </Button>
                    )}
                    {nextHref !== null && (
                        <Button asChild size="sm">
                            <Link href={nextHref} preserveScroll>
                                {consoleT('console.next_page_c08ac736')}
                            </Link>
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}

FineGrainedAuthorization.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
