import { useTranslator } from '@/i18n';
import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps } from '@/types';
import { Button, ConfirmDelete, Disclosure, Field, Input, Panel, Switch } from '@/ui';
import { AppFrame, type AppHeaderData } from './frame';

type Props = PageProps<{
    appHeader: AppHeaderData;
    name: string;
    lifetime: {
        /** Null: the install's default. */
        minutes: number | null;
        defaultMinutes: number;
        ceilingMinutes: number;
        href: string;
    };
    exchange: { enabled: boolean; available: boolean; href: string };
    apiKeys: { prefix: string; href: string };
    urls: { update: string; destroy: string };
}>;

/**
 * ONE FORM PER SETTING, each saving only its own — so saving one never writes back a value
 * another form changed since the page loaded, and each change is one line on the activity
 * log. Where people are sent after signing in and out, and back-channel logout, are on the
 * Sign-in & logout tab; deleting the app is last, set apart.
 */
export default function ClientSettings({
    appHeader,
    name,
    lifetime,
    exchange,
    apiKeys,
    urls,
}: Props) {
    return (
        <AppFrame app={appHeader}>
            <NameForm name={name} href={urls.update} />
            <LifetimeForm lifetime={lifetime} />
            <ExchangeForm exchange={exchange} />
            <ApiKeysForm apiKeys={apiKeys} />
            <DangerZone name={name} href={urls.destroy} />
        </AppFrame>
    );
}

function NameForm({ name, href }: { name: string; href: string }) {
    const { t } = useTranslator();
    const form = useForm({ name });

    return (
        <Panel title={t('console.app_settings_general')}>
            <form
                className="space-y-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(href, { preserveScroll: true });
                }}
            >
                <Field label={t('console.name_dcd1d522')} error={form.errors.name}>
                    <Input
                        name="name"
                        style={{ maxWidth: '28rem' }}
                        value={form.data.name}
                        onChange={(event) => form.setData('name', event.target.value)}
                    />
                </Field>
                <Button
                    type="submit"
                    size="sm"
                    variant="primary"
                    loading={form.processing}
                    disabled={form.data.name.trim() === name}
                >
                    {t('console.app_settings_save_name')}
                </Button>
            </form>
        </Panel>
    );
}

/** Deleting the app: last on the page, apart from the settings, behind its name typed out. */
function DangerZone({ name, href }: { name: string; href: string }) {
    const { t } = useTranslator();
    const [confirming, setConfirming] = useState(false);

    return (
        <Panel
            title={t('console.app_danger_zone')}
            description={t(
                'console.anything_using_its_credentials_will_stop_working_this_c_da65f419',
            )}
        >
            <Button size="sm" variant="danger" onClick={() => setConfirming(true)}>
                {t('console.delete_app_70ddc821')}
            </Button>
            <ConfirmDelete
                open={confirming}
                onOpenChange={setConfirming}
                name={name}
                consequence={t(
                    'console.anything_using_this_app_s_credentials_will_stop_working_1ea63206',
                )}
                onConfirm={() => {
                    setConfirming(false);
                    router.delete(href);
                }}
            />
        </Panel>
    );
}

function LifetimeForm({ lifetime }: { lifetime: Props['lifetime'] }) {
    const { t: tc, locale } = useTranslator();

    const form = useForm({ minutes: lifetime.minutes === null ? '' : String(lifetime.minutes) });

    return (
        <Disclosure
            title={tc('console.access_token_lifetime_3d0f7e0e')}
            summary={new Intl.NumberFormat(locale, {
                style: 'unit',
                unit: 'minute',
                unitDisplay: 'long',
            }).format(lifetime.minutes ?? lifetime.defaultMinutes)}
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

function ApiKeysForm({ apiKeys }: { apiKeys: Props['apiKeys'] }) {
    const { t: tc, rich } = useTranslator();

    const form = useForm({ prefix: apiKeys.prefix });
    const example = form.data.prefix.trim() === '' ? 'acme_live' : form.data.prefix.trim();

    return (
        <Panel
            title={tc('console.api_keys_98aae897')}
            description={tc('console.lets_the_people_who_use_this_app_create_api__6ef80e45')}
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
                            {rich(
                                'console.keys_start_with_it_so_a_key_found_in_a_log_names_this_a_8a69e3c2',
                                {
                                    part1: ' ',
                                    part2: <code className="mono">{example}_…</code>,
                                    part3: <code className="mono">live</code>,
                                    part4: ' ',
                                    part5: <code className="mono">test</code>,
                                },
                            )}
                        </>
                    }
                    error={form.errors.prefix}
                >
                    <Input
                        name="prefix"
                        className="mono"
                        spellCheck={false}
                        autoCapitalize="off"
                        placeholder="acme_live"
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
