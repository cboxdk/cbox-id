import { useTranslator } from '@/i18n';
import { Link, router, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, PageProps } from '@/types';
import { Badge, Button, Field, Icon, PageHeader, Textarea } from '@/ui';

interface Validation {
    valid: boolean;
    errors: { line: number; message: string }[];
    canonical: string | null;
}

type Props = PageProps<{
    help: HelpContent;
    /** The schema as saved — or, before the first save, the documents-in-folders starter. */
    source: string;
    defined: boolean;
    version: number;
    /** The answer to "Validate", for the text that was validated (`?draft=`). */
    validation: Validation | null;
    updateHref: string;
    validateHref: string;
    indexHref: string;
}>;

/**
 * The schema editor: write the model, validate it as you go, save it. Saving changes the
 * answer to every check in the environment at once — and is refused while tuples exist
 * the new schema would no longer allow, naming them.
 */
export default function FgaSchemaEditor({
    help,
    source,
    defined,
    version,
    validation,
    updateHref,
    validateHref,
    indexHref,
}: Props) {
    const { t: tc } = useTranslator();

    // Validating posts and comes back to this page with state preserved, so the text being
    // edited survives the round trip.
    const form = useForm({ schema: source });

    return (
        <>
            <PageHeader
                help={help}
                badge={
                    defined ? (
                        <Badge>
                            {tc('console.v_4c94485e')}
                            {version}
                        </Badge>
                    ) : undefined
                }
                description={tc('console.one_type_per_resource_type_its_relation_s_be_18943de8')}
                actions={
                    <Button asChild>
                        <Link href={indexHref}>
                            <Icon name="arrow-left" className="w-4 h-4" />
                            {tc('console.overview_d4b1ea57')}
                        </Link>
                    </Button>
                }
            />

            <form
                className="mt-6 grid gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(updateHref, { preserveScroll: true });
                }}
            >
                <Field label={tc('console.schema_07b091a3')} error={form.errors.schema}>
                    <Textarea
                        className="mono text-xs"
                        rows={24}
                        spellCheck={false}
                        value={form.data.schema}
                        onChange={(event) => form.setData('schema', event.target.value)}
                    />
                </Field>

                {validation !== null && (
                    <output className="card p-4 block">
                        {validation.valid ? (
                            <p className="flex items-center gap-2 text-sm">
                                <Badge tone="success">{tc('console.valid_f5162206')}</Badge>
                                {tc(
                                    'console.every_name_it_uses_is_defined_and_nothing_su_a169d152',
                                )}
                            </p>
                        ) : (
                            <>
                                <p className="flex items-center gap-2 text-sm">
                                    <Badge tone="danger">
                                        {validation.errors.length === 1
                                            ? tc('console.1_problem_41ac3c60')
                                            : tc('console.value0_problems_c91917d2', {
                                                  value0: validation.errors.length,
                                              })}
                                    </Badge>
                                </p>
                                <ul className="mt-2 grid gap-1 text-xs">
                                    {validation.errors.map((error) => (
                                        <li key={`${error.line}:${error.message}`} className="mono">
                                            {error.line > 0
                                                ? tc('console.line_value0_20717ce8', {
                                                      value0: error.line,
                                                  })
                                                : ''}
                                            {error.message}
                                        </li>
                                    ))}
                                </ul>
                            </>
                        )}
                    </output>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        onClick={() =>
                            router.get(
                                validateHref,
                                { draft: form.data.schema },
                                {
                                    preserveScroll: true,
                                    preserveState: true,
                                    only: ['validation'],
                                    replace: true,
                                },
                            )
                        }
                    >
                        <Icon name="check" className="w-4 h-4" />
                        {tc('console.validate_40611abb')}
                    </Button>
                    <Button type="submit" variant="danger" loading={form.processing}>
                        {defined
                            ? tc('console.save_every_check_uses_it_at_once_29bf6473')
                            : tc('console.save_schema_325690c5')}
                    </Button>
                </div>
            </form>
        </>
    );
}

FgaSchemaEditor.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
