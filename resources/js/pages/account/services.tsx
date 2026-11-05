import { Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useTranslator, type MessageKey } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Field, Input, PageHeader, Panel, Pill, Textarea } from '@/ui';

type Service = {
    id: number;
    key: string;
    name: string;
    description: string | null;
    mode: 'waitlist' | 'paused' | 'invite_only' | 'open';
    questions: { key: string; label: string; required: boolean }[];
    publicUrl: string;
    status: 'waiting' | 'invited' | 'accepted' | null;
    joinHref: string;
    reference: string;
};

type Props = PageProps<{
    services: Service[];
    unavailable: boolean;
    email: string | null;
    emailVerified: boolean;
    accountHref: string;
    allHref: string | null;
    previousHref: string | null;
    nextHref: string | null;
}>;

const statusKeys: Record<NonNullable<Service['status']>, MessageKey> = {
    waiting: 'service_access.waiting',
    invited: 'service_access.invited',
    accepted: 'service_access.accepted',
};

export default function Services({
    services,
    unavailable,
    email,
    emailVerified,
    accountHref,
    allHref,
    previousHref,
    nextHref,
}: Props) {
    const { t } = useTranslator();
    return (
        <div className="space-y-6">
            <PageHeader description={t('service_access.lead')} />
            {allHref !== null && (
                <Link href={allHref} className="text-sm underline">
                    {t('service_access.all')}
                </Link>
            )}
            {!emailVerified && (
                <Panel>
                    <p className="text-sm">{t('service_access.verify_email')}</p>
                    <Button asChild variant="secondary" className="mt-3">
                        <Link href={accountHref}>{t('service_access.verify_action')}</Link>
                    </Button>
                </Panel>
            )}
            {unavailable ? (
                <Panel>
                    <p role="alert">{t('service_access.unavailable')}</p>
                </Panel>
            ) : services.length === 0 ? (
                <Panel>
                    <p>{t('service_access.empty')}</p>
                </Panel>
            ) : (
                <div className="grid items-start gap-5 lg:grid-cols-2">
                    {services.map((service) => (
                        <ServiceCard
                            key={`${service.id}-${service.status ?? 'new'}`}
                            service={service}
                            canJoin={emailVerified && email !== null}
                            email={email}
                        />
                    ))}
                </div>
            )}
            <nav className="flex flex-wrap gap-3" aria-label={t('service_access.title')}>
                {previousHref !== null && (
                    <Button asChild>
                        <Link href={previousHref}>{t('service_access.previous')}</Link>
                    </Button>
                )}
                {nextHref !== null && (
                    <Button asChild>
                        <Link href={nextHref}>{t('service_access.next')}</Link>
                    </Button>
                )}
            </nav>
        </div>
    );
}

function ServiceCard({
    service,
    canJoin,
    email,
}: {
    service: Service;
    canJoin: boolean;
    email: string | null;
}) {
    const { t } = useTranslator();
    const form = useForm({
        country: '',
        answers: {} as Record<string, string>,
        reference: service.reference,
    });
    const errors: Record<string, string | undefined> = form.errors;
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(service.joinHref, { preserveScroll: true, errorBag: `waitlist-${service.id}` });
    }
    return (
        <Panel
            id={`service-${service.key}`}
            title={service.name}
            description={service.description ?? undefined}
        >
            {service.status !== null ? (
                <div className="space-y-3">
                    <Pill tone={service.status === 'accepted' ? 'success' : 'neutral'}>
                        {t(statusKeys[service.status])}
                    </Pill>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {t('service_access.email_notice')}
                    </p>
                </div>
            ) : service.mode !== 'waitlist' ? (
                <p className="text-sm">{t('service_access.closed')}</p>
            ) : (
                <form onSubmit={submit} className="space-y-4">
                    {email !== null && (
                        <p
                            className="break-words text-sm"
                            style={{ color: 'var(--muted-foreground)' }}
                        >
                            {t('service_access.signed_in_as', { email })}
                        </p>
                    )}
                    <Field label={t('service_access.country')} error={errors.country}>
                        <Input
                            value={form.data.country}
                            onChange={(event) =>
                                form.setData('country', event.target.value.toUpperCase())
                            }
                            maxLength={2}
                            pattern="[A-Za-z]{2}"
                            autoComplete="country"
                            disabled={!canJoin}
                        />
                    </Field>
                    {service.questions.map((question) => (
                        <Field
                            key={question.key}
                            label={question.label}
                            required={question.required}
                            error={errors[`answers.${question.key}`]}
                        >
                            <Textarea
                                value={form.data.answers[question.key] ?? ''}
                                onChange={(event) =>
                                    form.setData('answers', {
                                        ...form.data.answers,
                                        [question.key]: event.target.value,
                                    })
                                }
                                maxLength={2000}
                                rows={3}
                                disabled={!canJoin}
                            />
                        </Field>
                    ))}
                    {(errors.waitlist ?? errors.answers) && (
                        <p role="alert" className="field-error">
                            {errors.waitlist ?? errors.answers}
                        </p>
                    )}
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {t('service_access.scope_note')}
                    </p>
                    <Button
                        type="submit"
                        variant="primary"
                        disabled={!canJoin}
                        loading={form.processing}
                    >
                        {t('service_access.join')}
                    </Button>
                </form>
            )}
            <a
                href={service.publicUrl}
                className="mt-5 inline-block text-sm underline"
                rel="noreferrer"
            >
                {t('service_access.public_page')}
            </a>
        </Panel>
    );
}

Services.layout = (page: ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
