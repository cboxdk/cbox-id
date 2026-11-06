import { Link, router, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import { useTranslator, type MessageKey } from '@/i18n';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Combobox, Field, Input, PageHeader, Panel, Pill, Textarea } from '@/ui';

type Service = {
    kind: 'service' | 'feature';
    scope: 'organization' | 'person';
    serviceName: string | null;
    id: number;
    key: string;
    name: string;
    description: string | null;
    mode: 'waitlist' | 'paused' | 'invite_only' | 'open';
    questions: {
        key: string;
        label: string;
        required: boolean;
        type: 'string' | 'textarea' | 'select' | 'multiselect';
        options: string[];
        validation: 'none' | 'email' | 'url';
        min_length: number;
        max_length: number;
    }[];
    countryCollection: 'hidden' | 'optional' | 'required';
    countries: Record<string, string>;
    acceptanceUrl: string | null;
    publicUrl: string | null;
    status: 'waiting' | 'invited' | 'accepted' | 'following' | 'revoked' | null;
    joinHref: string;
    leaveHref: string;
    reference: string;
};

type Props = PageProps<{
    kind: 'service' | 'feature';
    organization: string | null;
    teams: { id: string; name: string }[];
    servicesHref: string;
    featuresHref: string;
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
    following: 'service_access.following',
    revoked: 'service_access.revoked',
    waiting: 'service_access.waiting',
    invited: 'service_access.invited',
    accepted: 'service_access.accepted',
};

export default function Services({
    kind,
    organization,
    teams,
    servicesHref,
    featuresHref,
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
            <PageHeader
                description={t(
                    kind === 'feature' ? 'service_access.feature_lead' : 'service_access.lead',
                )}
            />
            <nav className="flex gap-2" aria-label={t('service_access.title')}>
                <Button asChild variant={kind === 'service' ? 'primary' : 'secondary'}>
                    <Link
                        href={servicesHref}
                        aria-current={kind === 'service' ? 'page' : undefined}
                    >
                        {t('service_access.title')}
                    </Link>
                </Button>
                <Button asChild variant={kind === 'feature' ? 'primary' : 'secondary'}>
                    <Link
                        href={featuresHref}
                        aria-current={kind === 'feature' ? 'page' : undefined}
                    >
                        {t('service_access.features')}
                    </Link>
                </Button>
            </nav>
            {kind === 'feature' && (
                <Panel>
                    <Field label={t('service_access.team')}>
                        <Combobox
                            value={organization ?? undefined}
                            options={teams.map((team) => ({ value: team.id, label: team.name }))}
                            onValueChange={(value) =>
                                router.get(
                                    featuresHref,
                                    { organization: value },
                                    { preserveScroll: true },
                                )
                            }
                            disabled={teams.length === 0}
                        />
                    </Field>
                    {teams.length === 0 && (
                        <p className="mt-3 text-sm">{t('service_access.no_team')}</p>
                    )}
                </Panel>
            )}
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
                    <p>
                        {t(
                            kind === 'feature'
                                ? 'service_access.feature_empty'
                                : 'service_access.empty',
                        )}
                    </p>
                </Panel>
            ) : (
                <div className="grid items-start gap-5 lg:grid-cols-2">
                    {services.map((service) => (
                        <ServiceCard
                            key={`${organization ?? ''}-${service.id}-${service.status ?? 'new'}`}
                            service={service}
                            canJoin={
                                emailVerified &&
                                email !== null &&
                                (kind !== 'feature' || organization !== null)
                            }
                            organization={organization}
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
    organization,
}: {
    organization: string | null;
    service: Service;
    canJoin: boolean;
    email: string | null;
}) {
    const { t } = useTranslator();
    const form = useForm({
        organization,
        interest: 'notify' as 'notify' | 'test',
        country: '',
        answers: {} as Record<string, string | string[]>,
        reference: service.reference,
    });
    const withdrawal = useForm({ organization, reference: crypto.randomUUID() });
    const errors: Record<string, string | undefined> = form.errors;
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(service.joinHref, { preserveScroll: true, errorBag: `waitlist-${service.id}` });
    }
    return (
        <Panel
            id={`program-${service.id}`}
            title={service.name}
            description={service.description ?? undefined}
        >
            {service.kind === 'feature' && (
                <p className="mb-4 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {service.serviceName} ·{' '}
                    {t(
                        service.scope === 'person'
                            ? 'service_access.person_scope'
                            : 'service_access.team_scope',
                    )}
                </p>
            )}
            {service.kind === 'service' &&
                service.acceptanceUrl !== null &&
                service.mode !== 'paused' && (
                    <Button asChild variant="secondary" className="mb-4">
                        <a
                            href={`${service.acceptanceUrl}${service.acceptanceUrl.includes('?') ? '&' : '?'}invite=1`}
                        >
                            {t('service_access.invitation')}
                        </a>
                    </Button>
                )}
            {service.status !== null ? (
                <div className="space-y-3">
                    <Pill tone={service.status === 'accepted' ? 'success' : 'neutral'}>
                        {t(statusKeys[service.status])}
                    </Pill>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {t(
                            service.kind === 'feature'
                                ? 'service_access.feature_scope_note'
                                : 'service_access.email_notice',
                        )}
                    </p>
                    {service.kind === 'feature' && service.status !== 'accepted' && (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                withdrawal.post(service.leaveHref, {
                                    preserveScroll: true,
                                    errorBag: `waitlist-${service.id}`,
                                });
                            }}
                        >
                            <Button type="submit" loading={withdrawal.processing}>
                                {t('service_access.leave')}
                            </Button>
                            {withdrawal.errors.reference && (
                                <p role="alert" className="field-error">
                                    {withdrawal.errors.reference}
                                </p>
                            )}
                            {'waitlist' in withdrawal.errors && (
                                <p role="alert" className="field-error">
                                    {String(withdrawal.errors.waitlist)}
                                </p>
                            )}
                        </form>
                    )}
                </div>
            ) : service.mode !== 'waitlist' ? (
                <p className="text-sm">
                    {t(
                        service.kind !== 'feature'
                            ? 'service_access.closed'
                            : service.mode === 'open'
                              ? 'service_access.feature_released'
                              : 'service_access.feature_closed',
                    )}
                </p>
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
                    {service.kind === 'feature' && (
                        <fieldset className="space-y-2">
                            <legend className="mb-2 text-sm font-medium">
                                {t('service_access.interest')}
                            </legend>
                            {(['notify', 'test'] as const).map((interest) => (
                                <label key={interest} className="flex items-center gap-2 text-sm">
                                    <input
                                        type="radio"
                                        name={`interest-${service.id}`}
                                        value={interest}
                                        checked={form.data.interest === interest}
                                        onChange={() => form.setData('interest', interest)}
                                        disabled={!canJoin}
                                    />
                                    {t(
                                        interest === 'notify'
                                            ? 'service_access.notify'
                                            : 'service_access.test',
                                    )}
                                </label>
                            ))}
                        </fieldset>
                    )}
                    {service.countryCollection !== 'hidden' &&
                        Object.keys(service.countries).length > 0 && (
                            <Field
                                label={t('service_access.country')}
                                required={service.countryCollection === 'required'}
                                error={errors.country}
                            >
                                <Combobox
                                    value={form.data.country || undefined}
                                    options={Object.entries(service.countries).map(
                                        ([value, label]) => ({ value, label }),
                                    )}
                                    onValueChange={(value) => form.setData('country', value ?? '')}
                                    disabled={!canJoin}
                                />
                            </Field>
                        )}
                    {service.questions.map((question) => {
                        const answer = form.data.answers[question.key];
                        const setAnswer = (value: string | string[]) =>
                            form.setData('answers', {
                                ...form.data.answers,
                                [question.key]: value,
                            });
                        const value = typeof answer === 'string' ? answer : '';
                        const textProps = {
                            value,
                            onChange: (
                                event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>,
                            ) => setAnswer(event.target.value),
                            minLength: question.min_length,
                            maxLength: question.max_length,
                            required: question.required,
                            disabled: !canJoin,
                        };
                        return (
                            <Field
                                key={question.key}
                                label={question.label}
                                required={question.required}
                                error={errors[`answers.${question.key}`]}
                            >
                                {question.type === 'multiselect' ? (
                                    <fieldset
                                        aria-label={question.label}
                                        disabled={!canJoin}
                                        className="space-y-2"
                                    >
                                        {question.options.map((option) => (
                                            <label
                                                key={option}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={
                                                        Array.isArray(answer) &&
                                                        answer.includes(option)
                                                    }
                                                    onChange={(event) =>
                                                        setAnswer(
                                                            event.target.checked
                                                                ? [
                                                                      ...(Array.isArray(answer)
                                                                          ? answer
                                                                          : []),
                                                                      option,
                                                                  ]
                                                                : (Array.isArray(answer)
                                                                      ? answer
                                                                      : []
                                                                  ).filter(
                                                                      (selected) =>
                                                                          selected !== option,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                {option}
                                            </label>
                                        ))}
                                    </fieldset>
                                ) : question.type === 'select' ? (
                                    <Combobox
                                        value={value || undefined}
                                        options={question.options.map((option) => ({
                                            value: option,
                                            label: option,
                                        }))}
                                        onValueChange={(value) => setAnswer(value ?? '')}
                                        disabled={!canJoin}
                                    />
                                ) : question.type === 'string' ? (
                                    <Input
                                        {...textProps}
                                        type={
                                            question.validation === 'none'
                                                ? 'text'
                                                : question.validation
                                        }
                                    />
                                ) : (
                                    <Textarea {...textProps} rows={3} />
                                )}
                            </Field>
                        );
                    })}
                    {(errors.waitlist ?? errors.answers) && (
                        <p role="alert" className="field-error">
                            {errors.waitlist ?? errors.answers}
                        </p>
                    )}
                    <p className="text-xs" style={{ color: 'var(--muted-foreground)' }}>
                        {t(
                            service.kind === 'feature'
                                ? 'service_access.feature_scope_note'
                                : 'service_access.scope_note',
                        )}
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
            {service.publicUrl !== null && (
                <a
                    href={service.publicUrl}
                    className="mt-5 inline-block text-sm underline"
                    rel="noreferrer"
                >
                    {t('service_access.public_page')}
                </a>
            )}
        </Panel>
    );
}

Services.layout = (page: ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
