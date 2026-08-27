import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, EmptyState, Field, Icon, Input, PageHeader, Panel } from '@/ui';

type Props = PageProps<{
    organizationChosen: boolean;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateAccessReview({ organizationChosen, indexHref, storeHref }: Props) {
    const form = useForm({ name: '' });

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
                Access reviews
            </Link>

            <div className="mt-2">
                <PageHeader description="Snapshots every current role assignment and membership in the selected organization as items to certify or revoke." />
            </div>

            {!organizationChosen ? (
                // Nothing is wrong with this administrator: a review covers ONE
                // organization's access, and they have not said which.
                <div className="card mt-6" style={{ maxWidth: '36rem' }}>
                    <EmptyState
                        icon="layers"
                        title="Choose an organization"
                        description="A review snapshots one organization's roles and memberships, so there is nothing to take a picture of yet. Pick the organization in the bar above."
                        actions={
                            <Button asChild>
                                <Link href={indexHref}>Back to Access reviews</Link>
                            </Button>
                        }
                    />
                </div>
            ) : (
                <form
                    className="mt-6"
                    style={{ maxWidth: '36rem' }}
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(storeHref);
                    }}
                >
                    <Panel>
                        <Field
                            label="Review name"
                            hint="What this round is called when somebody asks which review a decision came from — a quarter, an audit, a date."
                            error={form.errors.name}
                        >
                            <Input
                                name="name"
                                placeholder="Q3 access review"
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>
                    </Panel>

                    <div className="mt-6 flex items-center gap-2">
                        <Button type="submit" variant="primary" loading={form.processing}>
                            Open review
                        </Button>
                        <Button asChild>
                            <Link href={indexHref}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            )}
        </>
    );
}

CreateAccessReview.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
