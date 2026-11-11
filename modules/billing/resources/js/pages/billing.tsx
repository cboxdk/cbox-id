import ProjectPayments, { type ProjectPaymentsData } from '../components/ProjectPayments';
import { router, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { Stripe, StripeElements } from '@stripe/stripe-js';
import { useTranslator, type MessageKey } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { billingMoney, billingRequest } from '@/lib/billing';
import { useUnsavedChangesGuard } from '@/lib/unsavedChanges';
import type { HelpContent, PageProps } from '@/types';
import { Button, Combobox, Dialog, Field, Input, PageHeader, Panel } from '@/ui';
import { billing as billingPage } from '@routes/orgs/settings';
import {
    account as accountRoute,
    complete as completeRoute,
    setup as setupRoute,
} from '@routes/orgs/settings/billing';
import {
    defaultMethod,
    remove as removeMethod,
} from '@routes/orgs/settings/billing/payment-method';

interface Account {
    name: string;
    billingEmail: string | null;
    addressLine1: string | null;
    addressLine2: string | null;
    postalCode: string | null;
    city: string | null;
    country: string | null;
    taxId: string | null;
}
interface Card {
    id: string;
    brand: string;
    last4: string;
    expMonth: number;
    expYear: number;
    isDefault: boolean;
}
interface Invoice {
    number: string;
    date: string | null;
    amountMinor: number;
    subtotalMinor: number | null;
    taxMinor: number | null;
    currency: string;
    status: string;
    downloadUrl: string;
}
interface Subscription {
    plan: string;
    status: string;
    paused: boolean;
    periodStart: string | null;
    periodEnd: string | null;
    renewsAt: string | null;
    trialEndsAt: string | null;
    recurring: {
        netMinor: number;
        totalMinor: number | null;
        currency: string;
        tax: { tax_minor: number | null } | null;
    } | null;
}
type Props = PageProps<{
    organization: string;
    projectPayments: ProjectPaymentsData | null;
    account: Account | null;
    subscription: Subscription | null;
    paymentMethods: Card[] | null;
    invoices: Invoice[] | null;
    environmentAllowance: number | null;
    sectionErrors: Record<string, string>;
    canManage: boolean;
    countries: { value: string; label: string }[];
    help: HelpContent;
}>;
const statuses: Record<string, MessageKey> = Object.fromEntries(
    [
        'active',
        'trialing',
        'past_due',
        'canceled',
        'unpaid',
        'incomplete',
        'incomplete_expired',
        'paid',
        'draft',
        'open',
        'void',
        'uncollectible',
        'paused',
    ].map((value) => [value, `billing.${value}`]),
) as Record<string, MessageKey>;

export default function Billing({
    organization,
    account,
    subscription,
    paymentMethods,
    invoices,
    environmentAllowance,
    projectPayments,
    sectionErrors,
    canManage,
    countries,
    help,
}: Props) {
    const { t, locale } = useTranslator();
    const money = (amount: number, currency: string) => billingMoney(amount, currency, locale);
    const date = (value: string) =>
        new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(new Date(value));
    const form = useForm({
        organization,
        invoice_name: account?.name ?? '',
        billing_email: account?.billingEmail ?? '',
        address_line1: account?.addressLine1 ?? '',
        address_line2: account?.addressLine2 ?? '',
        postal_code: account?.postalCode ?? '',
        city: account?.city ?? '',
        country: account?.country ?? '',
        tax_id: account?.taxId ?? '',
    });
    const accountForm = useRef<HTMLFormElement>(null);
    useEffect(() => {
        accountForm.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
    }, [form.errors]);
    const [savedDetails, setSavedDetails] = useState(() => JSON.stringify(form.data));
    const guard = useUnsavedChangesGuard(JSON.stringify(form.data) !== savedDetails);
    const mutation = useForm({ organization });
    const [remove, setRemove] = useState<Card | null>(null);
    const [adding, setAdding] = useState(false);
    const [notice, setNotice] = useState<MessageKey | null>(null);
    const [returning, setReturning] = useState(false);
    const returnStarted = useRef(false);
    const reload = () =>
        router.reload({
            only: [
                'account',
                'subscription',
                'paymentMethods',
                'invoices',
                'environmentAllowance',
                'sectionErrors',
                'projectPayments',
            ],
        });
    const completeReturn = async () => {
        setReturning(true);
        setNotice(null);
        try {
            await billingRequest(completeRoute.url(), organization);
            setNotice('billing.payment_saved');
            history.replaceState(null, '', billingPage.url({}, { query: { organization } }));
            reload();
        } catch (error) {
            setNotice(
                error instanceof Error && error.message === '409'
                    ? 'billing.payment_pending'
                    : 'billing.payment_failed',
            );
        } finally {
            setReturning(false);
        }
    };
    useEffect(() => {
        if (
            new URLSearchParams(location.search).get('setup_return') === '1' &&
            !returnStarted.current
        ) {
            returnStarted.current = true;
            void completeReturn();
        }
        // An organization change remounts this component through the console boundary.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const error = (section: string) =>
        sectionErrors[section] ? (
            <div role="alert" className="flex flex-wrap items-center gap-3 text-sm">
                <p>{t('billing.unavailable')}</p>
                <Button variant="secondary" onClick={reload}>
                    {t('billing.retry')}
                </Button>
            </div>
        ) : null;
    const fields = [
        'invoice_name',
        'billing_email',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'tax_id',
    ] as const;
    return (
        <>
            <Dialog
                open={guard.pending !== null}
                onOpenChange={(open) => {
                    if (!open) guard.stay();
                }}
                title={t('console.leave_without_saving_2190d03a')}
                description={t('console.ux_discard_changes')}
                footer={
                    <>
                        <Button onClick={guard.stay}>{t('console.keep_editing_e76fd2ad')}</Button>
                        <Button variant="danger" onClick={guard.leave}>
                            {t('console.discard_and_leave_07e5b915')}
                        </Button>
                    </>
                }
            />
            <PageHeader help={help} description={t('billing.description')} />
            <div className="mt-6 grid gap-4">
                {error('projectPayments') ??
                    (projectPayments && (
                        <ProjectPayments
                            key={organization}
                            data={projectPayments}
                            canManage={canManage}
                            organization={organization}
                            locale={locale}
                        />
                    ))}
                <Panel title={t('console.subscription_4999c6c6')}>
                    {error('subscription') ??
                        (subscription ? (
                            <dl className="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <dt className="text-sm text-[var(--muted)]">
                                        {t('console.plan_fa8ed0bd')}
                                    </dt>
                                    <dd className="font-medium break-words">{subscription.plan}</dd>
                                </div>
                                <div>
                                    <dt className="text-sm text-[var(--muted)]">
                                        {t('billing.status')}
                                    </dt>
                                    <dd>
                                        {t(
                                            subscription.paused
                                                ? 'billing.paused'
                                                : (statuses[subscription.status] ??
                                                      'billing.unknown_status'),
                                        )}
                                    </dd>
                                </div>
                                {subscription.periodStart && subscription.periodEnd && (
                                    <div>
                                        <dt className="text-sm text-[var(--muted)]">
                                            {t('billing.period')}
                                        </dt>
                                        <dd>
                                            {date(subscription.periodStart)} –{' '}
                                            {date(subscription.periodEnd)}
                                        </dd>
                                    </div>
                                )}
                                {subscription.renewsAt && (
                                    <div>
                                        <dt className="text-sm text-[var(--muted)]">
                                            {t('billing.renewal')}
                                        </dt>
                                        <dd>{date(subscription.renewsAt)}</dd>
                                    </div>
                                )}
                                {subscription.trialEndsAt && (
                                    <div>
                                        <dt className="text-sm text-[var(--muted)]">
                                            {t('billing.trial_end')}
                                        </dt>
                                        <dd>{date(subscription.trialEndsAt)}</dd>
                                    </div>
                                )}
                                {subscription.recurring && (
                                    <div className="space-y-1 text-sm">
                                        <dt className="sr-only">{t('console.total_c9b3c382')}</dt>
                                        <dd>
                                            <p>
                                                {t('billing.subtotal')}:{' '}
                                                {money(
                                                    subscription.recurring.netMinor,
                                                    subscription.recurring.currency,
                                                )}
                                            </p>
                                            {subscription.recurring.tax?.tax_minor != null && (
                                                <p>
                                                    {t('billing.tax')}:{' '}
                                                    {money(
                                                        subscription.recurring.tax.tax_minor,
                                                        subscription.recurring.currency,
                                                    )}
                                                </p>
                                            )}
                                            {subscription.recurring.totalMinor !== null ? (
                                                <p className="font-semibold">
                                                    {t('console.total_c9b3c382')}:{' '}
                                                    {money(
                                                        subscription.recurring.totalMinor,
                                                        subscription.recurring.currency,
                                                    )}
                                                </p>
                                            ) : (
                                                <p>{t('billing.tax_pending')}</p>
                                            )}
                                        </dd>
                                    </div>
                                )}
                            </dl>
                        ) : (
                            <p className="text-sm text-[var(--muted)]">
                                {t('billing.no_subscription')}
                            </p>
                        ))}
                    <div className="mt-4 border-t border-[var(--border)] pt-4">
                        {error('features') ?? (
                            <>
                                <p className="text-sm">
                                    {t('billing.allowance')}:{' '}
                                    <strong>
                                        {environmentAllowance?.toLocaleString(locale) ?? '—'}
                                    </strong>
                                </p>
                                <p className="mt-1 text-sm text-[var(--muted)]">
                                    {t('billing.allowance_description')}
                                </p>
                            </>
                        )}
                    </div>
                </Panel>
                <Panel title={t('billing.account')}>
                    {error('account') ??
                        (account && (
                            <form
                                ref={accountForm}
                                noValidate
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.patch(accountRoute.url(), {
                                        preserveScroll: true,
                                        only: ['account', 'sectionErrors', 'errors', 'flash'],
                                        onSuccess: () => {
                                            form.defaults();
                                            setSavedDetails(JSON.stringify(form.data));
                                        },
                                    });
                                }}
                                className="grid gap-4 sm:grid-cols-2"
                            >
                                {fields.map((field) => (
                                    <Field
                                        key={field}
                                        id={`billing-${field}`}
                                        label={t(`billing.${field}`)}
                                        error={form.errors[field]}
                                        required={field !== 'address_line2' && field !== 'tax_id'}
                                    >
                                        <Input
                                            name={field}
                                            disabled={!canManage || form.processing}
                                            type={field === 'billing_email' ? 'email' : 'text'}
                                            value={form.data[field]}
                                            onChange={(event) =>
                                                form.setData(field, event.target.value)
                                            }
                                            maxLength={
                                                field === 'tax_id'
                                                    ? 64
                                                    : field === 'postal_code'
                                                      ? 32
                                                      : 254
                                            }
                                        />
                                    </Field>
                                ))}
                                <Field
                                    id="billing-country"
                                    label={t('console.country_701d021d')}
                                    required
                                    error={form.errors.country}
                                >
                                    <Combobox
                                        options={countries}
                                        value={form.data.country}
                                        onValueChange={(value) => form.setData('country', value)}
                                        disabled={!canManage || form.processing}
                                    />
                                </Field>
                                <p className="text-sm text-[var(--muted)] sm:col-span-2">
                                    {t('billing.tax_hint')}
                                </p>
                                {'payment' in form.errors && (
                                    <p role="alert" className="text-sm sm:col-span-2">
                                        {String(form.errors.payment)}
                                    </p>
                                )}
                                {canManage && (
                                    <div className="sm:col-span-2">
                                        <Button type="submit" disabled={form.processing}>
                                            {t(
                                                form.processing
                                                    ? 'billing.saving'
                                                    : 'console.save_changes_dd0ae7a5',
                                            )}
                                        </Button>
                                    </div>
                                )}
                            </form>
                        ))}
                </Panel>
                <Panel
                    title={t('billing.cards')}
                    description={t('billing.cards_description')}
                    action={
                        canManage && (
                            <Button variant="secondary" onClick={() => setAdding(true)}>
                                {t('billing.add_card')}
                            </Button>
                        )
                    }
                >
                    {notice && (
                        <output className="mb-4 flex flex-wrap items-center gap-3 text-sm">
                            <p>{t(notice)}</p>
                            {notice !== 'billing.payment_saved' && returnStarted.current && (
                                <Button onClick={() => void completeReturn()} disabled={returning}>
                                    {t(returning ? 'billing.loading' : 'billing.retry')}
                                </Button>
                            )}
                        </output>
                    )}
                    {'payment' in mutation.errors && (
                        <p role="alert" className="mb-3 text-sm">
                            {String(mutation.errors.payment)}
                        </p>
                    )}
                    {error('paymentMethods') ?? (
                        <div className="space-y-4">
                            {paymentMethods?.length ? (
                                paymentMethods.map((card) => (
                                    <div
                                        key={card.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-[var(--border)] p-4"
                                    >
                                        <div>
                                            <p className="font-medium capitalize">
                                                {card.brand} •••• {card.last4}{' '}
                                                {card.isDefault && (
                                                    <span className="ml-2 text-xs text-[var(--muted)]">
                                                        {t('billing.default')}
                                                    </span>
                                                )}
                                            </p>
                                            <p className="mt-1 text-sm text-[var(--muted)]">
                                                {t('billing.expires', {
                                                    date: `${String(card.expMonth).padStart(2, '0')}/${card.expYear}`,
                                                })}
                                            </p>
                                        </div>
                                        {canManage && (
                                            <div className="flex flex-wrap gap-2">
                                                {!card.isDefault && (
                                                    <Button
                                                        variant="secondary"
                                                        size="sm"
                                                        disabled={mutation.processing}
                                                        onClick={() =>
                                                            mutation.patch(
                                                                defaultMethod.url({
                                                                    method: card.id,
                                                                }),
                                                                {
                                                                    preserveScroll: true,
                                                                    only: [
                                                                        'paymentMethods',
                                                                        'sectionErrors',
                                                                        'errors',
                                                                        'flash',
                                                                    ],
                                                                },
                                                            )
                                                        }
                                                    >
                                                        {t('billing.make_default')}
                                                    </Button>
                                                )}
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={mutation.processing}
                                                    onClick={() => setRemove(card)}
                                                >
                                                    {t('console.remove_c3812fc4')}
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                ))
                            ) : (
                                <p className="text-sm text-[var(--muted)]">
                                    {t('billing.no_cards')}
                                </p>
                            )}
                        </div>
                    )}
                </Panel>
                <Panel title={t('billing.invoices')}>
                    {error('invoices') ?? (
                        <div className="space-y-4">
                            {invoices?.length ? (
                                invoices.map((invoice) => (
                                    <article
                                        key={invoice.number}
                                        className="flex flex-wrap items-start justify-between gap-4 rounded-lg border border-[var(--border)] p-4"
                                    >
                                        <div className="min-w-0">
                                            <h3 className="break-words font-medium">
                                                {t('billing.invoice_number', {
                                                    number: invoice.number,
                                                })}
                                            </h3>
                                            <p className="mt-1 text-sm text-[var(--muted)]">
                                                {invoice.date && date(`${invoice.date}T12:00:00`)} ·{' '}
                                                {t(
                                                    statuses[invoice.status] ??
                                                        'billing.unknown_status',
                                                )}
                                            </p>
                                            <dl className="mt-3 space-y-1 text-sm">
                                                {invoice.subtotalMinor !== null && (
                                                    <div className="flex gap-2">
                                                        <dt>{t('billing.subtotal')}:</dt>
                                                        <dd>
                                                            {money(
                                                                invoice.subtotalMinor,
                                                                invoice.currency,
                                                            )}
                                                        </dd>
                                                    </div>
                                                )}
                                                {invoice.taxMinor !== null && (
                                                    <div className="flex gap-2">
                                                        <dt>{t('billing.tax')}:</dt>
                                                        <dd>
                                                            {money(
                                                                invoice.taxMinor,
                                                                invoice.currency,
                                                            )}
                                                        </dd>
                                                    </div>
                                                )}
                                                <div className="flex gap-2 font-semibold">
                                                    <dt>{t('console.total_c9b3c382')}:</dt>
                                                    <dd>
                                                        {money(
                                                            invoice.amountMinor,
                                                            invoice.currency,
                                                        )}
                                                    </dd>
                                                </div>
                                            </dl>
                                        </div>
                                        <a
                                            className="text-sm font-medium underline underline-offset-4"
                                            href={invoice.downloadUrl}
                                        >
                                            {t('billing.download')}
                                        </a>
                                    </article>
                                ))
                            ) : (
                                <p className="text-sm text-[var(--muted)]">
                                    {t('billing.no_invoices')}
                                </p>
                            )}
                        </div>
                    )}
                </Panel>
            </div>
            <Dialog
                open={remove !== null}
                onOpenChange={(open) => {
                    if (!open && !mutation.processing) setRemove(null);
                }}
                title={t('billing.remove_card')}
                description={t('billing.remove_description')}
                footer={
                    <>
                        <Button
                            variant="secondary"
                            disabled={mutation.processing}
                            onClick={() => setRemove(null)}
                        >
                            {t('console.cancel_19766ed6')}
                        </Button>
                        <Button
                            variant="danger"
                            disabled={mutation.processing}
                            onClick={() =>
                                remove &&
                                mutation.delete(removeMethod.url({ method: remove.id }), {
                                    preserveScroll: true,
                                    only: ['paymentMethods', 'sectionErrors', 'errors', 'flash'],
                                    onSuccess: () => setRemove(null),
                                })
                            }
                        >
                            {t('console.remove_c3812fc4')}
                        </Button>
                    </>
                }
            >
                <p>
                    {remove?.brand} •••• {remove?.last4}
                </p>
                {'payment' in mutation.errors && (
                    <p role="alert" className="mt-3 text-sm">
                        {String(mutation.errors.payment)}
                    </p>
                )}
            </Dialog>
            {adding && (
                <AddCard
                    key={organization}
                    organization={organization}
                    onClose={() => setAdding(false)}
                    onSaved={() => {
                        setAdding(false);
                        setNotice('billing.payment_saved');
                        reload();
                    }}
                />
            )}
        </>
    );
}

function AddCard(props: { organization: string; onClose: () => void; onSaved: () => void }) {
    const [attempt, setAttempt] = useState(0);
    return (
        <PaymentForm key={attempt} {...props} onRetry={() => setAttempt((value) => value + 1)} />
    );
}

function PaymentForm({
    organization,
    onClose,
    onSaved,
    onRetry,
}: {
    organization: string;
    onClose: () => void;
    onSaved: () => void;
    onRetry: () => void;
}) {
    const { t } = useTranslator();
    const mount = useRef<HTMLDivElement>(null);
    const state = useRef<{ stripe: Stripe; elements: StripeElements } | null>(null);
    const [ready, setReady] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<MessageKey | null>(null);
    const savedMethod = useRef<string | null>(null);
    useEffect(() => {
        let canceled = false;
        let destroy: (() => void) | undefined;
        const setup = async () => {
            try {
                const intent = await billingRequest<{
                    gateway: string;
                    publishableKey: string;
                    clientSecret: string;
                }>(setupRoute.url(), organization);
                if (canceled) return;
                if (intent.gateway !== 'stripe' || !intent.publishableKey || !intent.clientSecret)
                    throw new Error('unsupported');
                const { loadStripe } = await import('@stripe/stripe-js/pure');
                const stripe = await loadStripe(intent.publishableKey);
                if (canceled) return;
                if (!stripe || !mount.current) throw new Error('payment_unavailable');
                const elements = stripe.elements({
                    clientSecret: intent.clientSecret,
                    appearance: {
                        theme: 'stripe',
                        variables: {
                            colorPrimary: '#b45309',
                            borderRadius: '8px',
                            fontFamily: 'system-ui, sans-serif',
                        },
                    },
                });
                const payment = elements.create('payment', {
                    wallets: { applePay: 'never', googlePay: 'never' },
                });
                destroy = () => payment.destroy();
                payment.on('ready', () => {
                    if (!canceled) setReady(true);
                });
                payment.on('loaderror', () => {
                    if (!canceled) setError('billing.payment_failed');
                });
                payment.mount(mount.current);
                state.current = { stripe, elements };
            } catch {
                if (!canceled) setError('billing.payment_failed');
            }
        };
        void setup();
        return () => {
            canceled = true;
            destroy?.();
            state.current = null;
        };
    }, [organization]);
    const save = async () => {
        if (!state.current || busy) return;
        setBusy(true);
        setError(null);
        try {
            if (!savedMethod.current) {
                const { stripe, elements } = state.current;
                const result = await stripe.confirmSetup({
                    elements,
                    confirmParams: {
                        return_url: `${location.origin}${billingPage.url({}, { query: { organization, setup_return: 1 } })}`,
                    },
                    redirect: 'if_required',
                });
                if (result.error || result.setupIntent?.status !== 'succeeded') {
                    setError('billing.payment_failed');
                    return;
                }
                const method = result.setupIntent.payment_method;
                savedMethod.current = typeof method === 'string' ? method : (method?.id ?? null);
                if (!savedMethod.current) throw new Error('missing_method');
            }
            await billingRequest(completeRoute.url(), organization, {
                payment_method: savedMethod.current,
            });
            onSaved();
        } catch (failure) {
            setError(
                failure instanceof Error && failure.message === '409'
                    ? 'billing.payment_pending'
                    : 'billing.payment_failed',
            );
        } finally {
            setBusy(false);
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
            title={t('billing.add_card')}
            description={t('billing.cards_description')}
            footer={
                <>
                    <Button variant="secondary" disabled={busy} onClick={onClose}>
                        {t('console.cancel_19766ed6')}
                    </Button>
                    <Button disabled={!ready || busy} onClick={() => void save()}>
                        {t(busy ? 'billing.saving' : 'billing.save_card')}
                    </Button>
                </>
            }
        >
            <div className="min-h-28" ref={mount} />
            {!ready && !error && (
                <output className="mt-3 block text-sm">{t('billing.loading_payment')}</output>
            )}
            {error && (
                <div role="alert" className="mt-3 space-y-3 text-sm">
                    <p>{t(error)}</p>
                    {!ready && <Button onClick={onRetry}>{t('billing.retry')}</Button>}
                </div>
            )}
        </Dialog>
    );
}
Billing.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
