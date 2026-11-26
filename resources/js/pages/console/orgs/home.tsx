import { Link } from '@inertiajs/react';
import ConsoleLayout from '@/layouts/ConsoleLayout';
import { useTranslator } from '@/i18n';
import type { PageProps } from '@/types';
import { Button, Icon, type IconName, PageHeader, Stat } from '@/ui';

type Props = PageProps<{
    name: string;
    /** Null for a role that may not read the roster. */
    people: number | null;
    peopleHref: string | null;
    billingHref: string | null;
    signInHref: string | null;
    auditHref: string | null;
    settingsHref: string | null;
    platforms: number;
    /** Null until the organization owns one. */
    platformsHref: string | null;
    /** Null for somebody who may not create one. */
    createPlatformHref: string | null;
}>;

/**
 * THE ORGANIZATION'S HOME: its people, how they sign in and what it pays, first. Sign-in for
 * the organization's own product (an identity platform) is one card below them.
 */
export default function OrganizationHome({
    name,
    people,
    peopleHref,
    billingHref,
    signInHref,
    auditHref,
    settingsHref,
    platforms,
    platformsHref,
    createPlatformHref,
}: Props) {
    const { t, locale } = useTranslator();

    const links: { key: string; icon: IconName; title: string; detail: string; href: string }[] = [
        signInHref !== null && {
            key: 'sign-in',
            icon: 'fingerprint' as IconName,
            title: t('console.org_home_team_sign_in'),
            detail: t('console.org_home_team_sign_in_detail'),
            href: signInHref,
        },
        billingHref !== null && {
            key: 'billing',
            icon: 'briefcase' as IconName,
            title: t('console.org_home_billing'),
            detail: t('console.org_home_billing_detail'),
            href: billingHref,
        },
        auditHref !== null && {
            key: 'audit',
            icon: 'audit' as IconName,
            title: t('console.org_home_audit'),
            detail: t('console.org_home_audit_detail'),
            href: auditHref,
        },
    ].filter((link) => link !== false);

    return (
        <>
            <PageHeader
                title={name}
                description={t('console.org_home_description')}
                actions={
                    settingsHref !== null ? (
                        <Button asChild variant="secondary" className="shrink-0">
                            <Link href={settingsHref}>
                                <Icon name="settings" className="w-4 h-4" />
                                {t('console.org_home_settings')}
                            </Link>
                        </Button>
                    ) : undefined
                }
            />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {people !== null && (
                    <Stat
                        icon="members"
                        label={t('console.org_home_people')}
                        value={people.toLocaleString(locale)}
                        href={peopleHref ?? undefined}
                    />
                )}
                {platformsHref !== null && (
                    <Stat
                        icon="layers"
                        label={t('console.org_home_platforms')}
                        value={platforms.toLocaleString(locale)}
                        href={platformsHref}
                    />
                )}
            </div>

            {links.length > 0 && (
                <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {links.map((link) => (
                        <li key={link.key} className="min-w-0">
                            <Link href={link.href} className="card cbx-home-link p-5 flex gap-3">
                                <Icon
                                    name={link.icon}
                                    className="w-5 h-5 shrink-0 mt-0.5"
                                    style={{ color: 'var(--primary)' }}
                                />
                                <span className="min-w-0">
                                    <span className="block font-medium">{link.title}</span>
                                    <span
                                        className="block text-sm"
                                        style={{ color: 'var(--muted-foreground)' }}
                                    >
                                        {link.detail}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {/*
                Sign-in for the organization's own product. Below its own business, because
                most organizations never need one; one card, so the ones that do find it.
            */}
            {createPlatformHref !== null && platformsHref === null && (
                <section
                    className="card mt-6 p-5 flex flex-wrap items-center gap-4"
                    style={{ borderStyle: 'dashed' }}
                    aria-labelledby="build-sign-in"
                >
                    <Icon
                        name="code"
                        className="w-5 h-5 shrink-0"
                        style={{ color: 'var(--primary)' }}
                    />
                    <div className="min-w-0 flex-1" style={{ minWidth: '14rem' }}>
                        <h2 id="build-sign-in" className="font-medium">
                            {t('console.org_home_build_title')}
                        </h2>
                        <p className="text-sm" style={{ color: 'var(--muted-foreground)' }}>
                            {t('console.org_home_build_body')}
                        </p>
                    </div>
                    <Button asChild variant="secondary" className="shrink-0">
                        <Link href={createPlatformHref}>{t('console.org_home_build_action')}</Link>
                    </Button>
                </section>
            )}
        </>
    );
}

OrganizationHome.layout = (page: React.ReactNode) => <ConsoleLayout>{page}</ConsoleLayout>;
