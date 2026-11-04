import { useTranslator } from '@/i18n';
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
    const { t: tc } = useTranslator();

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
            <Head title={tc('console.design_system_4a866105')} />
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
                    title={tc('console.design_system_4a866105')}
                    badge={<Pill tone="info">{tc('console.local_only_1b7e7551')}</Pill>}
                    description={tc(
                        'console.every_primitive_the_console_is_built_from_sw_f2daecc0',
                    )}
                    actions={
                        <>
                            <Button icon="refresh" onClick={() => window.location.reload()}>
                                {tc('console.reload_bdc090ec')}
                            </Button>
                            <Button variant="primary" icon="plus">
                                {tc('console.primary_action_da2eb576')}
                            </Button>
                        </>
                    }
                />

                <Panel
                    title={tc('console.buttons_d452583a')}
                    description={tc('console.four_variants_three_sizes_one_loading_state_a23b3bb3')}
                >
                    <Row>
                        <Button variant="primary">{tc('console.primary_efe10c80')}</Button>
                        <Button variant="secondary">{tc('console.secondary_62f2ccff')}</Button>
                        <Button variant="ghost">{tc('console.ghost_df1bc498')}</Button>
                        <Button variant="danger">{tc('console.danger_8412c070')}</Button>
                    </Row>
                    <Row>
                        <Button size="sm">{tc('console.small_5263293f')}</Button>
                        <Button>{tc('console.medium_8e588cd1')}</Button>
                        <Button size="lg">{tc('console.large_ab80540d')}</Button>
                    </Row>
                    <Row>
                        <Button icon="plus" variant="primary">
                            {tc('console.with_an_icon_229e7b82')}
                        </Button>
                        <Button loading variant="primary">
                            {tc('console.in_flight_b65abbf5')}
                        </Button>
                        <Button disabled>{tc('console.disabled_75081b59')}</Button>
                        <Button asChild variant="secondary">
                            <a href="/dev/design-system">{tc('console.a_real_anchor_ead47fa2')}</a>
                        </Button>
                    </Row>
                </Panel>

                <Panel
                    title={tc('console.status_920e413c')}
                    description={tc(
                        'console.a_pill_says_the_state_in_words_the_tone_only_10648ffe',
                    )}
                >
                    <Row>
                        <Pill tone="success">{tc('console.active_92340695')}</Pill>
                        <Pill tone="warning">{tc('console.degraded_a8494c12')}</Pill>
                        <Pill tone="destructive">{tc('console.failed_031a8f0f')}</Pill>
                        <Pill tone="info">{tc('console.pending_331551b0')}</Pill>
                        <Pill>{tc('console.unknown_b764cdc0')}</Pill>
                        <Pill tone="info" dot={false}>
                            {tc('console.enterprise_3fbe5ed1')}
                        </Pill>
                    </Row>
                    <Row>
                        <Badge>{tc('console.neutral_1c6ac69f')}</Badge>
                        <Badge tone="success">OIDC</Badge>
                        <Badge tone="warn">{tc('console.trial_98a66e97')}</Badge>
                        <Badge tone="danger">{tc('console.revoked_f6f738d0')}</Badge>
                        <Badge tone="info">SAML</Badge>
                    </Row>
                    <Row>
                        <Avatar name="Sylvester Damgaard" />
                        <Spinner label={tc('console.loading_dc380888')} />
                        <CopyButton value="whsec_3f9a2c1b8e7d4f60" />
                        <Help
                            help={{
                                topic: 'webhooks',
                                title: tc('console.what_is_a_signing_secret_87afbb1d'),
                                summary:
                                    'Every delivery is signed with it, so a receiver can prove the request came from us and not from somebody who learned the URL.',
                                href: '/docs',
                            }}
                        />
                    </Row>
                </Panel>

                <Panel title={tc('console.metrics_a58da793')}>
                    <div
                        style={{
                            display: 'grid',
                            gap: '12px',
                            gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                        }}
                    >
                        <Stat icon="members" label={tc('console.users_6b0cc904')} value="1,284" />
                        <Stat
                            icon="shield-check"
                            tone="success"
                            label={tc('console.mfa_enrolled_c56fc40b')}
                            value="94%"
                        />
                        <Stat
                            icon="webhooks"
                            tone="warning"
                            label={tc('console.failing_endpoints_57a7ed78')}
                            value="2"
                        />
                        <Stat
                            icon="key"
                            tone="neutral"
                            label={tc('console.active_keys_457ca15d')}
                            value="7"
                            href="#"
                        />
                    </div>
                </Panel>

                <Panel
                    title={tc('console.forms_85845126')}
                    description={tc(
                        'console.label_hint_and_error_are_wired_by_the_field__1b04abf6',
                    )}
                >
                    <div style={{ display: 'grid', gap: '16px', maxWidth: '28rem' }}>
                        <Field
                            label={tc('console.organization_name_9a807d52')}
                            required
                            hint={tc('console.shown_to_your_members_27263d82')}
                        >
                            <Input placeholder={tc('console.acme_inc_42cf7a26')} />
                        </Field>

                        <Field
                            label={tc('console.email_address_f2488fd4')}
                            error="That address is already in use."
                        >
                            <Input type="email" defaultValue="ada@example.com" />
                        </Field>

                        <Field label={tc('console.protocol_cf088334')}>
                            <Select
                                value={protocol}
                                onValueChange={setProtocol}
                                options={[
                                    {
                                        value: 'oidc',
                                        label: tc('console.openid_connect_50a8a027'),
                                        hint: tc('console.recommended_d70604e8'),
                                    },
                                    { value: 'saml', label: 'SAML 2.0' },
                                ]}
                            />
                        </Field>

                        <Field
                            label={tc('console.organization_d764d425')}
                            hint={tc('console.forty_of_them_type_to_narrow_eb4313eb')}
                        >
                            <Combobox
                                value={org}
                                onValueChange={setOrg}
                                options={organizations}
                                placeholder={tc('console.find_an_organization_eb2523db')}
                            />
                        </Field>

                        <Field label={tc('console.notes_8a7525b1')}>
                            <Textarea
                                placeholder={tc('console.why_this_change_was_made_708b8919')}
                            />
                        </Field>

                        <Field label={tc('console.require_mfa_e35be609')}>
                            <Switch checked={mfa} onCheckedChange={setMfa} />
                        </Field>

                        <Checkbox
                            checked={invite}
                            onCheckedChange={setInvite}
                            label={tc('console.send_an_invitation_email_52a433c4')}
                            hint={tc('console.they_can_also_be_given_the_link_directly_fac83540')}
                        />

                        <RadioGroup
                            label={tc('console.access_ec5ba0ab')}
                            value={access}
                            onValueChange={setAccess}
                            options={[
                                {
                                    value: 'read',
                                    label: tc('console.read_only_8ac76735'),
                                    hint: tc('console.can_see_cannot_change_6b65059a'),
                                },
                                { value: 'write', label: tc('console.read_and_write_822da963') },
                            ]}
                        />

                        <Divider>{tc('console.or_7175517a')}</Divider>

                        <Button variant="primary">{tc('console.save_changes_dd0ae7a5')}</Button>
                    </div>
                </Panel>

                <Panel title={tc('console.overlays_975c5697')}>
                    <Row>
                        <Button onClick={() => setDialog(true)}>
                            {tc('console.open_a_dialog_fd3b6ed9')}
                        </Button>
                        <Button variant="danger" onClick={() => setConfirm(true)}>
                            {tc('console.type_to_confirm_48b30d9e')}
                        </Button>

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button icon="chevron">{tc('console.menu_99af6606')}</Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent>
                                <DropdownMenuLabel>
                                    {tc('console.endpoint_3df9726c')}
                                </DropdownMenuLabel>
                                <DropdownMenuItem>
                                    {tc('console.pause_deliveries_7c4fa955')}
                                </DropdownMenuItem>
                                <DropdownMenuItem>
                                    {tc('console.rotate_signing_secret_ca38c242')}
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem destructive>
                                    {tc('console.delete_endpoint_6772d678')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <Tooltip content="Deliveries are retried for 24 hours.">
                            <Button>{tc('console.hover_me_1d8fb154')}</Button>
                        </Tooltip>

                        <Button onClick={() => toast.success('Signing secret rotated.')}>
                            {tc('console.toast_34b86033')}
                        </Button>
                        <Button
                            variant="danger"
                            onClick={() => toast.error('The endpoint refused the delivery.')}
                        >
                            {tc('console.error_toast_28f5081d')}
                        </Button>
                    </Row>
                </Panel>

                <Panel title={tc('console.tabs_and_tables_83cac9b9')} flush>
                    <div style={{ padding: '0 20px' }}>
                        <Tabs
                            value={tab}
                            onValueChange={setTab}
                            label={tc('console.delivery_status_045b8761')}
                        >
                            <Tab value="all">{tc('console.all_a52ace42')}</Tab>
                            <Tab value="failed">{tc('console.failed_031a8f0f')}</Tab>
                            <Tab value="pending">{tc('console.pending_331551b0')}</Tab>
                        </Tabs>
                    </div>

                    <Table caption={tc('console.recent_deliveries_9d067ec8')}>
                        <thead>
                            <tr>
                                <Th>{tc('console.event_4e1f49a9')}</Th>
                                <Th>{tc('console.status_920e413c')}</Th>
                                <Th>{tc('console.delivered_90611565')}</Th>
                                <Th>{tc('console.id_12156e92')}</Th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <Td>user.created</Td>
                                <Td>
                                    <Pill tone="success">200</Pill>
                                </Td>
                                <TdMono>2026-08-25 14:02:11</TdMono>
                                <TdMono>{tc('console.evt_01jq8z_bf571fef')}</TdMono>
                            </tr>
                            <tr>
                                <Td>user.deleted</Td>
                                <Td>
                                    <Pill tone="destructive">502</Pill>
                                </Td>
                                <TdMono>2026-08-25 13:58:04</TdMono>
                                <TdMono>{tc('console.evt_01jq8y_75ce0a42')}</TdMono>
                            </tr>
                        </tbody>
                    </Table>
                </Panel>

                <Panel title={tc('console.details_45989de4')}>
                    <KvList>
                        <Kv label={tc('console.endpoint_3df9726c')}>
                            https://example.com/hooks/cbox
                        </Kv>
                        <Kv label={tc('console.created_d70b9e24')}>2026-04-02 09:14:55</Kv>
                        <Kv label={tc('console.purpose_d4e8830a')} prose>
                            {tc('console.notifies_the_billing_system_when_a_member_is_01c4ae1e')}
                        </Kv>
                    </KvList>
                </Panel>

                <Panel title={tc('console.nothing_here_yet_49abaf80')} flush>
                    <EmptyState
                        icon="webhooks"
                        title={tc('console.no_endpoints_6bda3861')}
                        description={tc(
                            'console.nothing_is_listening_for_events_in_this_envi_e2c191f4',
                        )}
                        steps={[
                            'Add an endpoint URL that can receive a POST.',
                            'Choose which events it should hear about.',
                            'Verify the signature using the secret shown once on creation.',
                        ]}
                        actions={
                            <>
                                <Button variant="primary" icon="plus">
                                    {tc('console.add_an_endpoint_b47f5651')}
                                </Button>
                                <Button>{tc('console.read_the_guide_83b3b277')}</Button>
                            </>
                        }
                    />
                </Panel>

                <Panel
                    title={tc('console.icons_eae96e02')}
                    description={tc(
                        'console.one_family_one_stroke_weight_add_to_resource_d89cf01e',
                    )}
                >
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
                title={tc('console.rotate_the_signing_secret_8bd998fb')}
                description={tc('console.the_current_secret_stops_working_immediately_a6493eff')}
                footer={
                    <>
                        <Button onClick={() => setDialog(false)}>
                            {tc('console.cancel_19766ed6')}
                        </Button>
                        <Button variant="primary">{tc('console.rotate_c3613b17')}</Button>
                    </>
                }
            >
                <Field
                    label={tc('console.why_d3ae14a5')}
                    hint={tc('console.recorded_in_the_audit_log_43143948')}
                >
                    <Input
                        placeholder={tc('console.rotating_after_the_leak_on_24_august_d5dbbc2b')}
                    />
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
