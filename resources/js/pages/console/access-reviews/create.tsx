import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, EmptyState, Field, Icon, Input, PageHeader, Panel, RadioGroup } from '@/ui';

type Covers = 'organization' | 'staff';

type Props = PageProps<{
    organizationChosen: boolean;
    organizationName: string | null;
    /** The environment console can also review staff roles, which no organization owns. */
    canReviewStaff: boolean;
    covers: Covers;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateAccessReview({
    organizationChosen,
    organizationName,
    canReviewStaff,
    covers,
    indexHref,
    storeHref,
}: Props) {
    const form = useForm<{ name: string; covers: Covers }>({ name: '', covers });

    const reviewingStaff = canReviewStaff && form.data.covers === 'staff';
    const needsOrganization = !reviewingStaff && !organizationChosen;

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
                <PageHeader
                    description={
                        reviewingStaff
                            ? 'Snapshots every staff role — each role held across the whole environment — as items to certify or revoke. A revoke takes the role back in every organization at once.'
                            : 'Snapshots every current role assignment and membership in the selected organization as items to certify or revoke.'
                    }
                />
            </div>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '36rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref);
                }}
            >
                {canReviewStaff && (
                    <Panel>
                        <RadioGroup
                            label="What to review"
                            name="covers"
                            value={form.data.covers}
                            onValueChange={(value) => form.setData('covers', value)}
                            options={[
                                {
                                    value: 'organization',
                                    label: organizationName ?? 'One organization',
                                    hint:
                                        organizationName === null
                                            ? 'Pick the organization in the bar above first.'
                                            : "Its members' roles and memberships.",
                                },
                                {
                                    value: 'staff',
                                    label: 'Staff roles',
                                    hint: 'Everyone holding a role across the whole environment. Organizations never see this review.',
                                },
                            ]}
                        />
                    </Panel>
                )}

                {needsOrganization ? (
                    // Nothing is wrong with this administrator: a review of an
                    // organization's access covers ONE organization, and they have not
                    // said which.
                    <div className="card">
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
                    <>
                        <Panel>
                            <Field
                                label="Review name"
                                hint="What this round is called when somebody asks which review a decision came from — a quarter, an audit, a date."
                                error={form.errors.name}
                            >
                                <Input
                                    name="name"
                                    placeholder={
                                        reviewingStaff ? 'Q3 staff access' : 'Q3 access review'
                                    }
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                />
                            </Field>
                        </Panel>

                        <div className="flex items-center gap-2">
                            <Button type="submit" variant="primary" loading={form.processing}>
                                Open review
                            </Button>
                            <Button asChild>
                                <Link href={indexHref}>Cancel</Link>
                            </Button>
                        </div>
                    </>
                )}
            </form>
        </>
    );
}

CreateAccessReview.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
