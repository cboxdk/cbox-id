import { useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import { useTranslator } from '@/i18n';
import { useUnsavedChangesGuard } from '@/lib/unsavedChanges';
import type { Stripe, StripeElements } from '@stripe/stripe-js';
import { Button, Combobox, Dialog, Field, Input, Panel } from '@/ui';

export interface PaymentProject {
    id: string;
    name: string;
    previewHref: string;
    confirmHref: string;
    subscription?: {
        plan: string;
        status: string;
        data: { cancel_at_period_end?: boolean; period_end?: string };
    } | null;
}
export interface PaymentsConfig {
    organizationId: string;
    configured: boolean;
    currency: string;
    canManage: boolean;
    accountHref: string;
    detailsHref: string;
    cardHref: string;
    cardUpdateHref: string;
    environmentFeature: string;
    invoiceHref: string;
    countries: Record<string, string>;
    labels: Record<string, string>;
}
type Plan = {
    features: Record<string, number | boolean | string | null>;
    key: string;
    name: string;
    priceMinor: number;
    totalMinor: number | null;
    currency: string;
    interval: string;
};
type Card = {
    id: string;
    brand: string;
    last4: string;
    expMonth: number;
    expYear: number;
    isDefault: boolean;
};
type Invoice = {
    number: string;
    date: string;
    amountMinor: number;
    currency: string;
    status: string;
    taxMinor: number | null;
};
type Account = {
    profile: Record<string, string | boolean | null>;
    plans: Plan[];
    cards: Card[];
    invoices: Invoice[];
};
type Preview = {
    due_now_minor?: number;
    credit_minor?: number;
    credit_delta?: { forfeited?: number; granted?: number; carried?: number };
    new_recurring_minor?: number;
    new_recurring_tax?: { total_minor?: number };
    currency?: string;
    interval?: string;
    effective_at?: string;
    ends_at?: string;
    consequences?: { subscription?: string; message: string }[];
};
type Quote = {
    quote: string;
    preview: Preview;
    operation: string;
    project: PaymentProject;
    currency: string;
    interval?: string;
    returnToPlans: boolean;
};
class PaymentError extends Error {
    constructor(
        message: string,
        readonly fields: Record<string, string> = {},
    ) {
        super(message);
    }
}
const autocomplete: Record<string, string> = {
    invoice_name: 'organization',
    billing_email: 'email',
    address_line_1: 'address-line1',
    address_line_2: 'address-line2',
    postal_code: 'postal-code',
    city: 'address-level2',
    subdivision: 'address-level1',
};
const fields = {
    invoice_name: 'name',
    billing_email: 'billingEmail',
    address_line_1: 'addressLine1',
    address_line_2: 'addressLine2',
    postal_code: 'postalCode',
    city: 'city',
    subdivision: 'subdivision',
    tax_id: 'taxId',
};

export default function Payments({
    config,
    projects,
    locale,
}: {
    config: PaymentsConfig;
    projects: PaymentProject[];
    locale: string;
}) {
    const l = config.labels;
    const { t } = useTranslator();
    const [account, setAccount] = useState<Account | null>(null);
    const [details, setDetails] = useState<Record<string, string>>({});
    const [savedDetails, setSavedDetails] = useState<Record<string, string>>({});
    const guard = useUnsavedChangesGuard(JSON.stringify(details) !== JSON.stringify(savedDetails));
    const [error, setError] = useState('');
    const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
    const detailsForm = useRef<HTMLFormElement>(null);
    useEffect(() => {
        detailsForm.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
    }, [fieldErrors]);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState('');
    const [quote, setQuote] = useState<Quote | null>(null);
    const [project, setProject] = useState<PaymentProject | null>(null);
    const [cardOpen, setCardOpen] = useState(false);
    const [cardAttempt, setCardAttempt] = useState(0);
    const [cardReady, setCardReady] = useState(false);
    const cardMount = useRef<HTMLDivElement>(null);
    const stripe = useRef<Stripe | null>(null);
    const elements = useRef<StripeElements | null>(null);
    const money = (amount: number, currency: string) =>
        new Intl.NumberFormat(locale, { style: 'currency', currency }).format(amount / 100);
    async function post<T>(url: string, body: object = {}): Promise<T> {
        const isRead = url === config.accountHref || url === config.invoiceHref;
        const target = new URL(url, window.location.origin);
        if (isRead)
            Object.entries(body).forEach(([key, value]) =>
                target.searchParams.set(key, String(value)),
            );
        const response = await fetch(target, {
            method: isRead ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ??
                    '',
            },
            body: isRead ? undefined : JSON.stringify(body),
        });
        const data = await response.json().catch(() => {
            throw new PaymentError(l.unavailable);
        });
        if (!response.ok)
            throw new PaymentError(
                data.errors
                    ? Object.values(data.errors).flat().join(' ')
                    : (data.message ?? l.unavailable),
                Object.fromEntries(
                    Object.entries(data.errors ?? {}).map(([key, value]) => [
                        key,
                        Array.isArray(value) ? value.join(' ') : String(value),
                    ]),
                ),
            );
        return data as T;
    }
    async function run(action: () => Promise<void>) {
        if (busy) return;
        setBusy(true);
        setError('');
        setFieldErrors({});
        setNotice('');
        try {
            await action();
        } catch (e) {
            setError(e instanceof PaymentError ? e.message : l.unavailable);
            if (e instanceof PaymentError) setFieldErrors(e.fields);
        } finally {
            setBusy(false);
        }
    }
    async function load(resetDetails = false) {
        const data = await post<Account>(config.accountHref);
        setAccount(data);
        if (resetDetails) {
            const profile = Object.fromEntries([
                ...Object.entries(fields).map(([key, value]) => [
                    key,
                    String(data.profile[value] ?? ''),
                ]),
                ['country', String(data.profile.country ?? '')],
            ]);
            setDetails(profile);
            setSavedDetails(profile);
        }
    }
    useEffect(() => {
        if (!config.configured) return;
        void run(async () => {
            const query = new URLSearchParams(window.location.search);
            const returned = query.get('setup_intent') !== null;
            if (returned) {
                window.history.replaceState({}, '', window.location.pathname);
                await post(config.cardHref, { operation: 'added' });
            }
            await load(true);
            if (query.get('add_card') === '1') {
                window.history.replaceState({}, '', window.location.pathname);
                setCardOpen(true);
            }
        });
        // Account belongs to this organization-bound page; navigation remounts it.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [config.accountHref]);
    useEffect(() => {
        if (!cardOpen) {
            elements.current = null;
            stripe.current = null;
            setCardReady(false);
            return;
        }
        let disposed = false;
        let destroy: (() => void) | undefined;
        void run(async () => {
            const { intent } = await post<{
                intent: { gateway: string; publishableKey: string; clientSecret: string };
            }>(config.cardHref, { operation: 'setup' });
            if (disposed) return;
            if (intent.gateway !== 'stripe' || !intent.publishableKey || !intent.clientSecret)
                throw new PaymentError(l.unsupported_gateway);
            const { loadStripe } = await import('@stripe/stripe-js/pure');
            const provider = await loadStripe(intent.publishableKey);
            if (disposed || !cardMount.current) return;
            if (!provider) throw new PaymentError(l.unavailable);
            stripe.current = provider;
            elements.current = provider.elements({ clientSecret: intent.clientSecret });
            const element = elements.current.create('payment', {
                wallets: { applePay: 'never', googlePay: 'never' },
            });
            element.on('ready', () => {
                if (!disposed) setCardReady(true);
            });
            element.on('loaderror', () => {
                if (!disposed) setError(l.unavailable);
            });
            element.mount(cardMount.current);
            destroy = () => element.destroy();
        });
        return () => {
            disposed = true;
            destroy?.();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [cardOpen, cardAttempt]);
    async function review(p: PaymentProject, operation: string, plan?: Plan) {
        await run(async () => {
            const result = await post<Omit<Quote, 'project' | 'currency'>>(p.previewHref, {
                operation,
                plan: plan?.key,
            });
            setQuote({
                ...result,
                returnToPlans: plan !== undefined,
                project: p,
                currency:
                    result.preview.currency ??
                    plan?.currency ??
                    account?.plans.find((v) => v.key === p.subscription?.plan)?.currency ??
                    config.currency,
                interval: result.preview.interval ?? plan?.interval,
            });
            setProject(null);
        });
    }
    if (!config.configured)
        return (
            <Panel title={l.manage} className="mt-4">
                <p>{l.not_configured}</p>
            </Panel>
        );
    return (
        <div className="mt-4 space-y-4">
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
            {error && (
                <div role="alert" className="rounded border border-[var(--danger)] p-4">
                    <p>{error}</p>
                    <Button
                        variant="secondary"
                        onClick={() => void run(() => load(account === null))}
                        disabled={busy}
                    >
                        {l.retry}
                    </Button>
                </div>
            )}
            <output className={notice ? 'block' : undefined}>{notice}</output>
            {!config.canManage && <p>{l.read_only}</p>}
            {!account ? (
                <output className="block">{busy ? l.loading : l.unavailable}</output>
            ) : (
                <>
                    <Panel title={l.plans}>
                        {projects.map((p) => (
                            <div
                                key={p.id}
                                className="flex flex-wrap items-center justify-between gap-3 border-b border-[var(--border)] py-3"
                            >
                                <div>
                                    <p className="font-medium break-all">{p.name}</p>
                                    <p className="text-sm">
                                        {account.plans.find((v) => v.key === p.subscription?.plan)
                                            ?.name ??
                                            p.subscription?.plan ??
                                            l.no_subscription}{' '}
                                        {p.subscription?.status}
                                    </p>
                                    {p.subscription?.data.cancel_at_period_end && (
                                        <p>
                                            {l.ends}:{' '}
                                            {p.subscription.data.period_end
                                                ? new Date(
                                                      p.subscription.data.period_end,
                                                  ).toLocaleDateString(locale)
                                                : '—'}
                                        </p>
                                    )}
                                </div>
                                {config.canManage && (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            variant="secondary"
                                            disabled={busy}
                                            onClick={() => setProject(p)}
                                        >
                                            {l.plans}
                                        </Button>
                                        {p.subscription &&
                                            ['active', 'trialing'].includes(
                                                p.subscription.status,
                                            ) && (
                                                <Button
                                                    variant="ghost"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        void review(
                                                            p,
                                                            p.subscription?.data
                                                                .cancel_at_period_end
                                                                ? 'reactivate'
                                                                : 'cancel',
                                                        )
                                                    }
                                                >
                                                    {p.subscription.data.cancel_at_period_end
                                                        ? l.reactivate
                                                        : l.cancel}
                                                </Button>
                                            )}
                                    </div>
                                )}
                            </div>
                        ))}
                    </Panel>
                    <Panel title={l.details}>
                        <form
                            ref={detailsForm}
                            onSubmit={(e) => {
                                e.preventDefault();
                                void run(async () => {
                                    await post(config.detailsHref, details);
                                    await load(true);
                                    setNotice(l.saved);
                                });
                            }}
                            className="grid gap-4 sm:grid-cols-2"
                        >
                            {Object.keys(fields).map((key) => (
                                <Field key={key} label={l[key]} error={fieldErrors[key]}>
                                    <Input
                                        name={key}
                                        autoComplete={autocomplete[key]}
                                        value={details[key] ?? ''}
                                        type={key === 'billing_email' ? 'email' : 'text'}
                                        disabled={!config.canManage || busy}
                                        onChange={(e) =>
                                            setDetails({ ...details, [key]: e.target.value })
                                        }
                                    />
                                </Field>
                            ))}
                            <Field label={l.country} error={fieldErrors.country}>
                                <Combobox
                                    value={details.country}
                                    onValueChange={(value) =>
                                        setDetails({ ...details, country: value })
                                    }
                                    disabled={!config.canManage || busy}
                                    options={Object.entries(config.countries).map(
                                        ([value, label]) => ({ value, label }),
                                    )}
                                />
                            </Field>
                            {account.profile.taxId && (
                                <p>
                                    {account.profile.taxIdValidated
                                        ? l.tax_validated
                                        : l.tax_unverified}
                                </p>
                            )}
                            {config.canManage && (
                                <div className="sm:col-span-2">
                                    <Button type="submit" loading={busy}>
                                        {l.save}
                                    </Button>
                                </div>
                            )}
                        </form>
                    </Panel>
                    <Panel title={l.cards}>
                        {account.cards.length === 0 && <p>{l.none}</p>}
                        {account.cards.map((card) => (
                            <div
                                key={card.id}
                                className="flex flex-wrap items-center justify-between gap-3 py-2"
                            >
                                <span>
                                    {card.brand} •••• {card.last4} — {card.expMonth}/{card.expYear}{' '}
                                    {card.isDefault && `(${l.default})`}
                                </span>
                                {config.canManage && !card.isDefault && (
                                    <div className="flex flex-wrap gap-2">
                                        {(['default', 'remove'] as const).map((operation) => (
                                            <Button
                                                key={operation}
                                                variant="secondary"
                                                disabled={busy}
                                                onClick={() =>
                                                    void run(async () => {
                                                        await post(config.cardUpdateHref, {
                                                            operation,
                                                            card: card.id,
                                                        });
                                                        await load();
                                                    })
                                                }
                                            >
                                                {operation === 'default'
                                                    ? l.make_default
                                                    : l.remove}
                                            </Button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        ))}
                        {config.canManage && (
                            <Button
                                variant="secondary"
                                disabled={busy}
                                onClick={() => {
                                    window.location.assign(
                                        `${window.location.pathname}?add_card=1`,
                                    );
                                }}
                            >
                                {l.add_card}
                            </Button>
                        )}
                    </Panel>
                    <Panel title={l.invoices}>
                        {account.invoices.length === 0 ? (
                            <p>{l.none}</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-left text-sm">
                                    <thead>
                                        <tr>
                                            <th>{l.invoices}</th>
                                            <th>{l.total}</th>
                                            <th>{l.tax}</th>
                                            <th>{l.status}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {account.invoices.map((i) => (
                                            <tr key={i.number}>
                                                <td className="py-2">
                                                    <Button
                                                        variant="ghost"
                                                        disabled={busy}
                                                        onClick={() =>
                                                            void run(async () => {
                                                                const result = await post<{
                                                                    url: string;
                                                                }>(config.invoiceHref, {
                                                                    number: i.number,
                                                                });
                                                                window.location.assign(result.url);
                                                            })
                                                        }
                                                    >
                                                        {i.number} (PDF)
                                                    </Button>
                                                </td>
                                                <td>{money(i.amountMinor, i.currency)}</td>
                                                <td>
                                                    {i.taxMinor === null
                                                        ? '—'
                                                        : money(i.taxMinor, i.currency)}
                                                </td>
                                                <td>{i.status}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Panel>
                </>
            )}
            <Dialog
                open={project !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) setProject(null);
                }}
                title={l.plans}
                description={project?.name}
            >
                <div className="space-y-4">
                    {error && <p role="alert">{error}</p>}
                    {account?.plans.length === 0 && <p>{l.not_configured}</p>}
                    {account?.plans.map((plan) => (
                        <div key={plan.key} className="rounded border border-[var(--border)] p-4">
                            <p className="font-medium">{plan.name}</p>
                            {typeof plan.features[config.environmentFeature] === 'number' && (
                                <p>
                                    {plan.features[config.environmentFeature]} {l.environments}
                                </p>
                            )}
                            <p>
                                {money(plan.totalMinor ?? plan.priceMinor, plan.currency)} /{' '}
                                {plan.interval}
                            </p>
                            {plan.totalMinor === null && <p>{l.tax_pending}</p>}
                            <Button
                                disabled={busy}
                                onClick={() => project && void review(project, 'plan', plan)}
                            >
                                {l.select}
                            </Button>
                        </div>
                    ))}
                </div>
            </Dialog>
            <Dialog
                open={quote !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) setQuote(null);
                }}
                title={l.review}
                description={l.review_description}
                footer={
                    <>
                        <Button
                            variant="secondary"
                            disabled={busy}
                            onClick={() => {
                                setError('');
                                if (quote?.returnToPlans) setProject(quote.project);
                                setQuote(null);
                            }}
                        >
                            {l.back}
                        </Button>
                        <Button
                            loading={busy}
                            onClick={() =>
                                quote &&
                                void run(async () => {
                                    const result = await post<{ url?: string }>(
                                        quote.project.confirmHref,
                                        { quote: quote.quote },
                                    );
                                    if (result.url) {
                                        window.location.assign(result.url);
                                        return;
                                    }
                                    setQuote(null);
                                    setNotice(l.saved);
                                    await load();
                                    router.reload({ only: ['projects'] });
                                })
                            }
                        >
                            {quote?.operation === 'checkout' ? l.checkout : l.confirm}
                        </Button>
                    </>
                }
            >
                {quote && (
                    <div className="space-y-3">
                        <p className="font-medium">{quote.project.name}</p>
                        {quote.preview.due_now_minor !== undefined && (
                            <p>
                                {l.due}: {money(quote.preview.due_now_minor, quote.currency)}
                            </p>
                        )}
                        {quote.preview.new_recurring_minor !== undefined && (
                            <p>
                                {l.recurring}:{' '}
                                {money(
                                    quote.preview.new_recurring_tax?.total_minor ??
                                        quote.preview.new_recurring_minor,
                                    quote.currency,
                                )}{' '}
                                {quote.interval && `/ ${quote.interval}`}{' '}
                                {quote.operation === 'change' &&
                                    quote.preview.new_recurring_tax?.total_minor === undefined &&
                                    l.tax_pending}
                            </p>
                        )}
                        {(quote.preview.credit_minor ?? 0) > 0 && (
                            <p>
                                {l.credit}: {money(quote.preview.credit_minor!, quote.currency)}
                            </p>
                        )}
                        {(['forfeited', 'granted', 'carried'] as const).map((kind) => {
                            const amount = quote.preview.credit_delta?.[kind] ?? 0;
                            return amount > 0 ? (
                                <p key={kind}>
                                    {l[`credits_${kind}`]}:{' '}
                                    {new Intl.NumberFormat(locale).format(amount)}
                                </p>
                            ) : null;
                        })}
                        {(quote.preview.effective_at || quote.preview.ends_at) && (
                            <p>
                                {l.effective}:{' '}
                                {new Date(
                                    (quote.preview.effective_at ?? quote.preview.ends_at)!,
                                ).toLocaleString(locale)}
                            </p>
                        )}
                        {quote.preview.consequences?.map((c) => (
                            <p key={c.subscription ?? c.message}>{c.message}</p>
                        ))}
                        {error && <p role="alert">{error}</p>}
                    </div>
                )}
            </Dialog>
            <Dialog
                open={cardOpen}
                onOpenChange={(open) => {
                    if (!busy) setCardOpen(open);
                }}
                title={l.add_card}
                description={l.cards}
                footer={
                    <Button
                        disabled={!cardReady}
                        loading={busy}
                        onClick={() =>
                            void run(async () => {
                                if (!stripe.current || !elements.current) return;
                                const result = await stripe.current.confirmSetup({
                                    elements: elements.current,
                                    confirmParams: {
                                        return_url:
                                            window.location.origin + window.location.pathname,
                                    },
                                    redirect: 'if_required',
                                });
                                if (result.error)
                                    throw new PaymentError(result.error.message ?? l.unavailable);
                                await post(config.cardHref, { operation: 'added' });
                                setCardOpen(false);
                                await load();
                                setNotice(l.saved);
                            })
                        }
                    >
                        {l.save}
                    </Button>
                }
            >
                {!cardReady && !error && <output className="block">{l.loading}</output>}
                <div ref={cardMount} />
                {error && (
                    <div role="alert">
                        <p>{error}</p>
                        {!cardReady && (
                            <Button
                                disabled={busy}
                                onClick={() => setCardAttempt((attempt) => attempt + 1)}
                            >
                                {l.retry}
                            </Button>
                        )}
                    </div>
                )}
            </Dialog>
        </div>
    );
}
