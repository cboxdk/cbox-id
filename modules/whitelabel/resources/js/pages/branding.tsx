import { useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { HelpContent, OrganizationFilter, PageProps } from '@/types';
import {
    Badge,
    Button,
    Field,
    FilterChips,
    Input,
    OrganizationFilterChip,
    PageHeader,
    Panel,
    Textarea,
} from '@/ui';

type Props = PageProps<{
    tokens: string[];
    palette: Record<string, string>;
    appName: string;
    emailFromName: string;
    emailTemplate: string;
    /** The uploaded images, read-only here — they are uploaded on Appearance. */
    logoUrl: string | null;
    faviconUrl: string | null;
    /** Where the images are uploaded: this altitude's Appearance page. */
    appearanceHref: string;
    /** True when this page is editing the environment default every organization inherits. */
    environmentDefault: boolean;
    /** On the environment default: the chip that goes to one organization's own brand. */
    organizationFilter: OrganizationFilter | null;
    saveHref: string;
    help: HelpContent;
}>;

export default function Branding({
    tokens,
    palette,
    appName,
    emailFromName,
    emailTemplate,
    logoUrl,
    faviconUrl,
    appearanceHref,
    environmentDefault,
    organizationFilter,
    saveHref,
    help,
}: Props) {
    const form = useForm<{
        palette: Record<string, string>;
        appName: string;
        emailFromName: string;
        emailTemplate: string;
    }>({
        palette,
        appName,
        emailFromName,
        emailTemplate,
    });

    const setToken = (token: string, value: string): void =>
        form.setData('palette', { ...form.data.palette, [token]: value });

    // Only the colours actually set — an unset token inherits, and previewing the default
    // as though it were a choice is what makes "did that save?" unanswerable.
    const preview = tokens.filter((token) => (form.data.palette[token] ?? '') !== '');

    return (
        <div className="space-y-6">
            <PageHeader
                help={help}
                description={
                    environmentDefault
                        ? 'Theme the console and hosted sign-in for this whole environment — palette, app name and email sender. Every organization inherits this unless it sets its own — pick one below to brand just that one.'
                        : 'Theme the console and hosted sign-in for this organization — palette, app name and email sender. This overrides the environment default.'
                }
            />

            {organizationFilter !== null && (
                <FilterChips>
                    <OrganizationFilterChip filter={organizationFilter} />
                </FilterChips>
            )}

            {/* Live preview: the tokens applied to a scoped surface and nowhere else. */}
            <Panel title="Preview">
                <div
                    style={Object.fromEntries(
                        preview.map((token) => [token, form.data.palette[token]]),
                    )}
                >
                    <div className="flex items-center gap-3 flex-wrap">
                        {preview.length === 0 ? (
                            <span style={{ color: 'var(--muted-foreground)' }}>
                                No custom colours yet — the default Cbox theme is in use.
                            </span>
                        ) : (
                            preview.map((token) => (
                                <Badge key={token} title={token}>
                                    <span
                                        aria-hidden="true"
                                        style={{
                                            width: '14px',
                                            height: '14px',
                                            borderRadius: '4px',
                                            background: form.data.palette[token],
                                            display: 'inline-block',
                                        }}
                                    />
                                    {token}
                                </Badge>
                            ))
                        )}
                    </div>

                    <Button variant="primary" className="mt-4">
                        {form.data.appName === '' ? 'Cbox ID' : form.data.appName}
                    </Button>
                </div>
            </Panel>

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    // A multipart POST, because two of these fields are files.
                    form.post(saveHref, { preserveScroll: true });
                }}
            >
                <Panel title="Palette & identity">
                    <div className="space-y-4">
                        <div
                            className="grid gap-3"
                            style={{
                                gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
                            }}
                        >
                            {tokens.map((token) => (
                                <Field
                                    key={token}
                                    label={token.charAt(0).toUpperCase() + token.slice(1)}
                                    error={form.errors[`palette.${token}` as never]}
                                >
                                    {/*
                                        A SWATCH YOU CAN CLICK, not a hex field alone — the
                                        same control the Appearance editor uses: a native
                                        colour input laid invisibly over the swatch, with the
                                        text field beside it so a brand hex can still be
                                        pasted, and so `oklch(…)`, which the normalizer
                                        accepts and no native picker can express, stays
                                        typeable. Choosing a colour by typing its coordinates
                                        is a thing only the person who wrote the parser
                                        enjoys.
                                    */}
                                    <div className="flex items-center gap-2">
                                        <span
                                            className="relative w-8 h-8 rounded-lg shrink-0 overflow-hidden"
                                            style={{ border: '1px solid var(--border)' }}
                                        >
                                            <span
                                                className="absolute inset-0"
                                                style={{
                                                    background:
                                                        (form.data.palette[token] ?? '') === ''
                                                            ? 'var(--secondary)'
                                                            : form.data.palette[token],
                                                }}
                                            />
                                            <input
                                                type="color"
                                                className="absolute inset-0 opacity-0 cursor-pointer"
                                                aria-label={`Pick ${token} colour`}
                                                value={
                                                    (form.data.palette[token] ?? '').startsWith('#')
                                                        ? form.data.palette[token]
                                                        : '#000000'
                                                }
                                                onChange={(event) =>
                                                    setToken(token, event.target.value)
                                                }
                                            />
                                        </span>

                                        <Input
                                            className="mono min-w-0 flex-1"
                                            placeholder="#0a2540 or oklch(…)"
                                            value={form.data.palette[token] ?? ''}
                                            onChange={(event) =>
                                                setToken(token, event.target.value)
                                            }
                                        />
                                    </div>
                                </Field>
                            ))}
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field label="App name" error={form.errors.appName}>
                                <Input
                                    placeholder="Acme ID"
                                    value={form.data.appName}
                                    onChange={(event) =>
                                        form.setData('appName', event.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Email sender name" error={form.errors.emailFromName}>
                                <Input
                                    placeholder="Acme Security"
                                    value={form.data.emailFromName}
                                    onChange={(event) =>
                                        form.setData('emailFromName', event.target.value)
                                    }
                                />
                            </Field>
                        </div>

                        {/*
                            THE IMAGES ARE THE SIGN-IN'S, and are uploaded beside the theme they
                            are previewed in. Shown here so this page still answers "what does
                            our brand look like", with the one place to change them.
                        */}
                        <div
                            className="flex flex-wrap items-center gap-3 rounded-lg px-3.5 py-3"
                            style={{ background: 'var(--secondary)' }}
                        >
                            {logoUrl !== null ? (
                                <img
                                    src={logoUrl}
                                    alt="Current logo"
                                    style={{ maxHeight: '2rem', maxWidth: '10rem' }}
                                />
                            ) : null}
                            {faviconUrl !== null ? (
                                <img
                                    src={faviconUrl}
                                    alt="Current favicon"
                                    style={{ height: '1.25rem' }}
                                />
                            ) : null}
                            <p
                                className="text-[13px] flex-1 min-w-[12rem]"
                                style={{ color: 'var(--muted-foreground)' }}
                            >
                                {logoUrl === null && faviconUrl === null
                                    ? 'No logo or favicon yet. '
                                    : ''}
                                The logo and favicon are uploaded on the Appearance page, beside the
                                sign-in preview.
                            </p>
                            <a href={appearanceHref} className="btn btn-secondary btn-sm">
                                Logo &amp; favicon on Appearance
                            </a>
                        </div>

                        <Field
                            label="Welcome email (preview)"
                            hint={`Rendered with your sender name${form.data.emailFromName === '' ? '' : ` “${form.data.emailFromName}”`}.`}
                            error={form.errors.emailTemplate}
                        >
                            <Textarea
                                rows={4}
                                placeholder="Welcome to {app}. Your account is ready."
                                value={form.data.emailTemplate}
                                onChange={(event) =>
                                    form.setData('emailTemplate', event.target.value)
                                }
                            />
                        </Field>

                        <div>
                            <Button type="submit" variant="primary" loading={form.processing}>
                                Save branding
                            </Button>
                        </div>
                    </div>
                </Panel>
            </form>

            {/*
                The custom-domain controls that used to sit here are gone, not moved: the
                account plane already owns them at Workspace › Environment domains, where they
                are scoped to environments the member can actually reach and verified by a DNS
                TXT record. This copy was neither — an org admin could take down sign-in at the
                environment's vanity host for every other tenant in it, or point it somewhere
                of their choosing, with nothing but an organization-level role.
            */}
        </div>
    );
}

Branding.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
