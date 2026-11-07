import { EnvironmentReadOnly, useCanManageEnvironment } from '@/ui/EnvironmentReadOnly';
import { useTranslator as useConsoleTranslator } from '@/i18n';
import { useForm, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useId, useRef, useState } from 'react';
import type { OrganizationHub } from '@/types';
import {
    Breadcrumb,
    Button,
    Checkbox,
    CopyButton,
    Dialog,
    DialogClose,
    Field,
    Icon,
    Input,
    LinkTabs,
    Pill,
    Select,
} from '@/ui';

/**
 * WHAT EVERY TAB OF AN ORGANIZATION'S PAGE DRAWS FIRST: the way back to the list, the
 * organization's name, its id to copy, its status, the one thing an environment
 * administrator most often came here to do — hand the customer's IT administrator an Admin
 * Portal link — and the tabs.
 *
 * Drawn by the layout around EVERY page under `/admin/organizations/{organization}/…` (the
 * server shares `organizationHub` there), which is what lets the environment-wide pages that
 * are also tabs here — Enterprise SSO, Roles, the audit log — sit inside an organization's
 * page without knowing they are one.
 */
export function OrganizationFrame({
    hub,
    children,
}: {
    hub: OrganizationHub;
    children: ReactNode;
}) {
    const { t: consoleFinalText } = useConsoleTranslator();

    const { t: consoleT } = useConsoleTranslator();
    // The link itself, on the flash channel and nowhere else: it admits its holder to the
    // organization's setup with no account, and page props are written into the history
    // entry, where it would be retrievable by pressing Back.
    const canManage = useCanManageEnvironment();
    const portalUrl = usePage().flash.portalUrl;
    const [linking, setLinking] = useState(false);

    const suspended = hub.status === 'suspended';
    const deleted = hub.status === 'deleted';

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb
                    href={hub.indexHref}
                    label={consoleT('console.organizations_2730183d')}
                />
                <div className="mt-2 flex items-start justify-between gap-3 flex-wrap">
                    <div style={{ minWidth: 0 }}>
                        <div className="flex items-center gap-3 flex-wrap">
                            <h1 className="cbx-page-title" style={{ overflowWrap: 'anywhere' }}>
                                {hub.name}
                            </h1>
                            <Pill tone={suspended || deleted ? 'warning' : 'success'}>
                                {hub.status}
                            </Pill>
                        </div>
                        <div className="mt-1 flex items-center gap-1">
                            <span
                                className="text-sm mono"
                                style={{ color: 'var(--faint)', overflowWrap: 'anywhere' }}
                            >
                                {hub.id}
                            </span>
                            <CopyButton
                                value={hub.id}
                                label={consoleT('console.copy_organization_id_320d9999')}
                            />
                        </div>
                    </div>

                    <Button
                        disabled={!canManage}
                        variant="primary"
                        size="sm"
                        onClick={() => setLinking(true)}
                    >
                        <Icon name="external" className="w-4 h-4" />
                        {consoleT('console.ux_invite_it')}
                    </Button>
                </div>
            </div>

            {portalUrl !== undefined && <PortalLinkRevealed url={portalUrl} />}

            <div>
                <LinkTabs
                    tabs={hub.tabs}
                    label={consoleFinalText('console._part1_pages_e37b5db1', { part1: hub.name })}
                />
                {/*
                    The shown page's group, as a second row — Members beside its pending
                    Invitations, the two audit logs, Settings' four pages. Fourteen tabs in
                    one row ran off the edge of a laptop screen; nine tabs and a row for the
                    group fit.
                */}
                <LinkTabs
                    tabs={hub.subTabs}
                    label={consoleFinalText('console._part1_pages_e37b5db1', {
                        part1: hub.tabs.find((tab) => tab.current)?.label ?? hub.name,
                    })}
                    variant="sub"
                />
            </div>

            <EnvironmentReadOnly>{children}</EnvironmentReadOnly>

            <PortalLinkDialog
                open={linking}
                onOpenChange={setLinking}
                name={hub.name}
                link={hub.portalLink}
            />
        </div>
    );
}

/**
 * "Admin Portal link" — WHAT the link sets up (the intents, as checkboxes), HOW LONG it may
 * wait to be opened, and, optionally, WHO it is mailed to and in which language.
 *
 * An intent the organization's plan does not include is shown, disabled, with the reason —
 * the action refuses it, and a choice offered only to be refused is the console lying about
 * what it can do; one hidden is a feature nobody learns exists. The mail goes in the
 * customer's language, because the person reading it is theirs, not ours.
 */
function PortalLinkDialog({
    open,
    onOpenChange,
    name,
    link,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    name: string;
    link: OrganizationHub['portalLink'];
}) {
    const { t: tc } = useConsoleTranslator();

    const form = useForm({
        intents: link.intents
            .filter((intent) => intent.available)
            .slice(0, 1)
            .map((intent) => intent.value),
        expires_in_minutes: link.lifetimes[0]?.value ?? '30',
        email: '',
        locale: link.defaultLocale,
    });

    const [step, setStep] = useState<'task' | 'delivery'>('task');
    const formId = useId();
    const stepHeading = useRef<HTMLHeadingElement>(null);
    const goTo = (next: 'task' | 'delivery') => {
        setStep(next);
        requestAnimationFrame(() => stepHeading.current?.focus());
    };

    const toggle = (value: string, checked: boolean): void => {
        form.setData(
            'intents',
            checked
                ? [...form.data.intents, value]
                : form.data.intents.filter((intent) => intent !== value),
        );
    };

    const submit = (): void => {
        form.post(link.href, {
            preserveScroll: true,
            onError: (errors) =>
                goTo(
                    Object.keys(errors).some((key) => key.startsWith('intents'))
                        ? 'task'
                        : 'delivery',
                ),
            onSuccess: () => {
                onOpenChange(false);
                form.reset('email');
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            presentation="sheet"
            initialFocus={stepHeading}
            title={tc('console.ux_invite_it')}
            description={tc('console.a_single_use_link_value0_s_it_administrator__10ae0882', {
                value0: name,
            })}
            footer={
                <>
                    {step === 'delivery' ? (
                        <Button disabled={form.processing} onClick={() => goTo('task')}>
                            {tc('console.ux_back')}
                        </Button>
                    ) : (
                        <DialogClose asChild>
                            <Button>{tc('console.cancel_19766ed6')}</Button>
                        </DialogClose>
                    )}
                    <Button
                        type="submit"
                        form={formId}
                        variant="primary"
                        loading={form.processing}
                        disabled={form.data.intents.length === 0}
                    >
                        {step === 'task'
                            ? tc('console.continue_31fbef16')
                            : form.data.email.trim() === ''
                              ? tc('console.create_link_e6b850cf')
                              : tc('console.create_and_send_link_54020cd9')}
                    </Button>
                </>
            }
        >
            <form
                id={formId}
                className="space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (step === 'task') goTo('delivery');
                    else submit();
                }}
            >
                <div
                    className="flex gap-3 text-xs text-[var(--muted-foreground)]"
                    aria-label={tc('console.setup_progress_e9b4495a')}
                >
                    <span aria-current={step === 'task' ? 'step' : undefined}>
                        {tc('console.ux_task')}
                    </span>
                    <span aria-current={step === 'delivery' ? 'step' : undefined}>
                        {tc('console.ux_delivery')}
                    </span>
                </div>
                <h2 ref={stepHeading} tabIndex={-1} className="font-medium outline-none">
                    {tc(step === 'task' ? 'console.ux_task' : 'console.ux_delivery')}
                </h2>
                {step === 'task' ? (
                    <fieldset>
                        <legend className="text-sm font-medium mb-2">
                            {tc('console.what_it_opens_08a62c98')}
                        </legend>
                        <div className="space-y-2">
                            {link.intents.map((intent) => (
                                <Checkbox
                                    key={intent.value}
                                    name="intents[]"
                                    value={intent.value}
                                    checked={form.data.intents.includes(intent.value)}
                                    disabled={!intent.available}
                                    onCheckedChange={(checked) => toggle(intent.value, checked)}
                                    label={intent.label}
                                    hint={
                                        intent.available
                                            ? intent.description
                                            : tc(
                                                  'console.value0_not_included_in_this_organization_s_p_a6447bb1',
                                                  { value0: intent.description },
                                              )
                                    }
                                />
                            ))}
                        </div>
                        {form.errors.intents !== undefined && (
                            <p role="alert" className="field-error mt-2">
                                {form.errors.intents}
                            </p>
                        )}
                    </fieldset>
                ) : (
                    <>
                        <div className="rounded-lg border border-[var(--border)] p-3 text-sm">
                            <p className="text-xs text-[var(--muted-foreground)] mb-1">
                                {tc('console.what_it_opens_08a62c98')}
                            </p>
                            {link.intents
                                .filter((intent) => form.data.intents.includes(intent.value))
                                .map((intent) => intent.label)
                                .join(' · ')}
                        </div>
                        <Field
                            label={tc('console.link_expires_after_5a55f779')}
                            error={form.errors.expires_in_minutes}
                        >
                            <Select
                                name="expires_in_minutes"
                                value={form.data.expires_in_minutes}
                                onValueChange={(minutes) =>
                                    form.setData('expires_in_minutes', minutes)
                                }
                                options={link.lifetimes}
                            />
                        </Field>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label={tc('console.email_it_to_6854d0fa')}
                                optional
                                hint={tc(
                                    'console.their_it_contact_left_blank_you_copy_the_lin_2fe54822',
                                )}
                                error={form.errors.email}
                            >
                                <Input
                                    name="email"
                                    type="email"
                                    autoComplete="off"
                                    placeholder="it@customer.com"
                                    value={form.data.email}
                                    onChange={(event) => form.setData('email', event.target.value)}
                                />
                            </Field>
                            <Field
                                label={tc('console.email_language_3e266c95')}
                                error={form.errors.locale}
                            >
                                <Select
                                    name="locale"
                                    value={form.data.locale}
                                    disabled={form.data.email.trim() === ''}
                                    onValueChange={(locale) => form.setData('locale', locale)}
                                    options={link.locales}
                                />
                            </Field>
                        </div>
                    </>
                )}
            </form>
        </Dialog>
    );
}

/** The link, the one render it is in — brought into view, because the button was above it. */
function PortalLinkRevealed({ url }: { url: string }) {
    const { t: consoleT } = useConsoleTranslator();
    const card = useRef<HTMLDivElement>(null);

    useEffect(() => {
        card.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        card.current?.focus({ preventScroll: true });
    }, []);

    return (
        <div
            ref={card}
            tabIndex={-1}
            className="rounded-xl border p-5"
            style={{
                borderColor: 'color-mix(in oklch, var(--accent) 40%, transparent)',
                background: 'var(--accent-soft)',
            }}
        >
            <p className="text-sm font-semibold">
                {consoleT('console.setup_link_for_their_it_admin_8c06f675')}
            </p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {consoleT(
                    'console.send_this_single_use_link_to_whoever_runs_their_identit_ecdd3fd5',
                )}
            </p>
            <div className="mt-3 flex items-start gap-2">
                <code
                    className="mono text-xs rounded-lg px-3 py-2 select-all break-all flex-1"
                    style={{ background: 'var(--secondary)', border: '1px solid var(--border)' }}
                >
                    {url}
                </code>
                <CopyButton value={url} />
            </div>
        </div>
    );
}
