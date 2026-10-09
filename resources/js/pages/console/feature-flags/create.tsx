import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Checkbox, Field, Icon, Input, PageHeader, Panel } from '@/ui';

type Props = PageProps<{
    indexHref: string;
    storeHref: string;
}>;

export default function CreateFeatureFlag({ indexHref, storeHref }: Props) {
    const form = useForm({ key: '', description: '', defaultValue: false });

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
                Feature flags
            </Link>

            <div className="mt-2">
                <PageHeader description="A switch your apps ask about. You choose who it is on for on the next page." />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '36rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref);
                }}
            >
                <Panel>
                    <div className="space-y-4">
                        <Field
                            label="Key"
                            hint="What your code asks for, and what the feature_flags claim carries. Lowercase letters and digits, with - _ or . between them. It cannot be changed later."
                            error={form.errors.key}
                        >
                            <Input
                                name="key"
                                className="mono"
                                spellCheck={false}
                                autoCapitalize="off"
                                placeholder="new-dashboard"
                                value={form.data.key}
                                onChange={(event) => form.setData('key', event.target.value)}
                            />
                        </Field>

                        <Field label="Description" optional error={form.errors.description}>
                            <Input
                                name="description"
                                placeholder="The redesigned dashboard"
                                value={form.data.description}
                                onChange={(event) =>
                                    form.setData('description', event.target.value)
                                }
                            />
                        </Field>

                        <Checkbox
                            checked={form.data.defaultValue}
                            onCheckedChange={(checked) => form.setData('defaultValue', checked)}
                            label="On by default"
                            hint="Who gets the flag when no rule names them. Leave it off to roll a feature out to named organizations or a percentage first."
                        />
                    </div>
                </Panel>

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Create flag
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href={indexHref}>Cancel</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateFeatureFlag.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
