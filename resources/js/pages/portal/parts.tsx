import { Link, router, useForm, usePage } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';
import { type MessageKey, useTranslator } from '@/i18n';
import {
    Button,
    ConfirmDelete,
    CopyButton,
    Icon,
    Input,
    PageHeader,
    Pill,
    ProviderMark,
} from '@/ui';

/**
 * WHAT EVERY ADMIN PORTAL PAGE IS BUILT FROM — the way back to the checklist, a numbered
 * step, a value to copy into somebody else's admin screen, a guide's steps, a provider to
 * pick, and the organization's domains.
 *
 * The portal is opened by a stranger to this platform, usually with their identity
 * provider's admin console open in the other half of the screen. So every value they have
 * to carry across is a row of its own with the NAME THEIR SCREEN GIVES THE FIELD beside it
 * and a copy button on it, and every step says what it is waiting for.
 */

export interface PortalChrome {
    organizationName: string | null;
    homeHref: string;
}

export interface GuideField {
    ours: string;
    /** THEIR label for the field, verbatim from the identity provider's screen — never translated. */
    theirs: string;
    literal?: string;
    /** A field their screen accepts but does not require — our Single Logout URL, say. */
    optional?: boolean;
    /** Where on their screen it sits, when the label alone is ambiguous — also verbatim. */
    location?: string;
}

/** The page's heading, under a link back to the checklist. */
export function PortalHeader({
    portal,
    title,
    lead,
}: {
    portal: PortalChrome;
    title: string;
    lead: string;
}) {
    const { t } = useTranslator();

    return (
        <>
            <Link
                href={portal.homeHref}
                className="text-sm inline-flex items-center gap-1 mb-3"
                style={{ color: 'var(--muted-foreground)' }}
            >
                <Icon name="arrow-left" className="w-3.5 h-3.5" /> {t('portal.nav.back')}
            </Link>
            <div className="mb-8">
                <PageHeader eyebrow={portal.organizationName} title={title} description={lead} />
            </div>
        </>
    );
}

/**
 * One numbered step. `done` draws a tick instead of the number, so a page that is half
 * finished reads as half finished at a glance.
 */
export function Step({
    number,
    title,
    done = false,
    children,
}: {
    number: number;
    title: string;
    done?: boolean;
    children: ReactNode;
}) {
    const { t } = useTranslator();

    return (
        <section className="mb-8" aria-labelledby={`step-${number}`}>
            <div className="flex items-start gap-3 mb-3">
                <span
                    aria-hidden="true"
                    className="inline-flex items-center justify-center rounded-full shrink-0 text-xs font-semibold"
                    style={{
                        width: '1.6rem',
                        height: '1.6rem',
                        background: done
                            ? 'var(--success-soft, var(--accent-soft))'
                            : 'var(--secondary)',
                        color: done ? 'var(--success-strong, var(--accent))' : 'var(--foreground)',
                        border: '1px solid var(--border)',
                    }}
                >
                    {done ? <Icon name="check" className="w-3.5 h-3.5" /> : number}
                </span>
                <div style={{ minWidth: 0 }}>
                    <p className="cbx-page-eyebrow">{t('portal.nav.step', { number })}</p>
                    <h2 id={`step-${number}`} className="text-sm font-semibold mt-0.5">
                        {title}
                    </h2>
                </div>
            </div>
            <div className="sm:pl-9">{children}</div>
        </section>
    );
}

/**
 * A value to carry into another admin screen: what THEIR screen calls the field, the value
 * in full (selectable, wrapping — never truncated, a truncated URL pasted is a broken one),
 * and a copy button that says which value it copies.
 */
export function ValueRow({ field, value }: { field: string; value: string }) {
    const { t } = useTranslator();

    return (
        <div className="py-2.5 border-t first:border-t-0" style={{ borderColor: 'var(--border)' }}>
            <p className="text-xs font-medium" style={{ color: 'var(--muted-foreground)' }}>
                {field}
            </p>
            <div className="mt-1 flex items-start gap-2">
                <code
                    className="mono text-xs rounded-lg px-3 py-2 select-all break-all flex-1 min-w-0"
                    style={{ background: 'var(--secondary)', border: '1px solid var(--border)' }}
                >
                    {value}
                </code>
                <CopyButton
                    value={value}
                    aria-label={`${t('portal.copy.copy')}: ${field}`}
                    label={t('portal.copy.copy')}
                    copiedLabel={t('portal.copy.copied')}
                    failedLabel={t('portal.copy.failed')}
                />
            </div>
        </div>
    );
}

/**
 * A guide's field list, resolved against the values this organization has: each of their
 * fields with the value of ours that goes in it, or the fixed value everybody uses.
 */
export function GuideValues({
    fields,
    values,
    lead,
}: {
    fields: GuideField[];
    values: Record<string, string | undefined>;
    lead: string;
}) {
    const { t } = useTranslator();
    const rows = fields
        .map((field) => ({
            /*
             * Their label, under the tab or section it sits in when the label alone is
             * ambiguous (PingFederate calls two fields "Endpoint URL"), and marked when
             * their screen does not require it. The label and the location are the
             * identity provider's words and stay as they are; only "optional" is ours.
             */
            field:
                (field.location === undefined ? '' : `${field.location} → `) +
                field.theirs +
                (field.optional === true ? ` (${t('portal.sso.optional')})` : ''),
            value: field.ours === 'literal' ? field.literal : values[field.ours],
            literal: field.ours === 'literal',
        }))
        .filter(
            (row): row is { field: string; value: string; literal: boolean } =>
                row.value !== undefined,
        );

    return (
        <div className="card p-4 mb-4">
            <p className="text-sm mb-1">{lead}</p>
            {rows.map((row, index) =>
                row.literal ? (
                    <div
                        key={`${index}-${row.field}`}
                        className="py-2.5 border-t flex flex-wrap items-baseline gap-x-2"
                        style={{ borderColor: 'var(--border)' }}
                    >
                        <span
                            className="text-xs font-medium"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {row.field}
                        </span>
                        <span className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                            {t('portal.sso.set_to')}
                        </span>
                        <code className="mono text-xs break-all">{row.value}</code>
                    </div>
                ) : (
                    <ValueRow key={`${index}-${row.field}`} field={row.field} value={row.value} />
                ),
            )}
        </div>
    );
}

/** A guide's steps, numbered, with the provider's own documentation when there is some. */
export function GuideSteps({
    steps,
    docs,
    provider,
}: {
    steps: string[];
    docs: string | null;
    provider: string;
}) {
    const { t } = useTranslator();

    return (
        <div className="mb-4">
            <p className="text-xs font-semibold mb-2">{t('portal.sso.steps_heading')}</p>
            <ol
                className="list-decimal pl-5 space-y-1.5 text-sm"
                style={{ color: 'var(--foreground)' }}
            >
                {steps.map((step) => (
                    <li key={step}>{step}</li>
                ))}
            </ol>
            {docs !== null && (
                <a
                    href={docs}
                    target="_blank"
                    rel="noreferrer noopener"
                    className="mt-2 inline-flex items-center gap-1 text-xs"
                    style={{ color: 'var(--accent)' }}
                >
                    {t('portal.nav.docs', { provider })}{' '}
                    <Icon name="external" className="w-3 h-3" />
                </a>
            )}
        </div>
    );
}

/**
 * The providers to pick from, as links rather than a form: choosing one is navigation —
 * the choice lives in the URL, so it survives a reload and the Back button means "choose
 * again".
 */
export function ProviderPicker({
    providers,
    href,
    label,
}: {
    providers: { key: string; name: string }[];
    href: string;
    label: string;
}) {
    return (
        <nav aria-label={label}>
            <ul className="grid gap-2 sm:grid-cols-2">
                {providers.map((provider) => (
                    <li key={provider.key}>
                        <Link
                            href={`${href}?provider=${encodeURIComponent(provider.key)}`}
                            preserveScroll
                            className="card p-3 flex items-center gap-3 text-sm font-medium"
                            style={{ textDecoration: 'none', color: 'var(--foreground)' }}
                        >
                            <ProviderMark provider={providerMarkKey(provider.key)} size={20} />
                            <span>{provider.name}</span>
                        </Link>
                    </li>
                ))}
            </ul>
        </nav>
    );
}

/** The chosen provider, with the way to choose again. */
export function ChosenProvider({
    providerKey,
    name,
    href,
    changeLabel,
}: {
    providerKey: string;
    name: string;
    href: string;
    changeLabel: string;
}) {
    return (
        <div className="card p-3 flex flex-wrap items-center justify-between gap-3">
            <span className="flex items-center gap-3 text-sm font-medium">
                <ProviderMark provider={providerMarkKey(providerKey)} size={20} />
                {name}
            </span>
            <Link href={href} preserveScroll className="text-xs" style={{ color: 'var(--accent)' }}>
                {changeLabel}
            </Link>
        </div>
    );
}

/** Our guide keys, as the shared mark registry knows the same vendors. */
function providerMarkKey(key: string): string {
    return key === 'entra' ? 'microsoft' : key;
}

export interface DomainRow {
    id: string;
    domain: string;
    verified: boolean;
    verifiedAt: string | null;
    recordHost: string;
    recordValue: string;
    verifyHref: string;
    removeHref: string;
}

/**
 * The organization's domains: add one, publish the TXT record it shows (both halves with a
 * copy button), check it, see it verified. The record is shown for every pending domain,
 * not only the one just added — it is public DNS, and somebody whose DNS change went live
 * an hour later must not have to start over.
 */
export function DomainManager({ domains, addHref }: { domains: DomainRow[]; addHref: string }) {
    const form = useForm({ domain: '' });
    const [removing, setRemoving] = useState<DomainRow | null>(null);
    const { errors } = usePage().props;
    const actionError =
        typeof errors.domain === 'string' && form.errors.domain === undefined
            ? errors.domain
            : null;
    const { t, rich } = useTranslator();
    const removeVerb = t('portal.domains.remove');

    return (
        <div>
            <form
                className="flex flex-wrap gap-2 mb-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(addHref, { preserveScroll: true, onSuccess: () => form.reset() });
                }}
            >
                <Input
                    name="domain"
                    placeholder="acme.com"
                    className="flex-1"
                    style={{ minWidth: '12rem' }}
                    aria-label={t('portal.domains.label')}
                    aria-invalid={form.errors.domain !== undefined || undefined}
                    value={form.data.domain}
                    onChange={(event) => form.setData('domain', event.target.value)}
                />
                <Button type="submit" variant="primary" loading={form.processing}>
                    {t('portal.domains.add')}
                </Button>
            </form>

            {(form.errors.domain ?? actionError) !== null &&
                (form.errors.domain ?? actionError) !== undefined && (
                    <p className="field-error mb-3" role="alert">
                        {form.errors.domain ?? actionError}
                    </p>
                )}

            <p className="text-xs mb-4" style={{ color: 'var(--muted-foreground)' }}>
                {t('portal.domains.dns_hint')}
            </p>

            {domains.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {t('portal.domains.empty')}
                </p>
            ) : (
                <ul className="space-y-3">
                    {domains.map((domain) => (
                        <li key={domain.id} className="card p-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <span className="mono text-sm font-medium break-all">
                                    {domain.domain}
                                </span>
                                <div className="flex items-center gap-2">
                                    {domain.verified ? (
                                        <Pill tone="success">{t('portal.domains.verified')}</Pill>
                                    ) : (
                                        <>
                                            <Pill tone="warning">
                                                {t('portal.domains.pending')}
                                            </Pill>
                                            <Button
                                                size="sm"
                                                aria-label={t('portal.domains.check_label', {
                                                    domain: domain.domain,
                                                })}
                                                onClick={() =>
                                                    router.post(
                                                        domain.verifyHref,
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                {t('portal.domains.check')}
                                            </Button>
                                        </>
                                    )}
                                    <Button
                                        size="sm"
                                        variant="danger"
                                        aria-label={t('portal.domains.remove_label', {
                                            domain: domain.domain,
                                        })}
                                        onClick={() => setRemoving(domain)}
                                    >
                                        {removeVerb}
                                    </Button>
                                </div>
                            </div>

                            {!domain.verified && (
                                <div className="mt-3">
                                    <p
                                        className="text-xs mb-1"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {t('portal.domains.record_lead')}
                                    </p>
                                    <div className="py-1.5 text-xs flex gap-2">
                                        <span style={{ color: 'var(--muted-foreground)' }}>
                                            {t('portal.domains.record_type')}
                                        </span>
                                        <code className="mono">TXT</code>
                                    </div>
                                    <ValueRow
                                        field={t('portal.domains.record_host')}
                                        value={domain.recordHost}
                                    />
                                    <ValueRow
                                        field={t('portal.domains.record_value')}
                                        value={domain.recordValue}
                                    />
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {/*
                Type-to-confirm: removing a verified domain stops routing everybody at it to
                single sign-on, and the person doing it is a third party who may not be the
                one who notices.
            */}
            <ConfirmDelete
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                name={removing?.domain ?? ''}
                verb={removeVerb}
                title={t('portal.domains.remove_title', { domain: removing?.domain ?? '' })}
                consequence={t('portal.domains.remove_consequence')}
                environment={null}
                cancelLabel={t('portal.confirm.cancel')}
                typeToConfirmLabel={rich('portal.confirm.type_to_confirm', {
                    name: <span className="mono">{removing?.domain ?? ''}</span>,
                })}
                hint={t('portal.confirm.hint', { action: removeVerb })}
                onConfirm={() => {
                    const domain = removing;
                    setRemoving(null);

                    if (domain !== null) {
                        router.delete(domain.removeHref, { preserveScroll: true });
                    }
                }}
            />
        </div>
    );
}

/** A refusal or a field error that has no field on the page, announced. */
export function FormError({ message }: { message: string | undefined }) {
    if (message === undefined || message === '') {
        return null;
    }

    return (
        <p className="field-error mt-2" role="alert">
            {message}
        </p>
    );
}

/** A message key the translator can be handed, from a value the server sent. */
export function messageKey(prefix: string, value: string): MessageKey {
    return `${prefix}.${value}` as MessageKey;
}
