import { Link, useForm, usePage } from '@inertiajs/react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import type { OrganizationHub } from '@/types';
import { Button, CopyButton, Dialog, DialogClose, Icon, LinkTabs, Pill, RadioGroup } from '@/ui';

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

                    {hub.portalLink !== null && (
                        <Button variant="primary" size="sm" onClick={() => setLinking(true)}>
                            <Icon name="external" className="w-4 h-4" />
                            Admin Portal link
                        </Button>
                    )}
                </div>
            </div>

            {portalUrl !== undefined && hub.portalLink !== null && (
                <PortalLinkRevealed url={portalUrl} />
            )}

            <LinkTabs tabs={hub.tabs} label={`${hub.name} pages`} />

            {children}

            {hub.portalLink !== null && (
                <PortalLinkDialog
                    open={linking}
                    onOpenChange={setLinking}
                    name={hub.name}
                    link={hub.portalLink}
                />
            )}
        </div>
    );
}

/**
 * "Admin Portal link" — what the link may set up. Only what the organization's plan includes
 * is offered, because the action refuses anything else and a choice that is then refused is
 * the console lying about what it can do.
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
    link: NonNullable<OrganizationHub['portalLink']>;
}) {
    const form = useForm({ covers: link.covers[0]?.value ?? '' });

    const submit = (): void => {
        form.post(link.href, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={onOpenChange}
            title="Admin Portal link"
            description={`A single-use link ${name}'s IT administrator opens to set things up or read its audit logs themselves, without an account here. It expires soon and is shown once.`}
            footer={
                <>
                    <DialogClose asChild>
                        <Button>Cancel</Button>
                    </DialogClose>
                    <Button variant="primary" loading={form.processing} onClick={submit}>
                        Create link
                    </Button>
                </>
            }
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                <RadioGroup
                    label="What it opens"
                    value={form.data.covers}
                    onValueChange={(covers) => form.setData('covers', covers)}
                    options={link.covers.map((option) => ({
                        value: option.value,
                        label: option.label,
                    }))}
                />
                {form.errors.covers !== undefined && (
                    <p role="alert" className="field-error mt-2">
                        {form.errors.covers}
                    </p>
                )}
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
                Send this single-use link to whoever runs their identity provider. It works without
                an account and expires soon. Copy it now — it is shown only once.
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
