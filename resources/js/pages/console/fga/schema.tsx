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
    // Validating posts and comes back to this page with state preserved, so the text being
    // edited survives the round trip.
    const form = useForm({ schema: source });

    return (
        <>
            <PageHeader
                help={help}
                badge={defined ? <Badge>v{version}</Badge> : undefined}
                description="One `type` per resource type, its `relation`s below it. A relation is decided by the subjects a tuple names directly ([user, group#member]), another relation (owner), a relation on a parent (viewer from parent), or a combination with or, and, but not."
                actions={
                    <Button asChild>
                        <Link href={indexHref}>
                            <Icon name="arrow-left" className="w-4 h-4" />
                            Overview
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
                <Field label="Schema" error={form.errors.schema}>
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
                                <Badge tone="success">Valid</Badge>
                                Every name it uses is defined, and nothing subtracts itself.
                            </p>
                        ) : (
                            <>
                                <p className="flex items-center gap-2 text-sm">
                                    <Badge tone="danger">
                                        {validation.errors.length === 1
                                            ? '1 problem'
                                            : `${validation.errors.length} problems`}
                                    </Badge>
                                </p>
                                <ul className="mt-2 grid gap-1 text-xs">
                                    {validation.errors.map((error) => (
                                        <li key={`${error.line}:${error.message}`} className="mono">
                                            {error.line > 0 ? `Line ${error.line}: ` : ''}
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
                        Validate
                    </Button>
                    <Button type="submit" variant="danger" loading={form.processing}>
                        {defined ? 'Save — every check uses it at once' : 'Save schema'}
                    </Button>
                </div>
            </form>
        </>
    );
}

FgaSchemaEditor.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
