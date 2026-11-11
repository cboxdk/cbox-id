<?php

declare(strict_types=1);

namespace App\Platform\Shell;

use App\Http\Props\Shell\ShellProps;
use App\Platform\Console\ConsoleScope;
use App\Platform\Console\ShellPayload;
use App\Platform\Locale\ConsoleLabels;
use App\Platform\NativeProduct\NativeProductManifest;
use App\Platform\OrganizationCapabilities;
use Cbox\Console\Kit\Shell\ShellCreatable;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * WHAT "+" CAN CREATE, and the form each one opens (cbox-ui 0.11's create sheet).
 *
 * A thing is creatable where its page is on this person's rail: the rail already answers
 * plane, role, entitlement and feature ({@see ShellPayload}), so "+" never offers a form
 * whose page the person could not open. The fields are the page's own create form's, and
 * the form posts to the same route that page posts to, so a refusal, a validation error and
 * the landing page are the page's own.
 *
 * `area` is the rail area the page is in: the organization console's areas are keyed by
 * name (`identity-platform`), the environment console's by their English label.
 */
final class IdCreatables
{
    /** The creatable "+" opens on a page that names none, per console, first one available. */
    public const DEFAULTS = ['user', 'project'];

    /**
     * @return list<array{key: string, label: string, area: string, page: string, store: string, submit: string, keywords: list<string>, fields: list<array<string, mixed>>}>
     */
    public static function available(): array
    {
        $legacy = app(ShellPayload::class)->build();

        if (! $legacy instanceof ShellProps) {
            return [];
        }

        $pages = [];

        foreach ($legacy->areas as $area) {
            foreach ($area->pages as $page) {
                $pages[$page->route] = $area->key;
            }
        }

        $out = [];

        foreach (self::definitions() as $definition) {
            $area = $pages[$definition['page']] ?? null;

            if ($area === null || ! Route::has($definition['store'])) {
                continue;
            }

            if ($definition['key'] === 'project' && self::capabilities()?->canManageEnvironments() !== true) {
                continue;
            }

            if ($definition['key'] === 'member' && self::capabilities()?->canManageMembers() !== true) {
                continue;
            }

            $out[] = [...$definition, 'area' => $area];
        }

        return $out;
    }

    /**
     * What the person may do in the organization the page is in. Asked only on the pages
     * that need it, and never allowed to fail the page: the native product catalog answers
     * 503 where it is not installed, and a shell that throws takes the whole page down.
     */
    private static function capabilities(): ?OrganizationCapabilities
    {
        try {
            return app(ConsoleScope::class)->capabilities();
        } catch (HttpException) {
            return null;
        }
    }

    /** @return list<ShellCreatable> */
    public static function items(): array
    {
        return array_map(
            static fn (array $item): ShellCreatable => new ShellCreatable($item['key'], $item['label'], $item['area'], $item['keywords'], $item['submit']),
            self::available(),
        );
    }

    /**
     * The forms, keyed by creatable, for the page's `renderCreate`.
     *
     * @return array<string, array{url: string, fields: list<array<string, mixed>>}>
     */
    public static function forms(): array
    {
        $forms = [];

        foreach (self::available() as $item) {
            $forms[$item['key']] = ['url' => route($item['store'], [], false), 'fields' => $item['fields']];
        }

        return $forms;
    }

    /**
     * @return list<array{key: string, label: string, page: string, store: string, submit: string, keywords: list<string>, fields: list<array<string, mixed>>}>
     */
    private static function definitions(): array
    {
        $email = ['name' => 'email', 'label' => __('console.email_address_f2488fd4'), 'type' => 'email', 'required' => true];

        return [
            [
                'key' => 'user',
                'label' => __('console.user_b512d97e'),
                'page' => 'environment.users',
                'store' => 'environment.users.store',
                'submit' => __('console.create_user_f06da128'),
                'keywords' => ['user', 'person', 'account', 'invite'],
                'fields' => [
                    $email,
                    ['name' => 'name', 'label' => __('console.name_dcd1d522'), 'type' => 'text', 'required' => false],
                    ['name' => 'sendLink', 'label' => __('console.email_them_a_sign_in_link_39a8ca7c'), 'type' => 'checkbox', 'default' => true],
                ],
            ],
            [
                'key' => 'organization',
                'label' => __('console.organization_d764d425'),
                'page' => 'environment.organizations',
                'store' => 'environment.organizations.store',
                'submit' => __('console.create_organization_f14e1b22'),
                'keywords' => ['organization', 'tenant', 'customer', 'company'],
                'fields' => [
                    ['name' => 'name', 'label' => __('console.name_dcd1d522'), 'type' => 'text', 'required' => true],
                    ['name' => 'slug', 'label' => __('console.slug_d15387ec'), 'type' => 'text', 'required' => false, 'hint' => __('console.shell_create_slug_hint')],
                ],
            ],
            [
                'key' => 'project',
                'label' => __('console.shell_project'),
                'page' => 'orgs.projects',
                'store' => 'orgs.projects.store',
                'submit' => __('console.shell_create_project'),
                'keywords' => ['project', 'product', 'environment'],
                'fields' => [
                    ['name' => 'name', 'label' => __('console.name_dcd1d522'), 'type' => 'text', 'required' => true],
                ],
            ],
            [
                'key' => 'member',
                'label' => __('console.shell_team_member'),
                'page' => 'orgs.settings.members',
                'store' => 'orgs.settings.members.invite',
                'submit' => __('console.invite_1fd9ae16'),
                'keywords' => ['member', 'team', 'invite', 'teammate', 'colleague'],
                'fields' => [
                    $email,
                    [
                        'name' => 'roles',
                        'label' => __('console.role_14736a2e'),
                        'type' => 'select',
                        'array' => true,
                        'required' => true,
                        'default' => 'developer',
                        'options' => array_map(
                            static fn (string $key, array $role): array => ['value' => $key, 'label' => ConsoleLabels::navigation($role['name'])],
                            array_keys(NativeProductManifest::ROLES),
                            array_values(NativeProductManifest::ROLES),
                        ),
                    ],
                ],
            ],
        ];
    }
}
