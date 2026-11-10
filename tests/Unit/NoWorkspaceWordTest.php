<?php

declare(strict_types=1);

/**
 * "WORKSPACE" IS GONE — IN EVERY LANGUAGE, NOT ONLY IN ENGLISH.
 *
 * v3 calls the customer's own Cbox account an Organization (level 1 of the shell, the same
 * organization Work and Billing show at /orgs/{slug}). Renaming the English word is the easy
 * half: each of the other twenty languages has its own word for "workspace", and a key that
 * kept its old translation keeps saying it — Danish "arbejdsområde", German
 * "Arbeitsbereich" — with nothing in English to show it. So this reads:
 *
 *  - the English catalogue, the React pages and chrome, the Blade views and every route
 *    name, for the English word;
 *  - every other language's catalogue, for THAT language's word (the denylist below, one
 *    stem per language, matched case-insensitively);
 *  - and every catalogue for a key English has and it lacks, or the other way round — a
 *    renamed key that lost its translation is a page in English in the middle of Danish.
 *
 * "Google Workspace" is a product name and stays. A URL path (`/api/v1/workspace`) is an
 * address the API keeps until v3's API move, not a word anybody reads.
 */
const NO_WORKSPACE_ROOT = __DIR__.'/../..';

/** Each language's own word for "workspace" — the stem, so every inflection matches. */
const WORKSPACE_IN = [
    'bg' => 'работн\w*\s+пространств',
    'cs' => 'pracovn\w*\s+prostor',
    'da' => 'arbejdsområd',
    'de' => 'arbeitsbereich',
    'el' => 'χώρ\w*\s+εργασίας',
    'en' => '(?<!google )(?<![\/\w])workspaces?(?![\/\w])',
    'es' => 'espacios?\s+de\s+trabajo',
    'fi' => 'työtil',
    'fr' => 'espaces?\s+de\s+travail',
    'hr' => 'radn\w*\s+prostor',
    'hu' => 'munkaterület',
    'it' => 'spazi\w*\s+di\s+lavoro',
    'nb' => 'arbeidsområd',
    'nl' => 'werkruimte',
    'pl' => 'przestrze\w*\s+robocz',
    'pt' => 'espaços?\s+de\s+trabalho',
    'ro' => 'spați\w*\s+de\s+lucru',
    'sk' => 'pracovn\w*\s+priestor',
    'sv' => 'arbetsyt',
    'tr' => 'çalışma\s+alan',
    'uk' => 'робоч\w*\s+простор',
];

/** @return array<string, string> dotted key => line */
function noWorkspaceLines(string $path): array
{
    $lines = require $path;
    $flat = [];
    $walk = static function (array $lines, string $prefix) use (&$walk, &$flat): void {
        foreach ($lines as $key => $value) {
            if (is_array($value)) {
                $walk($value, $prefix.$key.'.');
            } elseif (is_string($value)) {
                $flat[$prefix.$key] = $value;
            }
        }
    };
    $walk(is_array($lines) ? $lines : [], '');

    return $flat;
}

/** @return list<string> */
function noWorkspaceFiles(string $dir, string $pattern): array
{
    $files = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(NO_WORKSPACE_ROOT.'/'.$dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = (string) $file;
        if (preg_match($pattern, $path) === 1) {
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

it('has a word for workspace for every language there is', function (): void {
    $locales = array_map('basename', (array) glob(NO_WORKSPACE_ROOT.'/lang/*', GLOB_ONLYDIR));
    sort($locales);

    expect(array_keys(WORKSPACE_IN))->toBe($locales);
});

it('says no language\'s word for workspace in that language\'s catalogue', function (): void {
    $offenders = [];

    foreach (WORKSPACE_IN as $locale => $stem) {
        foreach ((array) glob(NO_WORKSPACE_ROOT."/lang/{$locale}/*.php") as $path) {
            foreach (noWorkspaceLines((string) $path) as $key => $line) {
                if (preg_match('/'.$stem.'/iu', $line) === 1) {
                    $offenders[] = "lang/{$locale}/".basename((string) $path).": {$key} — {$line}";
                }
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps every key in every language: none lost in the rename, none left over', function (): void {
    $problems = [];

    foreach ((array) glob(NO_WORKSPACE_ROOT.'/lang/en/*.php') as $english) {
        $file = basename((string) $english);
        $keys = array_keys(noWorkspaceLines((string) $english));

        foreach (array_keys(WORKSPACE_IN) as $locale) {
            $path = NO_WORKSPACE_ROOT."/lang/{$locale}/{$file}";
            $theirs = is_file($path) ? array_keys(noWorkspaceLines($path)) : [];

            foreach (array_diff($keys, $theirs) as $missing) {
                $problems[] = "lang/{$locale}/{$file} lacks {$missing}";
            }
            foreach (array_diff($theirs, $keys) as $extra) {
                $problems[] = "lang/{$locale}/{$file} has {$extra}, which English does not";
            }
        }
    }

    expect($problems)->toBe([]);
});

it('says no workspace on a page, in a view or in a route name', function (): void {
    $word = '/'.WORKSPACE_IN['en'].'/iu';
    $offenders = [];

    $sources = [
        ...noWorkspaceFiles('resources/js', '/(?<!\.test)\.tsx$/'),
        ...noWorkspaceFiles('resources/views', '/\.blade\.php$/'),
        ...array_filter(noWorkspaceFiles('modules', '/resources\/js\/.*(?<!\.test)\.tsx$/')),
    ];

    foreach ($sources as $path) {
        foreach (explode("\n", (string) file_get_contents($path)) as $n => $line) {
            // Comments explain history; they are not copy.
            if (preg_match('/^\s*(\*|\/\/|\/\*|\{\/\*|<!--)/', $line) === 1) {
                continue;
            }
            if (preg_match($word, $line) === 1) {
                $offenders[] = substr($path, strlen(NO_WORKSPACE_ROOT) + 1).':'.($n + 1).': '.trim($line);
            }
        }
    }

    // Route names: the console's own (`->name('…')`) and the API's (`api.actions.<action>`).
    foreach ([...noWorkspaceFiles('routes', '/\.php$/'), ...noWorkspaceFiles('app/Actions', '/\.php$/'), ...noWorkspaceFiles('modules', '/src\/.*\.php$/')] as $path) {
        preg_match_all("/(?:->name\\(|\\bname: )'([^']+)'/", (string) file_get_contents($path), $names);
        foreach ($names[1] as $name) {
            if (preg_match('/workspace/i', $name) === 1) {
                $offenders[] = substr($path, strlen(NO_WORKSPACE_ROOT) + 1).": route or action name {$name}";
            }
        }
    }

    expect($offenders)->toBe([]);
});
