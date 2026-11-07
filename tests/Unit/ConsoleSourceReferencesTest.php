<?php

declare(strict_types=1);

it('keeps every literal console translation used by application and module source', function (): void {
    $root = dirname(__DIR__, 2);
    $english = require $root.'/lang/en/console.php';
    $missing = [];

    foreach (['app', 'modules', 'resources/js'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'ts', 'tsx'], true)) {
                continue;
            }

            // Generated hashed translation keys are unambiguous; middleware names such as
            // console.customer and console.root are deliberately not translation references.
            preg_match_all('/[\'\"]console\.([a-z0-9_]+_[a-f0-9]{8})[\'\"]/', file_get_contents($file->getPathname()), $matches);
            foreach (array_unique($matches[1]) as $key) {
                if (! array_key_exists($key, $english)) {
                    $missing[] = substr($file->getPathname(), strlen($root) + 1).': '.$key;
                }
            }
        }
    }

    expect($missing)->toBe([], implode("\n", $missing));
});
