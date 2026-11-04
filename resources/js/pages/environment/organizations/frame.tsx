import { useTranslator } from '@/i18n';
import { useForm, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
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
    const { t: tc } = useTranslator();

    // The link itself, on the flash channel and nowhere else: it admits its holder to the
    // organization's setup with no account, and page props are written into the history
    // entry, where it would be retrievable by pressing Back.
    const portalUrl = usePage().flash.portalUrl;
    const [linking, setLinking] = useState(false);

    const suspended = hub.status === 'suspended';
    const deleted = hub.status === 'deleted';

    return (
        <div className="space-y-6">
            <div>
                <Breadcrumb href={hub.indexHref} label={tc('console.organizations_2730183d')} />
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
                                label={tc('console.copy_organization_id_320d9999')}
                            />
                        </div>
                    </div>

                    <Button variant="primary" size="sm" onClick={() => setLinking(true)}>
                        <Icon name="external" className="w-4 h-4" />
                        {tc('console.admin_portal_link_f7e62127')}
                    </Button>
                </div>
            </div>

            {portalUrl !== undefined && <PortalLinkRevealed url={portalUrl} />}

            <div>
                <LinkTabs
                    tabs={hub.tabs}
                    label={tc('console.value0_pages_e0b5cf6d', { value0: hub.name })}
                />
                {/*
                    The shown page's group, as a second row — Members beside its pending
                    Invitations, the two audit logs, Settings' four pages. Fourteen tabs in
                    one row ran off the edge of a laptop screen; nine tabs and a row for the
                    group fit.
                */}
                <LinkTabs
                    tabs={hub.subTabs}
                    label={tc('console.value0_pages_e0b5cf6d', {
                        value0: hub.tabs.find((tab) => tab.current)?.label ?? hub.name,
                    })}
                    variant="sub"
                />
            </div>

            {children}

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
    const { t: tc } = useTranslator();

    const form = useForm({
        intents: link.intents
            .filter((intent) => intent.available)
            .slice(0, 1)
            .map((intent) => intent.value),
        expires_in_minutes: link.lifetimes[0]?.value ?? '30',
        email: '',
        locale: link.defaultLocale,
    });

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
            title={tc('console.admin_portal_link_f7e62127')}
            description={tc('console.a_single_use_link_value0_s_it_administrator__10ae0882', {
                value0: name,
            })}
            footer={
                <>
                    <DialogClose asChild>
                        <Button>{tc('console.cancel_19766ed6')}</Button>
                    </DialogClose>
                    <Button
                        variant="primary"
                        loading={form.processing}
                        disabled={form.data.intents.length === 0}
                        onClick={submit}
                    >
                        {form.data.email.trim() === ''
                            ? tc('console.create_link_e6b850cf')
                            : tc('console.create_and_send_link_54020cd9')}
                    </Button>
                </>
            }
        >
            <form
                className="space-y-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
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

                <Field
                    label={tc('console.link_expires_after_5a55f779')}
                    error={form.errors.expires_in_minutes}
                >
                    <Select
                        name="expires_in_minutes"
                        value={form.data.expires_in_minutes}
                        onValueChange={(minutes) => form.setData('expires_in_minutes', minutes)}
                        options={link.lifetimes}
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label={tc('console.email_it_to_6854d0fa')}
                        optional
                        hint={tc('console.their_it_contact_left_blank_you_copy_the_lin_2fe54822')}
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
                    <Field label={tc('console.email_language_3e266c95')} error={form.errors.locale}>
                        <Select
                            name="locale"
                            value={form.data.locale}
                            disabled={form.data.email.trim() === ''}
                            onValueChange={(locale) => form.setData('locale', locale)}
                            options={link.locales}
                        />
                    </Field>
                </div>
            </form>
        </Dialog>
    );
}

/** The link, the one render it is in — brought into view, because the button was above it. */
function PortalLinkRevealed({ url }: { url: string }) {
    const { t: tc } = useTranslator();

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
                {tc('console.setup_link_for_their_it_admin_8c06f675')}
            </p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                {tc('console.send_this_single_use_link_to_whoever_runs_th_ecdd3fd5')}
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
