import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { Toaster, toast } from '@/chrome/Toaster';
import {
    Avatar,
    Badge,
    Button,
    Checkbox,
    Combobox,
    ConfirmDelete,
    CopyButton,
    Dialog,
    Divider,
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
    EmptyState,
    Field,
    Help,
    Icon,
    type IconName,
    iconNames,
    Input,
    Kv,
    KvList,
    PageHeader,
    Panel,
    Pill,
    RadioGroup,
    Select,
    Spinner,
    Stat,
    Switch,
    Tab,
    Table,
    Tabs,
    Td,
    TdMono,
    Textarea,
    Th,
    Tooltip,
    TooltipProvider,
} from '@/ui';

/**
 * THE GALLERY. Every primitive, drawn.
 *
 * Local only (see routes/web.php). It is here because the component tests cannot see
 * whether a control is DRAWN — they assert roles and behaviour in jsdom, which has no
 * layout engine and no cascade. A switch whose thumb is invisible against its track in
 * dark mode passes every test in this repository.
 *
 * So: open it, switch the theme, drag the window to 375px, and look.
 */
export default function DesignSystem() {
    const [dialog, setDialog] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [mfa, setMfa] = useState(true);
    const [invite, setInvite] = useState(false);
    const [access, setAccess] = useState<'read' | 'write'>('read');
    const [protocol, setProtocol] = useState<'oidc' | 'saml'>('oidc');
    const [org, setOrg] = useState<string>();
    const [tab, setTab] = useState('all');

    const organizations = Array.from({ length: 40 }, (_, i) => ({
        value: `org_${i}`,
        label: `Organization ${i}`,
        keywords: [`slug-${i}`],
    }));

    return (
        <TooltipProvider>
            <Head title="Design system" />
            <Toaster />

            <main
                id="main-content"
                style={{
                    maxWidth: '72rem',
                    margin: '0 auto',
                    padding: '32px 20px 96px',
                    display: 'flex',
                    flexDirection: 'column',
                    gap: 'var(--section-gap)',
                }}
            >
                <PageHeader
                    eyebrow="Internal"
                    title="Design system"
                    badge={<Pill tone="info">local only</Pill>}
                    description="Every primitive the console is built from. Switch the theme and narrow the window — the suite cannot see either."
                    actions={
                        <>
                            <Button icon="refresh" onClick={() => window.location.reload()}>
                                Reload
                            </Button>
                            <Button variant="primary" icon="plus">
                                Primary action
                            </Button>
                        </>
                    }
                />

                <Panel title="Buttons" description="Four variants, three sizes, one loading state.">
                    <Row>
                        <Button variant="primary">Primary</Button>
                        <Button variant="secondary">Secondary</Button>
                        <Button variant="ghost">Ghost</Button>
                        <Button variant="danger">Danger</Button>
                    </Row>
                    <Row>
                        <Button size="sm">Small</Button>
                        <Button>Medium</Button>
                        <Button size="lg">Large</Button>
                    </Row>
                    <Row>
                        <Button icon="plus" variant="primary">
                            With an icon
                        </Button>
                        <Button loading variant="primary">
                            In flight
                        </Button>
                        <Button disabled>Disabled</Button>
                        <Button asChild variant="secondary">
                            <a href="/dev/design-system">A real anchor</a>
                        </Button>
                    </Row>
                </Panel>

                <Panel title="Status" description="A pill says the state in words; the tone only reinforces it.">
                    <Row>
                        <Pill tone="success">Active</Pill>
                        <Pill tone="warning">Degraded</Pill>
                        <Pill tone="destructive">Failed</Pill>
                        <Pill tone="info">Pending</Pill>
                        <Pill>Unknown</Pill>
                        <Pill tone="info" dot={false}>
                            Enterprise
                        </Pill>
                    </Row>
                    <Row>
                        <Badge>Neutral</Badge>
                        <Badge tone="success">OIDC</Badge>
                        <Badge tone="warn">Trial</Badge>
                        <Badge tone="danger">Revoked</Badge>
                        <Badge tone="info">SAML</Badge>
                    </Row>
                    <Row>
                        <Avatar name="Sylvester Damgaard" />
                        <Spinner label="Loading" />
                        <CopyButton value="whsec_3f9a2c1b8e7d4f60" />
                        <Help title="What is a signing secret?" href="/docs">
                            Every delivery is signed with it, so a receiver can prove the request
                            came from us and not from somebody who learned the URL.
                        </Help>
                    </Row>
                </Panel>

                <Panel title="Metrics">
                    <div
                        style={{
                            display: 'grid',
                            gap: '12px',
                            gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                        }}
                    >
                        <Stat icon="members" label="Users" value="1,284" />
                        <Stat icon="shield-check" tone="success" label="MFA enrolled" value="94%" />
                        <Stat icon="webhooks" tone="warning" label="Failing endpoints" value="2" />
                        <Stat icon="key" tone="neutral" label="Active keys" value="7" href="#" />
                    </div>
                </Panel>

                <Panel title="Forms" description="Label, hint and error are wired by the Field, never by the call site.">
                    <div style={{ display: 'grid', gap: '16px', maxWidth: '28rem' }}>
                        <Field label="Organization name" required hint="Shown to your members.">
                            <Input placeholder="Acme Inc" />
                        </Field>

                        <Field label="Email address" error="That address is already in use.">
                            <Input type="email" defaultValue="ada@example.com" />
                        </Field>

                        <Field label="Protocol">
                            <Select
                                value={protocol}
                                onValueChange={setProtocol}
                                options={[
                                    { value: 'oidc', label: 'OpenID Connect', hint: 'Recommended' },
                                    { value: 'saml', label: 'SAML 2.0' },
                                ]}
                            />
                        </Field>

                        <Field label="Organization" hint="Forty of them — type to narrow.">
                            <Combobox
                                value={org}
                                onValueChange={setOrg}
                                options={organizations}
                                placeholder="Choose an organization…"
                            />
                        </Field>

                        <Field label="Notes">
                            <Textarea placeholder="Why this change was made…" />
                        </Field>

                        <Field label="Require MFA">
                            <Switch checked={mfa} onCheckedChange={setMfa} />
                        </Field>

                        <Checkbox
                            checked={invite}
                            onCheckedChange={setInvite}
                            label="Send an invitation email"
                            hint="They can also be given the link directly."
                        />

                        <RadioGroup
                            label="Access"
                            value={access}
                            onValueChange={setAccess}
                            options={[
                                { value: 'read', label: 'Read only', hint: 'Can see, cannot change.' },
                                { value: 'write', label: 'Read and write' },
                            ]}
                        />

                        <Divider>or</Divider>

                        <Button variant="primary">Save changes</Button>
                    </div>
                </Panel>

                <Panel title="Overlays">
                    <Row>
                        <Button onClick={() => setDialog(true)}>Open a dialog</Button>
                        <Button variant="danger" onClick={() => setConfirm(true)}>
                            Type-to-confirm
                        </Button>

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button icon="chevron">Menu</Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent>
                                <DropdownMenuLabel>Endpoint</DropdownMenuLabel>
                                <DropdownMenuItem>Pause deliveries</DropdownMenuItem>
                                <DropdownMenuItem>Rotate signing secret</DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem destructive>Delete endpoint</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <Tooltip content="Deliveries are retried for 24 hours.">
                            <Button>Hover me</Button>
                        </Tooltip>

                        <Button onClick={() => toast.success('Signing secret rotated.')}>
                            Toast
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => toast.error('The endpoint refused the delivery.')}
                        >
                            Error toast
                        </Button>
                    </Row>
                </Panel>

                <Panel title="Tabs and tables" flush>
                    <div style={{ padding: '0 20px' }}>
                        <Tabs value={tab} onValueChange={setTab} label="Delivery status">
                            <Tab value="all">All</Tab>
                            <Tab value="failed">Failed</Tab>
                            <Tab value="pending">Pending</Tab>
                        </Tabs>
                    </div>

                    <Table caption="Recent deliveries">
                        <thead>
                            <tr>
                                <Th>Event</Th>
                                <Th>Status</Th>
                                <Th>Delivered</Th>
                                <Th>Id</Th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <Td>user.created</Td>
                                <Td>
                                    <Pill tone="success">200</Pill>
                                </Td>
                                <TdMono>2026-08-25 14:02:11</TdMono>
                                <TdMono>evt_01JQ8Z…</TdMono>
                            </tr>
                            <tr>
                                <Td>user.deleted</Td>
                                <Td>
                                    <Pill tone="destructive">502</Pill>
                                </Td>
                                <TdMono>2026-08-25 13:58:04</TdMono>
                                <TdMono>evt_01JQ8Y…</TdMono>
                            </tr>
                        </tbody>
                    </Table>
                </Panel>

                <Panel title="Details">
                    <KvList>
                        <Kv label="Endpoint">https://example.com/hooks/cbox</Kv>
                        <Kv label="Created">2026-04-02 09:14:55</Kv>
                        <Kv label="Purpose" prose>
                            Notifies the billing system when a member is provisioned.
                        </Kv>
                    </KvList>
                </Panel>

                <Panel title="Nothing here yet" flush>
                    <EmptyState
                        icon="webhooks"
                        title="No endpoints"
                        description="Nothing is listening for events in this environment yet."
                        steps={[
                            'Add an endpoint URL that can receive a POST.',
                            'Choose which events it should hear about.',
                            'Verify the signature using the secret shown once on creation.',
                        ]}
                        actions={
                            <>
                                <Button variant="primary" icon="plus">
                                    Add an endpoint
                                </Button>
                                <Button>Read the guide</Button>
                            </>
                        }
                    />
                </Panel>

                <Panel title="Icons" description="One family, one stroke weight. Add to resources/js/ui/icons.ts.">
                    <div
                        style={{
                            display: 'grid',
                            gap: '8px',
                            gridTemplateColumns: 'repeat(auto-fill, minmax(110px, 1fr))',
                        }}
                    >
                        {iconNames.map((name: IconName) => (
                            <span
                                key={name}
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    gap: '8px',
                                    fontSize: '12px',
                                    color: 'var(--muted-foreground)',
                                }}
                            >
                                <Icon name={name} className="w-4 h-4 shrink-0" />
                                {name}
                            </span>
                        ))}
                    </div>
                </Panel>
            </main>

            <Dialog
                open={dialog}
                onOpenChange={setDialog}
                title="Rotate the signing secret?"
                description="The current secret stops working immediately. Anything still verifying with it will start refusing deliveries."
                footer={
                    <>
                        <Button onClick={() => setDialog(false)}>Cancel</Button>
                        <Button variant="primary">Rotate</Button>
                    </>
                }
            >
                <Field label="Why" hint="Recorded in the activity log.">
                    <Input placeholder="Rotating after the leak on 24 August" />
                </Field>
            </Dialog>

            <ConfirmDelete
                open={confirm}
                onOpenChange={setConfirm}
                name="prod-webhook"
                environment="production"
                consequence="Deliveries stop immediately and the signing secret is destroyed."
                onConfirm={() => {
                    setConfirm(false);
                    toast.success('Endpoint deleted.');
                }}
            />
        </TooltipProvider>
    );
}

function Row({ children }: { children: React.ReactNode }) {
    return (
        <div
            style={{
                display: 'flex',
                flexWrap: 'wrap',
                alignItems: 'center',
                gap: '10px',
                marginBottom: '10px',
            }}
        >
            {children}
        </div>
    );
}
