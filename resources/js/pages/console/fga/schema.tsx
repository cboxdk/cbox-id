import { useTranslator as useConsoleTranslator } from '@/i18n';
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
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleText } = useConsoleTranslator();
    const { rich: consoleRich } = useConsoleTranslator();
    const { t: consoleT } = useConsoleTranslator();
    // Validating posts and comes back to this page with state preserved, so the text being
    // edited survives the round trip.
    const form = useForm({ schema: source });

    return (
        <>
            <PageHeader
                help={help}
                badge={defined ? <Badge>v{version}</Badge> : undefined}
                description={consoleT(
                    'console.one_type_per_resource_type_its_relation_s_below_it_a_re_18943de8',
                )}
                actions={
                    <Button asChild>
                        <Link href={indexHref}>
                            {consoleRich('console._part1_overview_0ade1da7', {
                                part1: <Icon name="arrow-left" className="w-4 h-4" />,
                            })}
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
                <Field label={consoleT('console.schema_07b091a3')} error={form.errors.schema}>
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
                                {consoleRich(
                                    'console._part1_every_name_it_uses_is_defined_and_nothing_subtra_9ef8bb73',
                                    {
                                        part1: (
                                            <Badge tone="success">
                                                {consoleT('console.valid_f5162206')}
                                            </Badge>
                                        ),
                                    },
                                )}
                            </p>
                        ) : (
                            <>
                                <p className="flex items-center gap-2 text-sm">
                                    <Badge tone="danger">
                                        {consoleFinalText('console.problems_count', {
                                            count: validation.errors.length,
                                        })}
                                    </Badge>
                                </p>
                                <ul className="mt-2 grid gap-1 text-xs">
                                    {validation.errors.map((error) => (
                                        <li key={`${error.line}:${error.message}`} className="mono">
                                            {error.line > 0
                                                ? consoleFinalText('console.line_part1_8c02d91a', {
                                                      part1: error.line,
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
                        {consoleRich('console._part1_validate_4be424e7', {
                            part1: <Icon name="check" className="w-4 h-4" />,
                        })}
                    </Button>
                    <Button type="submit" variant="danger" loading={form.processing}>
                        {defined
                            ? consoleText('console.save_every_check_uses_it_at_once_29bf6473')
                            : consoleText('console.save_schema_325690c5')}
                    </Button>
                </div>
            </form>
        </>
    );
}

FgaSchemaEditor.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
