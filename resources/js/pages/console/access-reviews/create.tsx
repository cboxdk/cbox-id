import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { OrganizationPicker, PageProps } from '@/types';
import {
    Breadcrumb,
    Button,
    Field,
    Input,
    OrganizationPickerField,
    PageHeader,
    Panel,
    RadioGroup,
} from '@/ui';

type Covers = 'organization' | 'staff';

type Props = PageProps<{
    /**
     * "For which organization?" — the environment console only, where a review of one
     * organization's access has to say which; null on the organization console, whose
     * review is always its own.
     */
    organization: OrganizationPicker | null;
    /** The environment console can also review staff roles, which no organization owns. */
    canReviewStaff: boolean;
    covers: Covers;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateAccessReview({
    organization,
    canReviewStaff,
    covers,
    indexHref,
    storeHref,
}: Props) {
    const { t: tc } = useTranslator();

    const form = useForm<{ name: string; covers: Covers; organization: string }>({
        name: '',
        covers,
        organization: organization?.selected?.id ?? '',
    });

    const reviewingStaff = canReviewStaff && form.data.covers === 'staff';

    return (
        <>
            <Breadcrumb href={indexHref} label={tc('console.access_reviews_f0cc91be')} />

            <div className="mt-2">
                <PageHeader
                    description={
                        reviewingStaff
                            ? tc('console.snapshots_every_admin_support_role_each_role_351f6f6d')
                            : tc('console.snapshots_every_current_role_assignment_and__f56eb372')
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
                            label={tc('console.what_to_review_b92221f0')}
                            name="covers"
                            value={form.data.covers}
                            onValueChange={(value) => form.setData('covers', value)}
                            options={[
                                {
                                    value: 'organization',
                                    label: tc('console.one_organization_d222d0f3'),
                                    hint: tc('console.its_members_roles_and_memberships_19c68097'),
                                },
                                {
                                    value: 'staff',
                                    label: tc('console.admin_support_roles_19f22d25'),
                                    hint: tc(
                                        'console.everyone_holding_a_role_across_the_whole_env_eb61bd2a',
                                    ),
                                },
                            ]}
                        />
                    </Panel>
                )}

                <Panel>
                    <div className="space-y-5">
                        {/*
                            A review of an organization's access covers ONE organization, so
                            the environment console asks which — on the form, rather than
                            sending somebody to change a setting somewhere else first.
                        */}
                        {organization !== null && !reviewingStaff && (
                            <OrganizationPickerField
                                picker={organization}
                                error={form.errors.organization}
                                onChange={(id) => form.setData('organization', id)}
                                hint={tc(
                                    'console.whose_roles_and_memberships_the_review_snaps_e7576353',
                                )}
                            />
                        )}

                        <Field
                            label={tc('console.review_name_f0e5d03b')}
                            hint={tc(
                                'console.what_this_round_is_called_when_somebody_asks_6e0713c0',
                            )}
                            error={form.errors.name}
                        >
                            <Input
                                name="name"
                                placeholder={
                                    reviewingStaff
                                        ? tc('console.q3_admin_support_access_59fc1624')
                                        : tc('console.q3_access_review_df7a905b')
                                }
                                value={form.data.name}
                                onChange={(event) => form.setData('name', event.target.value)}
                            />
                        </Field>
                    </div>
                </Panel>

                <div className="flex items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.open_review_8a6e0b28')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{tc('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>
            </form>
        </>
    );
}

CreateAccessReview.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
