import { Link, useForm, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import type { OrganizationHub } from '@/types';
import {
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
                <Link
                    href={hub.indexHref}
                    className="text-sm inline-flex items-center gap-1"
                    style={{ color: 'var(--muted-foreground)' }}
                >
                    <Icon
                        name="chevron"
                        className="w-3.5 h-3.5"
                        style={{ transform: 'rotate(90deg)' }}
                    />
                    Organizations
                </Link>
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
                            <CopyButton value={hub.id} label="Copy organization id" />
                        </div>
                    </div>

                    <Button variant="primary" size="sm" onClick={() => setLinking(true)}>
                        <Icon name="external" className="w-4 h-4" />
                        Admin Portal link
                    </Button>
                </div>
            </div>

            {portalUrl !== undefined && <PortalLinkRevealed url={portalUrl} />}

            <LinkTabs tabs={hub.tabs} label={`${hub.name} pages`} />

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
            title="Admin Portal link"
            description={`A single-use link ${name}'s IT administrator opens to set things up themselves, without an account here. It is shown once.`}
            footer={
                <>
                    <DialogClose asChild>
                        <Button>Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="primary"
                        loading={form.processing}
                        disabled={form.data.intents.length === 0}
                        onClick={submit}
                    >
                        {form.data.email.trim() === '' ? 'Create link' : 'Create and send link'}
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
                    <legend className="text-sm font-medium mb-2">What it sets up</legend>
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
                                        : `${intent.description} Not included in this organization's plan.`
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

                <Field label="Link expires after" error={form.errors.expires_in_minutes}>
                    <Select
                        name="expires_in_minutes"
                        value={form.data.expires_in_minutes}
                        onValueChange={(minutes) => form.setData('expires_in_minutes', minutes)}
                        options={link.lifetimes}
                    />
                </Field>

                <div className="grid gap-4 sm:grid-cols-2">
                    <Field
                        label="Email it to"
                        optional
                        hint="Their IT contact. Left blank, you copy the link yourself."
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
                    <Field label="Email language" error={form.errors.locale}>
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
            <p className="text-sm font-semibold">Setup link for their IT admin</p>
            <p className="mt-1 text-xs" style={{ color: 'var(--muted-foreground)' }}>
                Send this single-use link to whoever runs their identity provider, if it was not
                mailed to them. It works without an account until it expires. Copy it now — it is
                shown only once.
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
