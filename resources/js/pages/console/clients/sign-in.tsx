import { useTranslator } from '@/i18n';
import { useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { lines } from '@/lib/apiSnippets';
import type { PageProps } from '@/types';
import { ApiEquivalent, Button, Checkbox, Field, Input, Panel, Textarea } from '@/ui';
import { AppFrame, type AppHeaderData } from './frame';

type Props = PageProps<{
    appHeader: AppHeaderData;
    /** Whether the app is returned to a browser at all (it holds the authorization code grant). */
    browserSignIn: boolean;
    redirectUris: string;
    postLogoutRedirectUris: string;
    logout: { uri: string; sessionRequired: boolean; href: string };
    urls: { update: string };
}>;

/**
 * AN APP › SIGN-IN & LOGOUT — the addresses around a person's session, in the order a
 * session uses them: where Cbox ID may send them after signing in, where after signing out,
 * and where the app is told a session ended elsewhere.
 *
 * TWO FORMS, as before: the URIs save through `apps.update` (only the URI fields, so a name
 * changed on Settings meanwhile is not written back), the back-channel endpoint through
 * `apps.settings.logout`.
 */
export default function ClientSignIn({
    appHeader,
    browserSignIn,
    redirectUris,
    postLogoutRedirectUris,
    logout,
    urls,
}: Props) {
    const { t, rich } = useTranslator();

    const uris = useForm({ redirectUris, postLogoutRedirectUris });
    const backchannel = useForm({ uri: logout.uri, sessionRequired: logout.sessionRequired });

    const perLine = (
        <span style={{ color: 'var(--faint)', fontWeight: 400 }}>
            {t('console._one_per_line_256f7ece')}
        </span>
    );

    return (
        <AppFrame app={appHeader}>
            {!browserSignIn && (
                <Panel>
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {t('console.app_sign_in_no_browser')}
                    </p>
                </Panel>
            )}

            <Panel
                title={t('console.app_sign_in_uris_title')}
                description={t('console.app_sign_in_uris_lead')}
            >
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        uris.patch(urls.update, { preserveScroll: true });
                    }}
                >
                    <Field
                        label={rich('console.redirect_uris_part1_part2_428a2fa5', {
                            part1: ' ',
                            part2: perLine,
                        })}
                        hint={t('console.app_sign_in_redirect_hint')}
                        error={uris.errors.redirectUris}
                    >
                        <Textarea
                            name="redirectUris"
                            rows={3}
                            className="mono"
                            spellCheck={false}
                            placeholder="https://app.example.com/auth/callback"
                            value={uris.data.redirectUris}
                            onChange={(event) => uris.setData('redirectUris', event.target.value)}
                        />
                    </Field>

                    <Field
                        label={rich('console.sign_out_uris_part1_part2_e713791a', {
                            part1: ' ',
                            part2: perLine,
                        })}
                        hint={t(
                            'console.where_cbox_id_sends_people_after_they_sign_out_of_this_3a65532f',
                        )}
                        error={uris.errors.postLogoutRedirectUris}
                    >
                        <Textarea
                            name="postLogoutRedirectUris"
                            rows={3}
                            className="mono"
                            spellCheck={false}
                            placeholder="https://app.example.com/signed-out"
                            value={uris.data.postLogoutRedirectUris}
                            onChange={(event) =>
                                uris.setData('postLogoutRedirectUris', event.target.value)
                            }
                        />
                    </Field>

                    <div className="flex flex-wrap items-start gap-2">
                        <Button type="submit" variant="primary" loading={uris.processing}>
                            {t('console.app_sign_in_save_uris')}
                        </Button>
                        <ApiEquivalent
                            action="apps.update"
                            values={{
                                redirect_uris: lines(uris.data.redirectUris),
                                post_logout_redirect_uris: lines(uris.data.postLogoutRedirectUris),
                            }}
                        />
                    </div>
                </form>
            </Panel>

            <Panel
                title={t('console.back_channel_logout_ac979af8')}
                description={t('console.when_somebody_signs_out_or_an_administrator__3520d247')}
            >
                <form
                    className="space-y-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        backchannel.put(logout.href, { preserveScroll: true });
                    }}
                >
                    <Field
                        label={t('console.logout_uri_29094b69')}
                        optional
                        hint={t('console.https_or_http_on_localhost_leave_empty_and_t_6d61df22')}
                        error={backchannel.errors.uri}
                    >
                        <Input
                            name="uri"
                            type="url"
                            className="mono"
                            spellCheck={false}
                            placeholder="https://app.example.com/auth/backchannel-logout"
                            value={backchannel.data.uri}
                            onChange={(event) => backchannel.setData('uri', event.target.value)}
                        />
                    </Field>
                    <Checkbox
                        checked={backchannel.data.sessionRequired}
                        disabled={backchannel.data.uri.trim() === ''}
                        onCheckedChange={(checked) =>
                            backchannel.setData('sessionRequired', checked)
                        }
                        label={t('console.the_app_needs_the_session_id_8983946c')}
                        hint={t('console.every_logout_token_then_names_the_session_si_54d0a907')}
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="primary"
                        loading={backchannel.processing}
                    >
                        {t('console.save_logout_b29b9b19')}
                    </Button>
                </form>
            </Panel>
        </AppFrame>
    );
}

ClientSignIn.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
