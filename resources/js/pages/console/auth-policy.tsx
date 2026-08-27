import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import type { PageProps, Pagination as PaginationState } from '@/types';
import {
    Badge,
    Button,
    Checkbox,
    Dialog,
    Field,
    Input,
    PageHeader,
    Pagination,
    Panel,
    Select,
    Table,
    Td,
    Th,
} from '@/ui';

interface Policy {
    minLength: number;
    requireBreachCheck: boolean;
    /** Empty means "no limit" — the loosest value the field can hold, not zero. */
    maxAgeDays: string;
    reuseHistory: number;
    mfa: string;
    sso: string;
    lockoutThreshold: string;
}

interface OrganizationRow {
    id: string;
    name: string;
    overridden: boolean;
    minLength: number;
    mfa: string;
    sso: string;
}

type Props = PageProps<{
    onEnvironmentPlane: boolean;
    policy: Policy;
    baseline: Policy;
    inheriting: boolean;
    overridden: string[];
    scopeName: string;
    passwordsCurrentlyWork: boolean;
    mfaOptions: { value: string; label: string }[];
    ssoOptions: { value: string; label: string }[];
    organizations: OrganizationRow[] | null;
    organizationsPagination: PaginationState | null;
    saveHref: string;
    inheritHref: string;
}>;

export default function AuthPolicyPage({
    onEnvironmentPlane,
    policy,
    baseline,
    inheriting,
    overridden,
    scopeName,
    passwordsCurrentlyWork,
    mfaOptions,
    ssoOptions,
    organizations,
    organizationsPagination,
    saveHref,
    inheritHref,
}: Props) {
    const form = useForm<Policy>(policy);
    const [confirming, setConfirming] = useState<'lockout' | 'inherit' | null>(null);

    /*
     * Whether saving would sign people out.
     *
     * Passwords work today AND the choice about to be saved refuses them. The second half
     * is the whole rule: an organization already covered by an environment-wide mandate
     * loses nothing by restating it, and asking "are you sure you want to end every
     * session" about a change that ends none is how confirmations become reflexes.
     */
    const endsSessions = passwordsCurrentlyWork && form.data.sso === 'required';

    const save = () => {
        setConfirming(null);
        form.put(saveHref, { preserveScroll: true });
    };

    const badge = (field: string) =>
        overridden.includes(field) ? <Badge className="ml-1">Overridden</Badge> : null;

    return (
        <div className="space-y-6">
            <PageHeader
                description={
                    onEnvironmentPlane
                        ? 'The baseline every organization in this environment inherits. An organization can ask for stricter rules — never looser.'
                        : `How people sign in to ${scopeName}. These start as your environment's defaults; anything you change here can only make them stricter.`
                }
            />

            {/*
                The inheritance state, said out loud before any control is read. An
                administrator looking at "Require SSO: off" cannot otherwise tell whose
                decision that is.
            */}
            {!onEnvironmentPlane && (
                <div
                    className="rounded-xl border p-4 flex flex-wrap items-center justify-between gap-3"
                    style={{ borderColor: 'var(--border)', background: 'var(--surface-2)' }}
                >
                    <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                        {inheriting ? (
                            <>
                                <strong style={{ color: 'var(--foreground)' }}>
                                    Using your environment's defaults.
                                </strong>{' '}
                                Nothing here is set by {scopeName} yet — change anything below and
                                it becomes this organization's own rule.
                            </>
                        ) : (
                            <>
                                <strong style={{ color: 'var(--foreground)' }}>
                                    {scopeName} has its own rules.
                                </strong>{' '}
                                {overridden.length}{' '}
                                {overridden.length === 1 ? 'setting differs' : 'settings differ'}{' '}
                                from your environment's defaults.
                            </>
                        )}
                    </p>

                    {!inheriting && (
                        <Button size="sm" onClick={() => setConfirming('inherit')}>
                            Use environment defaults
                        </Button>
                    )}
                </div>
            )}

            <form
                className="rounded-xl border p-5 space-y-5"
                style={{ borderColor: 'var(--border)' }}
                onSubmit={(event) => {
                    event.preventDefault();

                    if (endsSessions) {
                        setConfirming('lockout');

                        return;
                    }

                    save();
                }}
            >
                <div className="grid sm:grid-cols-2 gap-5">
                    <Field
                        id="min-length"
                        label={<>Minimum password length {badge('minLength')}</>}
                        error={form.errors.minLength}
                        hint={
                            onEnvironmentPlane
                                ? undefined
                                : `Environment default: ${baseline.minLength}. You can require more, not fewer.`
                        }
                    >
                        <Input
                            id="min-length"
                            name="minLength"
                            type="number"
                            min={8}
                            max={128}
                            value={form.data.minLength}
                            onChange={(event) =>
                                form.setData('minLength', Number(event.target.value))
                            }
                        />
                    </Field>

                    <Field
                        id="reuse-history"
                        label={<>Block reuse of the last {badge('reuseHistory')}</>}
                        error={form.errors.reuseHistory}
                        hint={
                            <>
                                Passwords. 0 turns reuse checking off. Only hashes are kept.
                                {!onEnvironmentPlane &&
                                    ` Environment default: ${baseline.reuseHistory}.`}
                            </>
                        }
                    >
                        <Input
                            id="reuse-history"
                            name="reuseHistory"
                            type="number"
                            min={0}
                            max={24}
                            value={form.data.reuseHistory}
                            onChange={(event) =>
                                form.setData('reuseHistory', Number(event.target.value))
                            }
                        />
                    </Field>

                    <Field
                        id="max-age"
                        label={<>Force a change after {badge('maxAgeDays')}</>}
                        error={form.errors.maxAgeDays}
                        hint={
                            <>
                                Days. Leave empty to never force a rotation.
                                {!onEnvironmentPlane &&
                                    ` Environment default: ${baseline.maxAgeDays === '' ? 'never' : `${baseline.maxAgeDays} days`}.`}
                            </>
                        }
                    >
                        <Input
                            id="max-age"
                            name="maxAgeDays"
                            type="number"
                            min={1}
                            max={3650}
                            placeholder="Never"
                            value={form.data.maxAgeDays}
                            onChange={(event) => form.setData('maxAgeDays', event.target.value)}
                        />
                    </Field>

                    <Field
                        id="lockout"
                        label={<>Lock out after {badge('lockoutThreshold')}</>}
                        error={form.errors.lockoutThreshold}
                        hint={
                            <>
                                Failed attempts. Leave empty to disable lockout.
                                {!onEnvironmentPlane &&
                                    ` Environment default: ${baseline.lockoutThreshold === '' ? 'off' : baseline.lockoutThreshold}.`}
                            </>
                        }
                    >
                        <Input
                            id="lockout"
                            name="lockoutThreshold"
                            type="number"
                            min={3}
                            max={100}
                            placeholder="Off"
                            value={form.data.lockoutThreshold}
                            onChange={(event) =>
                                form.setData('lockoutThreshold', event.target.value)
                            }
                        />
                    </Field>

                    <Field
                        label={<>Two-factor authentication {badge('mfa')}</>}
                        error={form.errors.mfa}
                    >
                        <Select
                            value={form.data.mfa}
                            onValueChange={(mfa) => form.setData('mfa', mfa)}
                            options={mfaOptions}
                            aria-label="Two-factor authentication"
                        />
                    </Field>

                    <Field label={<>Single sign-on {badge('sso')}</>} error={form.errors.sso}>
                        <Select
                            value={form.data.sso}
                            onValueChange={(sso) => form.setData('sso', sso)}
                            options={ssoOptions}
                            aria-label="Single sign-on"
                        />
                    </Field>
                </div>

                <Checkbox
                    checked={form.data.requireBreachCheck}
                    onCheckedChange={(checked) => form.setData('requireBreachCheck', checked)}
                    label="Refuse passwords found in known data breaches. Checked against Have I Been Pwned without ever sending the password — if the service is unreachable the sign-up is allowed rather than blocked."
                />
                {form.errors.requireBreachCheck !== undefined && (
                    <p className="field-error" role="alert">
                        {form.errors.requireBreachCheck}
                    </p>
                )}

                <div>
                    <Button type="submit" variant="primary" loading={form.processing}>
                        Save rules
                    </Button>
                </div>
            </form>

            {/* What each organization actually ends up with. */}
            {onEnvironmentPlane && organizations !== null && (
                <Panel
                    title="Per organization"
                    description="The rules in force after this environment's baseline is applied. An organization's own override can only make these stricter."
                >
                    <div className="overflow-x-auto">
                        <Table caption="Sign-in rules in force, per organization">
                            <thead>
                                <tr>
                                    <Th>Organization</Th>
                                    <Th>Min length</Th>
                                    <Th>2FA</Th>
                                    <Th>SSO</Th>
                                </tr>
                            </thead>
                            <tbody>
                                {organizations.length === 0 ? (
                                    <tr>
                                        <Td colSpan={4} style={{ color: 'var(--faint)' }}>
                                            No organizations in this environment yet.
                                        </Td>
                                    </tr>
                                ) : (
                                    organizations.map((row) => (
                                        <tr key={row.id}>
                                            <Td>
                                                {row.name}{' '}
                                                {row.overridden ? (
                                                    <Badge>Override</Badge>
                                                ) : (
                                                    <span
                                                        className="text-xs"
                                                        style={{ color: 'var(--faint)' }}
                                                    >
                                                        inherited
                                                    </span>
                                                )}
                                            </Td>
                                            <Td style={{ fontVariantNumeric: 'tabular-nums' }}>
                                                {row.minLength}
                                            </Td>
                                            <Td>{row.mfa}</Td>
                                            <Td>{row.sso}</Td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </Table>
                    </div>

                    {organizationsPagination !== null && (
                        <div className="mt-4">
                            <Pagination
                                pagination={organizationsPagination}
                                noun="organization"
                                href={(page) => `${window.location.pathname}?page=${page}`}
                            />
                        </div>
                    )}
                </Panel>
            )}

            {/*
                THE CONSEQUENCE, BEFORE THE CHANGE. Turning the mandate on ends every
                password session it governs — `RevokingAuthPolicies` does that on the way
                through the contract — and the person most likely to be holding one is the
                administrator reading this. A native confirm() cannot say that.
            */}
            <Dialog
                open={confirming === 'lockout'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={`This will sign people out of ${scopeName}`}
                description="Requiring SSO refuses every other way in, and ends the sessions that used one."
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>Keep passwords working</Button>
                        <Button variant="danger" onClick={save}>
                            Require SSO and sign everyone out
                        </Button>
                    </>
                }
            >
                <ul className="space-y-1 text-sm list-disc pl-5">
                    <li>
                        Password sign-in stops working for everyone in {scopeName}, immediately.
                    </li>
                    <li>
                        Every session that was opened with a password ends — including yours, if you
                        signed in that way.
                    </li>
                    <li>
                        People get back in through your identity provider. Anyone without one
                        connected cannot sign in at all.
                    </li>
                    <li>
                        You can set this back to "Prefer SSO" or "Both available" at any time;
                        sessions that ended stay ended.
                    </li>
                </ul>
            </Dialog>

            <Dialog
                open={confirming === 'inherit'}
                onOpenChange={(open) => !open && setConfirming(null)}
                title={`Use your environment's defaults for ${scopeName}?`}
                description="This organization's own sign-in rules are dropped, and it is governed by the environment baseline from here on."
                footer={
                    <>
                        <Button onClick={() => setConfirming(null)}>Cancel</Button>
                        <Button
                            variant="danger"
                            onClick={() => {
                                setConfirming(null);
                                router.delete(inheritHref, { preserveScroll: true });
                            }}
                        >
                            Use environment defaults
                        </Button>
                    </>
                }
            />
        </div>
    );
}

AuthPolicyPage.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
