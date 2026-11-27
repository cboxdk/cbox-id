import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { billingMoney } from '@/lib/billing';
import type { PageProps } from '@/types';
import { Breadcrumb, Button, Field, Icon, Input, PageHeader, Panel } from '@/ui';
import { store } from '@routes/orgs/projects';

interface PlanOffer {
    key: string;
    name: string;
    priceMinor: number;
    /** With tax, or null while Billing has not priced it for this organization yet. */
    totalMinor: number | null;
    currency: string;
    interval: string;
    environments: number | null;
}

type Props = PageProps<{
    indexHref: string;
    /** Empty where the deployment does not bill: the platform starts on its free allowance. */
    plans: PlanOffer[];
    defaultPlan: string | null;
    locale: string;
}>;

/**
 * NEW IDENTITY PLATFORM: what it is, what it is called, and which plan it runs on — in one
 * form, with the price on screen before anything is made. Production is created with it; a
 * paid plan is confirmed next on Billing's review, which prices it with tax.
 */
export default function CreateProject({ indexHref, plans, defaultPlan, locale }: Props) {
    const { t, choice } = useTranslator();
    const form = useForm({ name: '', plan: defaultPlan ?? '' });
    const chosen = plans.find((plan) => plan.key === form.data.plan);
    const paid = chosen !== undefined && chosen.priceMinor > 0;

    const price = (plan: PlanOffer) =>
        plan.priceMinor === 0
            ? t('console.create_platform_free')
            : t('console.create_platform_per_interval', {
                  price: billingMoney(plan.totalMinor ?? plan.priceMinor, plan.currency, locale),
                  interval: plan.interval,
              });

    return (
        <>
            <Breadcrumb href={indexHref} label={t('console.projects_04e2a972')} />

            <div className="mt-2">
                <PageHeader
                    description={t(
                        'console.a_separate_identity_provider_product_with_its_own_envir_97a4c57b',
                    )}
                />
            </div>

            <form
                className="mt-6"
                style={{ maxWidth: '44rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(store.url());
                }}
            >
                <Panel>
                    <Field
                        label={t('console.project_name_25498193')}
                        hint={t('console.create_platform_name_hint')}
                        error={form.errors.name}
                    >
                        <Input
                            name="name"
                            placeholder={t('console.product_two_23ff9b66')}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>

                    {plans.length > 0 && (
                        <fieldset className="mt-5">
                            <legend className="text-sm font-medium mb-1">
                                {t('console.create_platform_plan')}
                            </legend>
                            <p
                                className="text-sm mb-3"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {t('console.create_platform_plan_hint')}
                            </p>
                            <div className="grid gap-3 sm:grid-cols-3">
                                {plans.map((plan) => (
                                    <label key={plan.key} className="cbx-plan-option min-w-0">
                                        <input
                                            type="radio"
                                            name="plan"
                                            value={plan.key}
                                            className="sr-only"
                                            checked={form.data.plan === plan.key}
                                            onChange={() => form.setData('plan', plan.key)}
                                        />
                                        <span className="block font-medium">{plan.name}</span>
                                        <span className="block text-sm mono">
                                            {price(plan)}
                                            {plan.priceMinor > 0 &&
                                                plan.totalMinor === null &&
                                                ` ${t('console.create_platform_tax_pending')}`}
                                        </span>
                                        {plan.environments !== null && (
                                            <span
                                                className="block text-sm mt-1"
                                                style={{ color: 'var(--muted-foreground)' }}
                                            >
                                                {choice(
                                                    'console.create_platform_environments',
                                                    plan.environments,
                                                )}
                                            </span>
                                        )}
                                    </label>
                                ))}
                            </div>
                            {form.errors.plan && (
                                <p
                                    role="alert"
                                    className="text-sm mt-2"
                                    style={{ color: 'var(--destructive)' }}
                                >
                                    {form.errors.plan}
                                </p>
                            )}
                        </fieldset>
                    )}

                    <ul
                        className="mt-5 space-y-1 text-sm"
                        style={{ color: 'var(--muted-foreground)' }}
                    >
                        <li className="flex items-center gap-2">
                            <Icon name="check" className="w-4 h-4 shrink-0" />
                            {t('console.create_platform_production_note')}
                        </li>
                        {paid && (
                            <li className="flex items-center gap-2">
                                <Icon name="info" className="w-4 h-4 shrink-0" />
                                {t('console.create_platform_paid_note')}
                            </li>
                        )}
                    </ul>

                    <div className="flex items-center gap-2 mt-5">
                        <Button type="submit" variant="primary" loading={form.processing}>
                            {t('console.create_project_fa67e4d0')}
                        </Button>
                        <Button asChild>
                            <Link href={indexHref}>{t('console.cancel_19766ed6')}</Link>
                        </Button>
                    </div>
                </Panel>
            </form>
        </>
    );
}

CreateProject.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
