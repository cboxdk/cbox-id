import { useTranslator } from '@/i18n';
import { useForm } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, Checkbox, Disclosure, Field, Input, Panel, Switch } from '@/ui';
import { AppFrame, type AppHeaderData } from './frame';

type Props = PageProps<{
    appHeader: AppHeaderData;
    lifetime: {
        /** Null: the install's default. */
        minutes: number | null;
        defaultMinutes: number;
        ceilingMinutes: number;
        href: string;
    };
    exchange: { enabled: boolean; available: boolean; href: string };
    logout: { uri: string; sessionRequired: boolean; href: string };
    apiKeys: { prefix: string; href: string };
}>;

/**
 * FOUR FORMS, each saving one setting — so saving one never writes back a value another
 * form changed since the page loaded, and each change is one line on the activity log.
 */
export default function ClientSettings({ appHeader, lifetime, exchange, logout, apiKeys }: Props) {
    return (
        <AppFrame app={appHeader}>
            <LifetimeForm lifetime={lifetime} />
            <ExchangeForm exchange={exchange} />
            <LogoutForm logout={logout} />
            <ApiKeysForm apiKeys={apiKeys} />
        </AppFrame>
    );
}

function LifetimeForm({ lifetime }: { lifetime: Props['lifetime'] }) {
    const { t: tc } = useTranslator();

    const form = useForm({ minutes: lifetime.minutes === null ? '' : String(lifetime.minutes) });

    return (
        <Disclosure
            title={tc('console.access_token_lifetime_3d0f7e0e')}
            summary={`${lifetime.minutes ?? lifetime.defaultMinutes} ${tc('console.minutes_4f846a84').toLowerCase()}`}
            hasError={Boolean(form.errors.minutes)}
        >
            <p className="text-sm text-[var(--muted-foreground)] mb-4">
                {tc('console.how_long_an_access_token_for_this_app_is_acc_22748384')}
            </p>
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(lifetime.href, { preserveScroll: true });
                }}
            >
                <Field
                    label={tc('console.minutes_4f846a84')}
                    optional
                    hint={tc('console.leave_empty_for_this_install_s_default_value_fda04f89', {
                        value0: lifetime.defaultMinutes,
                        value1: lifetime.ceilingMinutes,
                    })}
                    error={form.errors.minutes}
                >
                    <Input
                        name="minutes"
                        type="number"
                        inputMode="numeric"
                        min={1}
                        max={lifetime.ceilingMinutes}
                        placeholder={String(lifetime.defaultMinutes)}
                        style={{ maxWidth: '12rem' }}
                        value={form.data.minutes}
                        onChange={(event) => form.setData('minutes', event.target.value)}
                    />
                </Field>
                <Button type="submit" size="sm" variant="primary" loading={form.processing}>
                    {tc('console.save_lifetime_efe22d70')}
                </Button>
            </form>
        </Disclosure>
    );
}

function ExchangeForm({ exchange }: { exchange: Props['exchange'] }) {
    const { t: tc } = useTranslator();

    const form = useForm({ enabled: exchange.enabled });

    return (
        <Disclosure
            title={tc('console.token_exchange_791311dd')}
            summary={tc(exchange.enabled ? 'console.on_13001175' : 'console.off_ca7981b4')}
            hasError={Boolean(form.errors.enabled)}
        >
            <p className="text-sm text-[var(--muted-foreground)] mb-4">
                {tc('console.lets_this_app_trade_a_token_it_was_given_for_e3add8e6')}
            </p>
            {exchange.available ? (
                <form
                    className="flex flex-wrap items-center gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(exchange.href, { preserveScroll: true });
                    }}
                >
                    <label className="flex items-center gap-2 text-sm">
                        <Switch
                            name="enabled"
                            checked={form.data.enabled}
                            onCheckedChange={(checked) => form.setData('enabled', checked)}
                        />
                        {form.data.enabled ? tc('console.on_13001175') : tc('console.off_ca7981b4')}
                    </label>
                    <Button
                        type="submit"
                        size="sm"
                        variant="primary"
                        loading={form.processing}
                        disabled={form.data.enabled === exchange.enabled}
                    >
                        {tc('console.save_1509f561')}
                    </Button>
                    {form.errors.enabled !== undefined && (
                        <p
                            className="w-full text-sm"
                            style={{ color: 'var(--destructive-strong)' }}
                        >
                            {form.errors.enabled}
                        </p>
                    )}
                </form>
            ) : (
                <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                    {tc('console.not_available_for_this_app_only_an_app_that__9bac58d4')}
                </p>
            )}
        </Disclosure>
    );
}

function LogoutForm({ logout }: { logout: Props['logout'] }) {
    const { t: tc } = useTranslator();

    const form = useForm({ uri: logout.uri, sessionRequired: logout.sessionRequired });

    return (
        <Disclosure
            title={tc('console.back_channel_logout_ac979af8')}
            summary={logout.uri || tc('console.ux_not_configured')}
            hasError={Object.keys(form.errors).length > 0}
        >
            <p className="text-sm text-[var(--muted-foreground)] mb-4">
                {tc('console.when_somebody_signs_out_or_an_administrator__3520d247')}
            </p>
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(logout.href, { preserveScroll: true });
                }}
            >
                <Field
                    label={tc('console.logout_uri_29094b69')}
                    optional
                    hint={tc('console.https_or_http_on_localhost_leave_empty_and_t_6d61df22')}
                    error={form.errors.uri}
                >
                    <Input
                        name="uri"
                        type="url"
                        className="mono"
                        spellCheck={false}
                        placeholder="https://app.example.com/auth/backchannel-logout"
                        value={form.data.uri}
                        onChange={(event) => form.setData('uri', event.target.value)}
                    />
                </Field>
                <Checkbox
                    checked={form.data.sessionRequired}
                    disabled={form.data.uri.trim() === ''}
                    onCheckedChange={(checked) => form.setData('sessionRequired', checked)}
                    label={tc('console.the_app_needs_the_session_id_8983946c')}
                    hint={tc('console.every_logout_token_then_names_the_session_si_54d0a907')}
                />
                <Button type="submit" size="sm" variant="primary" loading={form.processing}>
                    {tc('console.save_logout_b29b9b19')}
                </Button>
            </form>
        </Disclosure>
    );
}

function ApiKeysForm({ apiKeys }: { apiKeys: Props['apiKeys'] }) {
    const { t: tc } = useTranslator();

    const form = useForm({ prefix: apiKeys.prefix });
    const example = form.data.prefix.trim() === '' ? 'acme_live' : form.data.prefix.trim();

    return (
        <Panel
            title={tc('console.api_keys_98aae897')}
            description={tc('console.lets_the_people_who_use_this_app_create_api__ba2ebdce')}
        >
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(apiKeys.href, { preserveScroll: true });
                }}
            >
                <Field
                    label={tc('console.key_prefix_ac467d48')}
                    optional
                    hint={
                        <>
                            {tc('console.keys_start_with_it_so_a_key_found_in_a_log_n_c5f1fe6b')}{' '}
                            <code className="mono">{example}_…</code>
                            {tc('console.2_16_lowercase_letters_or_digits_then_82874c44')}
                            <code className="mono">{tc('console.live_faa88433')}</code>
                            {tc('console.or_a1812817')}{' '}
                            <code className="mono">{tc('console.test_f51b9c48')}</code>
                            {tc('console.unique_in_this_environment_setting_one_turns_1ba7348b')}
                        </>
                    }
                    error={form.errors.prefix}
                >
                    <Input
                        name="prefix"
                        className="mono"
                        spellCheck={false}
                        autoCapitalize="off"
                        placeholder={tc('console.acme_live_0b5e7383')}
                        style={{ maxWidth: '16rem' }}
                        value={form.data.prefix}
                        onChange={(event) => form.setData('prefix', event.target.value)}
                    />
                </Field>
                <Button type="submit" size="sm" variant="primary" loading={form.processing}>
                    {tc('console.save_prefix_12fbcbdd')}
                </Button>
            </form>
        </Panel>
    );
}

ClientSettings.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
