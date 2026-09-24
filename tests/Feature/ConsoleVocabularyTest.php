<?php

declare(strict_types=1);

use Tests\Support\UiCopy;

/**
 * ONE WORD PER THING, and the words that used to mean several things stay gone.
 *
 * The console called the customer's own Cbox account "Account", "Customer" and "Identity
 * platform"; called a team of THEIR customers an "organization", a "tenant" and a
 * "customer"; called the membership tier "Console access", "Organization access", "Org
 * access" and "Role"; and called app roles "Access roles", "Roles in your apps" and "App
 * roles". Architecture words — "plane", "tenant" — leaked into sentences. The fix was a
 * vocabulary, and a vocabulary nobody checks drifts back in a quarter:
 *
 *   Workspace — the customer's own Cbox account (its console, its Team, its settings)
 *   Organization — a team of YOUR customers, inside an environment
 *   Built-in role — Owner / Admin / Developer / Member / Viewer, exactly one per member
 *   Roles — app and custom roles, any number
 *   Staff roles — roles granted across a whole environment
 *   Apps, Keys, Switch user
 *
 * WHAT IS READ is the copy only — JSX text, sentence-like string literals, PHP string
 * literals in the console's controllers, props, navigation and help — through
 * {@see UiCopy}, so identifiers (`tenantAssignable`, `platform.customers`) and comments
 * explaining why a word was retired are not matches.
 */
function vocabularySources(): array
{
    $tsx = [];

    foreach ([
        base_path('resources/js/pages'),
        base_path('resources/js/chrome'),
        base_path('resources/js/layouts'),
        base_path('resources/js/ui'),
        ...((array) glob(base_path('modules/*/resources/js'))),
    ] as $root) {
        if (! is_string($root) || ! is_dir($root)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            $path = (string) $file;

            if (preg_match('/\.tsx?$/', $path) === 1 && ! str_contains($path, '.test.')) {
                $tsx[] = $path;
            }
        }
    }

    $php = [base_path('app/Providers/ConsoleServiceProvider.php')];

    foreach ([
        base_path('app/Http/Controllers'),
        base_path('app/Http/Props'),
        base_path('app/Http/Requests'),
        base_path('app/Platform/Console'),
        base_path('app/Platform/Help'),
        base_path('app/Platform/Navigation'),
        base_path('app/Platform/Onboarding'),
        base_path('app/Platform/Membership'),
        base_path('app/Platform/Install/Enums'),
        ...((array) glob(base_path('modules/*/src'))),
    ] as $root) {
        if (! is_string($root) || ! is_dir($root)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (str_ends_with((string) $file, '.php')) {
                $php[] = (string) $file;
            }
        }
    }

    return [$tsx, $php];
}

/**
 * The retired words, each with the word to use instead. A pattern here is matched against
 * copy only; it does not need to dodge identifiers.
 *
 * @var array<string, string>
 */
const RETIRED_WORDS = [
    // Deployment shape words ("multi-tenant", "single-tenant") are not this word.
    '/(?<!-)\btenants?\b(?!-)/i' => 'organization (a team of your customers) — or workspace, if it is the customer\'s own Cbox account',
    '/\bplanes?\b/i' => 'console or environment — "plane" is an architecture word',
    '/\bcustomers?\b/i' => 'workspace (the customer\'s Cbox account) or organization (a team of THEIR customers)',
    '/\bIdentity platform\b/' => 'Workspace',
    '/\bAdministrators\b/' => 'Team',
    '/\bAccount settings\b/' => 'Workspace settings',
    '/\bApps (&|&amp;) API keys\b/' => 'Apps (keys have a page of their own)',
    '/\b(Console|Organization|Org) access\b/i' => 'Built-in role',
    '/\bAccess roles?\b/i' => 'Roles',
    '/\bRoles in your apps\b/i' => 'Roles',
    '/\bApp roles\b/i' => 'App and custom roles, or Roles',
    '/\bOrg roles\b/i' => 'Custom roles',
    '/\bRoles everywhere\b/i' => 'Staff roles',
    '/\bworkspace console\b/i' => 'the workspace',
    '/\bSwitch account\b/i' => 'Switch user',
    '/\bEnvironment keys\b/' => 'Management keys',
    '/\bAgent approvals\b/' => 'Approve agent requests (organization console) or Review agent requests (environment console)',
];

/**
 * THE ALLOWLIST — small, explicit, and each entry says why.
 *
 * Keyed by the path's tail and the exact phrase that may stay. Adding to it is a decision
 * somebody has to write a reason for, which is the point.
 *
 * @var list<array{0: string, 1: string, 2: string}>
 */
const VOCABULARY_ALLOWED = [
    // Microsoft's own name for the field somebody copies out of the Entra portal. Renaming
    // it would send them looking for a value that does not exist under our name.
    ['resources/js/pages/console/directories/create.tsx', 'Tenant ID', 'Entra calls it that'],
    ['app/Http/Requests/Console/ConnectDirectoryRequest.php', 'Entra tenant ID', 'Entra calls it that'],
];

it('keeps retired words out of everything the console shows', function (): void {
    [$tsx, $php] = vocabularySources();

    $offenders = [];
    $strings = 0;

    foreach ([...array_map(fn (string $p): array => [$p, 'tsx'], $tsx), ...array_map(fn (string $p): array => [$p, 'php'], $php)] as [$path, $kind]) {
        $source = (string) file_get_contents($path);
        $copy = $kind === 'tsx' ? UiCopy::fromTsx($source) : UiCopy::fromPhp($source);
        $relative = str_replace(base_path().'/', '', $path);

        foreach ($copy as $text) {
            $strings++;

            foreach (RETIRED_WORDS as $pattern => $instead) {
                if (preg_match($pattern, $text, $match) !== 1) {
                    continue;
                }

                $allowed = collect(VOCABULARY_ALLOWED)->contains(
                    fn (array $entry): bool => str_ends_with($relative, $entry[0]) && str_contains($text, $entry[1]),
                );

                if (! $allowed) {
                    $offenders[] = "{$relative}: \"{$match[0]}\" in \"".mb_strimwidth($text, 0, 90, '…')."\" — use: {$instead}";
                }
            }
        }
    }

    // FLOORS, so a moved directory or a lexer that stopped finding anything cannot report
    // a clean console. They may go up; lowering one needs a reason written here.
    expect(count($tsx))->toBeGreaterThan(150, 'the sweep found almost no React files — did the directories move?')
        ->and(count($php))->toBeGreaterThan(300, 'the sweep found almost no PHP files — did the directories move?')
        ->and($strings)->toBeGreaterThan(5000, 'the sweep read almost no copy — is the extractor still finding strings?');

    expect($offenders)->toBe([], "the console says a retired word:\n".implode("\n", $offenders));
});

it('reads copy and not code', function (): void {
    // The extractor is the rule's foundation: if it read identifiers, every page would be
    // an offender; if it read nothing, every page would pass.
    $copy = UiCopy::fromTsx(<<<'TSX'
        import { tenant } from '@/tenants';
        // The tenant rail was retired here.
        export function Page({ customer }: { customer: Customer }) {
            const key = 'customer';
            return (
                <Panel title="Every organization" description={`Owned by ${customer.name} today`}>
                    Your team
                </Panel>
            );
        }
        TSX);

    expect($copy)->toContain('Every organization', 'Owned by … today', 'Your team')
        ->and(implode(' ', $copy))->not->toContain('tenant')
        ->and(implode(' ', $copy))->not->toContain('customer');

    expect(UiCopy::fromPhp("<?php\n// Tenant plane history.\n\$route = 'platform.customers';\nreturn \$this->page('x', 'Workspaces');"))
        ->toBe(['Workspaces']);
});

it('catches a retired word the moment it comes back', function (): void {
    // The rule, applied to a line of copy that uses the old word: the test above would go
    // red on exactly this.
    $copy = UiCopy::fromTsx('<Th>Console access</Th>');

    $hits = collect($copy)->filter(
        fn (string $text): bool => collect(array_keys(RETIRED_WORDS))->contains(
            fn (string $pattern): bool => preg_match($pattern, $text) === 1,
        ),
    );

    expect($hits->all())->toBe(['Console access']);
});
