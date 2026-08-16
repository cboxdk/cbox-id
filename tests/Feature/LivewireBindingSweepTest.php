<?php

declare(strict_types=1);

/**
 * Every `wire:model` in a Volt component must name a property that component HAS.
 *
 * A renamed property with a stale binding is invisible to an ordinary component test:
 * `Volt::test(...)->set('organizationName', 'Cbox')` writes the property directly and never
 * touches the template, so the test passes while the real form silently drops what the user
 * typed. That is not hypothetical — the first-run install screen shipped that way: the field
 * bound to `accountName`, the property was `organizationName`, validation failed on a key
 * whose `@error` block was never rendered, and the button returned 200 and did nothing.
 *
 * Cheap to check and impossible to get wrong twice.
 *
 * IT SCANS `modules/` TOO. It walked `resources/views/livewire` alone, so three module
 * components using `wire:model` — whitelabel branding and both compliance pages — were
 * never checked at all. Sibling sweeps in this suite have made the same omission
 * repeatedly (the accessibility audit was doing it a fortnight ago), and a module is
 * exactly where a stale binding survives longest: it has fewer readers than the app's own
 * views and its own tests set properties directly.
 */
it('binds every wire:model to a property its component declares', function (): void {
    $offenders = [];
    $checked = 0;

    $roots = [resource_path('views/livewire')];

    foreach ((array) glob(base_path('modules/*/resources/views')) as $moduleViews) {
        if (is_string($moduleViews) && is_dir($moduleViews)) {
            $roots[] = $moduleViews;
        }
    }

    // The module roots have to be FOUND, not assumed: a renamed layout would silently
    // narrow this back to the app's own views, which is the state it is being widened out
    // of. Modules exist in this repo, so finding none means the glob is wrong.
    expect(count($roots))->toBeGreaterThan(3, 'no module view directories were found — the sweep narrowed itself');

    $files = [];

    foreach ($roots as $root) {
        /** @var iterable<SplFileInfo> $found */
        $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($found as $file) {
            $files[] = (string) $file;
        }
    }

    foreach ($files as $file) {
        if (! str_ends_with((string) $file, '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents((string) $file);

        preg_match_all('/public\s+(?:\??[\w\\\\]+\s+)?\$(\w+)/', $source, $props);
        $declared = array_flip($props[1]);

        // A component with no public properties is a plain view; `wire:model` in one is a
        // different mistake and not this test's subject.
        if ($declared === []) {
            continue;
        }

        preg_match_all('/wire:model(?:\.\w+)*="(\w+)/', $source, $bindings);

        foreach ($bindings[1] as $name) {
            $checked++;

            if (! isset($declared[$name])) {
                $offenders[] = str_replace([resource_path('views/livewire/'), base_path('')], '', (string) $file).": wire:model=\"{$name}\"";
            }
        }
    }

    // A FLOOR, so a moved view directory cannot empty the sweep and report success.
    expect($checked)->toBeGreaterThan(50, 'the binding sweep found almost nothing; did the view directories move?');

    expect($offenders)->toBe([], "wire:model bound to a property the component does not declare:\n".implode("\n", $offenders));
});
