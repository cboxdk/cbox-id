import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Checkbox, Field, Icon, Input, PageHeader, Panel, RadioGroup } from '@/ui';

interface PointOption {
    value: string;
    label: string;
    description: string;
}

type Props = PageProps<{
    points: PointOption[];
    /** Whether registering for the whole environment is on offer here. */
    mayScopeEnvironmentWide: boolean;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateHook({
    points,
    mayScopeEnvironmentWide,
    indexHref,
    storeHref,
}: Props) {
    const form = useForm({
        point: points[0]?.value ?? '',
        url: '',
        environmentWide: false,
    });

    return (
        <>
            <Link
                href={indexHref}
                className="text-sm inline-flex items-center gap-1"
                style={{ color: 'var(--muted-foreground)' }}
            >
                <Icon
                    name="chevron"
                    className="w-3.5 h-3.5"
                    style={{ transform: 'rotate(90deg)' }}
                />
                Inline hooks
            </Link>

            <div className="mt-2">
                <PageHeader description="The signing secret is shown once, right after you register the endpoint." />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '40rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref);
                }}
            >
                <Panel
                    title="Hook point"
                    description="The moment your endpoint is called, and what its answer is allowed to change."
                >
                    {/*
                        Radios rather than a select: there are six, each needs a sentence
                        to be choosable at all, and this is the one field that decides
                        which operation your endpoint gets a say in. A dropdown hides five
                        of the six behind a click and has nowhere to put the sentence.
                    */}
                    <RadioGroup
                        label="Hook point"
                        value={form.data.point}
                        onValueChange={(point) => form.setData('point', point)}
                        options={points.map((point) => ({
                            value: point.value,
                            label: point.label,
                            hint: point.description,
                        }))}
                    />
                    {form.errors.point !== undefined && (
                        <p className="field-error" role="alert">
                            {form.errors.point}
                        </p>
                    )}
                </Panel>

                <Panel title="Endpoint">
                    <div className="space-y-4">
                        <Field
                            label="Endpoint URL"
                            hint="Called synchronously while somebody waits at the sign-in screen. Verify the X-Cbox-Signature header on every request."
                            error={form.errors.url}
                        >
                            <Input
                                name="url"
                                type="url"
                                className="mono"
                                placeholder="https://example.com/hooks/token"
                                value={form.data.url}
                                onChange={(event) => form.setData('url', event.target.value)}
                            />
                        </Field>

                        {mayScopeEnvironmentWide && (
                            <Checkbox
                                checked={form.data.environmentWide}
                                onCheckedChange={(checked) =>
                                    form.setData('environmentWide', checked)
                                }
                                label="Environment-wide"
                                hint="Call this endpoint for every organization in this environment, not just the one selected in the bar above."
                            />
                        )}
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Register endpoint
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateHook.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
