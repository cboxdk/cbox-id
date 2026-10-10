<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * THE PICTURES THE DOCUMENTATION SHOWS, AND ONLY THOSE.
 *
 * One list, read from both sides. tests/Browser/DocsScreenshotsTest.php takes every
 * picture on it from the running app and refuses to save one that is not on it; and
 * tests/Feature/DocsScreenshotsCatalogueTest.php, in the normal suite, holds docs/ to it:
 * every file in docs/screenshots is on the list, every name on the list is a file there,
 * is embedded by at least one page, and is a row of docs/screenshots/_index.md.
 *
 * Without the list, the two sides drifted apart by construction: the generator wrote what
 * it was told, the docs embedded what they had once been given, and a page renamed in
 * one place left a picture nobody regenerated (it showed the console as it used to be)
 * or one nobody embedded (four of the twenty-eight files, when this was written).
 *
 * TO ADD A PICTURE: add its name here, take it in DocsScreenshotsTest, embed it in the
 * page it illustrates, add its row to `_index.md`, and run the generator (or the "Docs
 * screenshots" workflow).
 */
final class DocsScreenshots
{
    /** Every screenshot under docs/screenshots, by file name without `.png`. */
    public const array NAMES = [
        'hosted-sign-in',
        'workspace-sign-up',
        'workspace-projects',
        'environment-overview',
        'get-started',
        'context-switcher',
        'mobile-overview',
        'users',
        'organizations',
        'organization-overview',
        'organization-members',
        'organization-enterprise-sso',
        'organization-domains',
        'organization-directory-sync',
        'directory-detail',
        'roles',
        'applications',
        'application-detail',
        'api-keys',
        'webhooks',
        'agents',
        'approvals',
        'audit-log',
        'app-audit-logs',
        'log-streams',
        'environment-settings',
        'admin-portal-link',
        'admin-portal',
    ];

    /** Where the pictures live, relative to the repository root. */
    public const string DIRECTORY = 'docs/screenshots';
}
