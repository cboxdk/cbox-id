import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { useTranslator } from '@/i18n';
import PortalLayout from '@/layouts/PortalLayout';
import type { PageProps } from '@/types';
import { Button, ConfirmDelete, Field, Icon, Pill, RadioGroup, Textarea } from '@/ui';
import { FormError, messageKey, type PortalChrome, PortalHeader } from './parts';

interface Certificate {
    role: 'primary' | 'staged';
    readable: boolean;
    fingerprint_sha256: string | null;
    subject: string | null;
    issuer: string | null;
    not_before: string | null;
    not_after: string | null;
    days_remaining: number | null;
    expired: boolean | null;
}

interface ConnectionRow {
    id: string;
    name: string;
    active: boolean;
    expiresAt: string | null;
    daysRemaining: number | null;
    certificates: Certificate[];
    stageHref: string;
    activateHref: string;
}

type Props = PageProps<{
    portal: PortalChrome;
    connections: ConnectionRow[];
}>;

/** The days at which a certificate reads as "expiring soon" — the alert's widest threshold. */
const SOON = 30;

/**
 * SAML CERTIFICATE RENEWAL — every SAML connection with when it stops working, the
 * certificates it trusts, and the three moves of a renewal with no outage in it: upload
 * (checked, then trusted beside the current one), switch the identity provider, activate.
 */
export default function PortalCertificates({ portal, connections }: Props) {
    const { t } = useTranslator();

    return (
        <div>
            <PortalHeader
                portal={portal}
                title={t('portal.certificates.title')}
                lead={t('portal.certificates.lead')}
            />

            <section className="card p-4 mb-8" aria-labelledby="how-renewal-works">
                <h2 id="how-renewal-works" className="text-sm font-semibold mb-2">
                    {t('portal.certificates.how_heading')}
                </h2>
                <ol className="list-decimal pl-5 space-y-1 text-sm">
                    <li>{t('portal.certificates.how.0')}</li>
                    <li>{t('portal.certificates.how.1')}</li>
                    <li>{t('portal.certificates.how.2')}</li>
                </ol>
            </section>

            {connections.length === 0 ? (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {t('portal.certificates.empty')}
                </p>
            ) : (
                <div className="space-y-8">
                    {connections.map((connection) => (
                        <ConnectionCertificates key={connection.id} connection={connection} />
                    ))}
                </div>
            )}
        </div>
    );
}

function ExpiryPill({ days, expired }: { days: number | null; expired: boolean | null }) {
    const { t, choice } = useTranslator();

    if (days === null) {
        return null;
    }

    if (expired === true) {
        return <Pill tone="destructive">{t('portal.certificates.expired')}</Pill>;
    }

    if (days <= SOON) {
        return (
            <Pill tone="warning">
                {days === 0
                    ? t('portal.certificates.expires_today')
                    : choice('portal.certificates.expires_in', days)}
            </Pill>
        );
    }

    return <Pill tone="success">{t('portal.certificates.healthy')}</Pill>;
}

function ConnectionCertificates({ connection }: { connection: ConnectionRow }) {
    const { t, locale } = useTranslator();
    const { certificateChecks } = usePage().flash;
    const { errors } = usePage().props;
    const [activating, setActivating] = useState<Certificate | null>(null);
    const checks =
        certificateChecks?.connectionId === connection.id ? certificateChecks.checks : null;
    const date = (iso: string | null): string =>
        iso === null ? '—' : new Date(iso).toLocaleDateString(locale, { dateStyle: 'medium' });
    const activateVerb = t('portal.certificates.activate');

    return (
        <section aria-labelledby={`connection-${connection.id}`}>
            <div className="flex flex-wrap items-center gap-2 mb-3">
                <h2 id={`connection-${connection.id}`} className="font-semibold">
                    {connection.name}
                </h2>
                <ExpiryPill
                    days={connection.daysRemaining}
                    expired={connection.daysRemaining !== null && connection.daysRemaining < 0}
                />
            </div>

            <ul className="space-y-2 mb-4">
                {connection.certificates.map((certificate, index) => (
                    <li key={certificate.fingerprint_sha256 ?? index} className="card p-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <span className="flex items-center gap-2 text-sm font-medium">
                                <Icon name="key" className="w-4 h-4" />
                                {certificate.role === 'primary'
                                    ? t('portal.certificates.primary')
                                    : t('portal.certificates.staged')}
                            </span>
                            <ExpiryPill
                                days={certificate.days_remaining}
                                expired={certificate.expired}
                            />
                        </div>
                        {certificate.readable ? (
                            <dl
                                className="mt-2 grid gap-x-3 gap-y-1 text-xs"
                                style={{ gridTemplateColumns: 'auto 1fr' }}
                            >
                                <dt style={{ color: 'var(--muted-foreground)' }}>
                                    {t('portal.certificates.subject')}
                                </dt>
                                <dd className="break-all">{certificate.subject ?? '—'}</dd>
                                <dt style={{ color: 'var(--muted-foreground)' }}>
                                    {t('portal.certificates.valid_until')}
                                </dt>
                                <dd>{date(certificate.not_after)}</dd>
                                <dt style={{ color: 'var(--muted-foreground)' }}>
                                    {t('portal.certificates.fingerprint')}
                                </dt>
                                <dd className="mono break-all">{certificate.fingerprint_sha256}</dd>
                            </dl>
                        ) : (
                            <p
                                className="text-xs mt-2"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {t('portal.certificates.unreadable')}
                            </p>
                        )}
                        {certificate.role === 'staged' &&
                            certificate.fingerprint_sha256 !== null && (
                                <div className="mt-3">
                                    <Button
                                        size="sm"
                                        variant="primary"
                                        aria-label={t('portal.certificates.activate_label', {
                                            name: connection.name,
                                        })}
                                        onClick={() => setActivating(certificate)}
                                    >
                                        {activateVerb}
                                    </Button>
                                </div>
                            )}
                    </li>
                ))}
            </ul>
            <FormError
                message={typeof errors.fingerprint === 'string' ? errors.fingerprint : undefined}
            />

            {checks !== null && checks.length > 0 && (
                <div className="card p-4 mb-4">
                    <p className="text-xs font-semibold mb-2">
                        {t('portal.certificates.checks_heading')}
                    </p>
                    <ul className="space-y-1 text-sm">
                        {checks.map((check) => (
                            <li key={check.check} className="flex items-start gap-2">
                                <Icon
                                    name={check.passed ? 'check' : 'info'}
                                    className="w-3.5 h-3.5 mt-0.5 shrink-0"
                                    style={{
                                        color: check.passed ? 'var(--success)' : 'var(--warning)',
                                    }}
                                />
                                <span>
                                    {check.passed || check.check !== 'valid_now'
                                        ? t(messageKey('portal.certificates.checks', check.check))
                                        : t('portal.certificates.not_yet_valid')}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <UploadForm href={connection.stageHref} />

            <ConfirmDelete
                open={activating !== null}
                onOpenChange={(open) => !open && setActivating(null)}
                name={connection.name}
                verb={activateVerb}
                title={t('portal.certificates.activate_title')}
                consequence={t('portal.certificates.activate_consequence')}
                environment={null}
                cancelLabel={t('portal.confirm.cancel')}
                typeToConfirmLabel={t('portal.confirm.type_to_confirm', { name: connection.name })}
                hint={t('portal.confirm.hint', { action: activateVerb })}
                onConfirm={() => {
                    const certificate = activating;
                    setActivating(null);

                    if (certificate !== null) {
                        router.post(
                            connection.activateHref,
                            { fingerprint: certificate.fingerprint_sha256 },
                            { preserveScroll: true },
                        );
                    }
                }}
            />
        </section>
    );
}

function UploadForm({ href }: { href: string }) {
    const { t } = useTranslator();
    const [mode, setMode] = useState<'metadata' | 'certificate'>('metadata');
    const form = useForm({ metadata: '', certificate: '' });

    return (
        <form
            className="card p-4 space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.transform((data) =>
                    mode === 'metadata'
                        ? { metadata: data.metadata }
                        : { certificate: data.certificate },
                );
                form.post(href, { preserveScroll: true, onSuccess: () => form.reset() });
            }}
        >
            <RadioGroup
                label={t('portal.certificates.upload_heading')}
                value={mode}
                onValueChange={(value) =>
                    setMode(value === 'certificate' ? 'certificate' : 'metadata')
                }
                options={[
                    { value: 'metadata', label: t('portal.certificates.mode_metadata') },
                    { value: 'certificate', label: t('portal.certificates.mode_certificate') },
                ]}
            />
            {mode === 'metadata' ? (
                <Field label={t('portal.certificates.metadata_label')} error={form.errors.metadata}>
                    <Textarea
                        name="metadata"
                        rows={4}
                        className="mono"
                        style={{ fontSize: '0.78rem' }}
                        placeholder="https://"
                        value={form.data.metadata}
                        onChange={(event) => form.setData('metadata', event.target.value)}
                    />
                </Field>
            ) : (
                <Field
                    label={t('portal.certificates.certificate_label')}
                    error={form.errors.certificate}
                >
                    <Textarea
                        name="certificate"
                        rows={6}
                        className="mono"
                        style={{ fontSize: '0.78rem' }}
                        placeholder="-----BEGIN CERTIFICATE-----"
                        value={form.data.certificate}
                        onChange={(event) => form.setData('certificate', event.target.value)}
                    />
                </Field>
            )}
            {mode === 'metadata' && <FormError message={form.errors.certificate} />}
            <Button type="submit" variant="primary" loading={form.processing}>
                {t('portal.certificates.upload')}
            </Button>
        </form>
    );
}

PortalCertificates.layout = (page: React.ReactNode) => <PortalLayout>{page}</PortalLayout>;
