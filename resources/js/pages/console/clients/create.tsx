import { useTranslator } from '@/i18n';
import { Link, useForm } from '@inertiajs/react';
import { useMemo } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { OrganizationPicker, PageProps } from '@/types';
import { lines } from '@/lib/apiSnippets';
import {
    ApiEquivalent,
    Breadcrumb,
    Button,
    Checkbox,
    Disclosure,
    Field,
    Input,
    OrganizationPickerField,
    Panel,
    RadioGroup,
    Textarea,
} from '@/ui';

interface AppKindOption {
    value: string;
    label: string;
    description: string;
    needsRedirectUris: boolean;
    confidential: boolean;
    defaultScopes: string[];
    /** How this kind's sign-in actually goes, in a few lines with `<b>` emphasis. */
    flow: string[];
}

interface ScopeOption {
    key: string;
    label: string;
    description: string;
    category: string;
    recommended: boolean;
}

type Props = PageProps<{
    scopeGroups: Record<string, ScopeOption[]>;
    appKinds: AppKindOption[];
    /** Only a plane that CHOOSES an organization may register an environment-wide app. */
    mayScopeEnvironmentWide: boolean;
    /** "For which organization?" — the environment console only; null where the form is about one already. */
    organization: OrganizationPicker | null;
    indexHref: string;
    storeHref: string;
}>;

export default function CreateClient({
    scopeGroups,
    appKinds,
    mayScopeEnvironmentWide,
    organization,
    indexHref,
    storeHref,
}: Props) {
    const { t: tc } = useTranslator();

    const form = useForm({
        name: '',
        // The ONE question. Everything the specification calls a decision follows from it.
        kind: appKinds[0]?.value ?? 'web',
        type: 'confidential',
        grantAuthorizationCode: true,
        grantClientCredentials: false,
        scopes: appKinds[0]?.defaultScopes ?? ['openid', 'profile', 'email', 'offline_access'],
        customScopes: '',
        redirectUris: '',
        postLogoutRedirectUris: '',
        manifestUrl: '',
        firstParty: false,
        environmentWide: false,
        organization: organization?.selected?.id ?? '',
    });

    const kind = useMemo(
        () => appKinds.find((candidate) => candidate.value === form.data.kind) ?? appKinds[0],
        [appKinds, form.data.kind],
    );

    const advanced = form.data.kind === 'advanced';

    // Asked of the KIND, not of a checkbox: a CLI has no callback URL, and the field
    // asking for one was what made people believe they had chosen wrong.
    const needsRedirectUris = advanced
        ? form.data.grantAuthorizationCode
        : kind?.needsRedirectUris === true;

    /**
     * Follow the chosen kind, unless the person has already disagreed with it.
     *
     * Re-applying the defaults on every change would silently undo a deliberate tick, so
     * the scopes are only rewritten while they still ARE the previous kind's defaults.
     * Somebody who has curated the list keeps their list.
     */
    const chooseKind = (next: string): void => {
        const previous = appKinds.find((candidate) => candidate.value === form.data.kind);
        const chosen = appKinds.find((candidate) => candidate.value === next);

        const untouched =
            previous !== undefined &&
            [...form.data.scopes].sort().join() === [...previous.defaultScopes].sort().join();

        form.setData((current) => ({
            ...current,
            kind: next,
            scopes: untouched && chosen !== undefined ? chosen.defaultScopes : current.scopes,
            // Only Advanced answers this by hand.
            type:
                chosen === undefined || next === 'advanced'
                    ? current.type
                    : chosen.confidential
                      ? 'confidential'
                      : 'public',
        }));
    };

    const toggleScope = (key: string, checked: boolean): void => {
        form.setData(
            'scopes',
            checked ? [...form.data.scopes, key] : form.data.scopes.filter((s) => s !== key),
        );
    };

    return (
        <>
            <Breadcrumb href={indexHref} label={tc('console.applications_98e33b0f')} />

            <h1 className="cbx-page-title mt-2">{tc('console.new_app_747f1473')}</h1>
            {/*
                The old subtitle named exactly two modes — "signing people in" and
                "machine-to-machine" — which is the same two-checkbox model this form has
                stopped using, and it excluded a CLI and an agent by omission. It also
                promised a client secret, which a public app never receives.
            */}
            <p className="mt-1 text-sm" style={{ color: 'var(--muted-foreground)' }}>
                {tc('console.register_an_app_so_it_can_sign_your_people_i_48568d69')}
            </p>

            <form
                className="mt-6 space-y-6"
                style={{ maxWidth: '42rem' }}
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(storeHref);
                }}
            >
                <Panel>
                    <Field label={tc('console.app_name_e6ad3996')} error={form.errors.name}>
                        <Input
                            name="name"
                            placeholder={tc('console.acme_dashboard_0b433bd7')}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                    </Field>

                    <div className="mt-5">
                        <RadioGroup
                            label={tc('console.what_kind_of_app_is_it_d7d847f5')}
                            value={form.data.kind}
                            onValueChange={chooseKind}
                            options={appKinds.map((option) => ({
                                value: option.value,
                                label: option.label,
                                hint: option.description,
                            }))}
                        />
                    </div>

                    {/*
                        WHAT WILL HAPPEN, in the words of the flow this kind uses. The
                        picker asks one question and decides four things; this is where the
                        person can check that the answer matches the app in their head.
                    */}
                    {kind !== undefined && kind.flow.length > 0 && (
                        <div
                            className="mt-4 rounded-lg p-3 text-sm"
                            style={{ background: 'var(--secondary)' }}
                        >
                            <ol className="space-y-1" style={{ listStyle: 'decimal inside' }}>
                                {kind.flow.map((step) => (
                                    // The copy carries `<b>` emphasis from the server. It is
                                    // a fixed string in the enum, not user input — there is
                                    // no path by which a person's text reaches it.
                                    <li
                                        key={step}
                                        // eslint-disable-next-line react/no-danger
                                        dangerouslySetInnerHTML={{ __html: step }}
                                    />
                                ))}
                            </ol>
                            <p className="mt-2 text-xs" style={{ color: 'var(--faint)' }}>
                                {kind.confidential
                                    ? tc(
                                          'console.it_gets_a_client_secret_shown_once_on_the_ne_356c8ac4',
                                      )
                                    : tc(
                                          'console.it_uses_pkce_and_gets_no_secret_nothing_to_l_f9fee647',
                                      )}
                            </p>
                        </div>
                    )}
                </Panel>

                {advanced && (
                    <Panel
                        title={tc('console.advanced_9f088dbe')}
                        description={tc(
                            'console.answer_the_specification_s_questions_yoursel_1dc57b65',
                        )}
                    >
                        <RadioGroup
                            label={tc('console.client_type_40a3b0cb')}
                            value={form.data.type}
                            onValueChange={(type) => form.setData('type', type)}
                            options={[
                                {
                                    value: 'confidential',
                                    label: tc('console.confidential_a3e83aee'),
                                    hint: tc(
                                        'console.runs_on_a_server_and_can_keep_a_secret_85e431b3',
                                    ),
                                },
                                {
                                    value: 'public',
                                    label: tc('console.public_591935b1'),
                                    hint: tc(
                                        'console.runs_in_a_browser_a_phone_or_a_terminal_pkce_67b3e26b',
                                    ),
                                },
                            ]}
                        />

                        <div className="mt-4 space-y-2">
                            <Checkbox
                                checked={form.data.grantAuthorizationCode}
                                onCheckedChange={(checked) =>
                                    form.setData('grantAuthorizationCode', checked)
                                }
                                label={tc(
                                    'console.signs_people_in_authorization_code_refresh_a9856e92',
                                )}
                            />
                            <Checkbox
                                checked={form.data.grantClientCredentials}
                                onCheckedChange={(checked) =>
                                    form.setData('grantClientCredentials', checked)
                                }
                                label={tc(
                                    'console.calls_the_api_as_itself_client_credentials_e2e7fd78',
                                )}
                            />
                            {form.errors.grantAuthorizationCode !== undefined && (
                                <p className="field-error" role="alert">
                                    {form.errors.grantAuthorizationCode}
                                </p>
                            )}
                        </div>
                    </Panel>
                )}

                {needsRedirectUris && (
                    <Panel
                        title={tc('console.where_cbox_id_may_return_people_to_a974f36d')}
                        description={tc(
                            'console.one_url_per_line_exact_matches_only_https_or_d1855423',
                        )}
                    >
                        <Field
                            label={tc('console.redirect_uris_721353f1')}
                            error={form.errors.redirectUris}
                        >
                            <Textarea
                                name="redirectUris"
                                rows={3}
                                spellCheck={false}
                                placeholder="https://app.example.com/auth/callback"
                                value={form.data.redirectUris}
                                onChange={(event) =>
                                    form.setData('redirectUris', event.target.value)
                                }
                            />
                        </Field>

                        <div className="mt-4">
                            <Disclosure
                                title={tc('console.sign_out_uris_8a668dbe')}
                                summary={
                                    form.data.postLogoutRedirectUris ||
                                    tc('console.ux_advanced_help')
                                }
                                hasError={Boolean(form.errors.postLogoutRedirectUris)}
                            >
                                <Field
                                    label={tc('console.sign_out_uris_8a668dbe')}
                                    hint={tc(
                                        'console.optional_where_sign_out_may_send_people_back_6ccaeb48',
                                    )}
                                    error={form.errors.postLogoutRedirectUris}
                                >
                                    <Textarea
                                        name="postLogoutRedirectUris"
                                        rows={2}
                                        spellCheck={false}
                                        placeholder="https://app.example.com/signed-out"
                                        value={form.data.postLogoutRedirectUris}
                                        onChange={(event) =>
                                            form.setData(
                                                'postLogoutRedirectUris',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </Disclosure>
                        </div>
                    </Panel>
                )}

                <Disclosure
                    title={tc('console.what_it_may_ask_for_d84366ae')}
                    summary={tc('console.ux_scopes_summary', { count: form.data.scopes.length })}
                    hasError={Object.keys(form.errors).some(
                        (key) => key.startsWith('scopes') || key === 'customScopes',
                    )}
                >
                    <p className="text-sm text-[var(--muted-foreground)] mb-4">
                        {tc('console.the_ceiling_on_this_app_s_tokens_it_can_ask__87966742')}
                    </p>
                    {form.errors.scopes && (
                        <p className="field-error mb-3" role="alert">
                            {form.errors.scopes}
                        </p>
                    )}
                    <div className="space-y-5">
                        {Object.entries(scopeGroups).map(([category, scopes]) => (
                            <div key={category}>
                                <p className="cbx-nav-group mb-2">{category}</p>
                                <div className="space-y-2">
                                    {scopes.map((scope) => (
                                        <Checkbox
                                            key={scope.key}
                                            checked={form.data.scopes.includes(scope.key)}
                                            onCheckedChange={(checked) =>
                                                toggleScope(scope.key, checked)
                                            }
                                            label={scope.label}
                                            hint={scope.description}
                                        />
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>

                    {advanced && (
                        <div className="mt-5">
                            <Field
                                label={tc('console.custom_scopes_2c8e3e2d')}
                                hint={tc(
                                    'console.comma_separated_only_for_keys_this_catalogue_5b436f7f',
                                )}
                                error={form.errors.customScopes}
                            >
                                <Input
                                    name="customScopes"
                                    spellCheck={false}
                                    placeholder={tc('console.billing_read_reports_write_36538bcc')}
                                    value={form.data.customScopes}
                                    onChange={(event) =>
                                        form.setData('customScopes', event.target.value)
                                    }
                                />
                            </Field>
                        </div>
                    )}
                </Disclosure>

                <Disclosure
                    title={tc('console.roles_c2533705')}
                    summary={
                        form.data.manifestUrl ||
                        tc('console.where_this_app_publishes_the_roles_it_unders_e6047562')
                    }
                    hasError={Boolean(form.errors.manifestUrl)}
                >
                    <Field
                        label={tc('console.manifest_url_b2e2812a')}
                        hint={tc('console.optional_cbox_id_fetches_it_now_and_on_a_sch_5becfdd5')}
                        error={form.errors.manifestUrl}
                    >
                        <Input
                            name="manifestUrl"
                            type="url"
                            spellCheck={false}
                            placeholder="https://app.example.com/.well-known/cbox-roles.json"
                            value={form.data.manifestUrl}
                            onChange={(event) => form.setData('manifestUrl', event.target.value)}
                        />
                    </Field>
                </Disclosure>

                {/*
                    The environment plane's own capability. An app with no organization is
                    the platform's own: marked first-party it skips the consent screen for
                    EVERY organization here — so it
                    is offered only where somebody holds all of them.
                */}
                {mayScopeEnvironmentWide && (
                    <Panel title={tc('console.who_owns_it_fad05e53')}>
                        <div className="space-y-2">
                            <Checkbox
                                checked={form.data.environmentWide}
                                onCheckedChange={(checked) =>
                                    form.setData('environmentWide', checked)
                                }
                                label={tc(
                                    'console.register_it_to_this_environment_rather_than__13e1bc4d',
                                )}
                                hint={tc(
                                    'console.an_environment_app_not_one_organization_s_3b087485',
                                )}
                            />
                            {organization !== null && !form.data.environmentWide && (
                                <OrganizationPickerField
                                    picker={organization}
                                    error={form.errors.organization}
                                    onChange={(id) => form.setData('organization', id)}
                                    hint={tc(
                                        'console.the_organization_the_app_belongs_to_or_regis_d0bd8e48',
                                    )}
                                />
                            )}
                            <Checkbox
                                checked={form.data.firstParty}
                                onCheckedChange={(checked) => form.setData('firstParty', checked)}
                                label={tc('console.first_party_ee374836')}
                                hint={tc(
                                    'console.skips_the_consent_screen_and_appears_in_ever_18ea1562',
                                )}
                            />
                        </div>
                    </Panel>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <Button type="submit" variant="primary" loading={form.processing}>
                        {tc('console.create_app_814eb4f6')}
                    </Button>
                    <Button asChild>
                        <Link href={indexHref}>{tc('console.cancel_19766ed6')}</Link>
                    </Button>
                </div>

                {/* The same app, created from code — with what this form holds now. */}
                <ApiEquivalent
                    action="apps.create"
                    values={{
                        name: form.data.name,
                        type: form.data.kind,
                        redirect_uris: lines(form.data.redirectUris),
                        post_logout_redirect_uris: lines(form.data.postLogoutRedirectUris),
                        scopes: [
                            ...form.data.scopes,
                            ...form.data.customScopes.split(/[\s,]+/).filter(Boolean),
                        ],
                        manifest_url: form.data.manifestUrl,
                        first_party: form.data.firstParty || undefined,
                    }}
                />
            </form>
        </>
    );
}

CreateClient.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
