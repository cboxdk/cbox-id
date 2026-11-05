<?php

declare(strict_types=1);

// Clean CI/build inputs, never a developer's sibling working tree or credentials.
if ($argc !== 2) {
    throw new RuntimeException('Pass a new Signals client destination directory.');
}
$root = dirname(__DIR__).'/build/dependencies';
$manifest = json_decode(file_get_contents($root.'/signals-packages.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['laravel-signals-client' => $argv[1]] as $name => $destination) {
    if (file_exists($destination) || is_link($destination)) {
        throw new RuntimeException('Refusing to overwrite an existing package destination.');
    }
    $archive = $root.'/'.$name.'.tar';
    if (! hash_equals($manifest[$name]['sha256'], hash_file('sha256', $archive))) {
        throw new RuntimeException('Reviewed package checksum mismatch.');
    }
    $tar = new PharData($archive);
    foreach (new RecursiveIteratorIterator($tar) as $entry) {
        $path = $entry->getPathname();
        if ($entry->isLink() || preg_match('~/(?:\.\.|\.git|vendor|node_modules|\.env[^/]*)/~', $path.'/')) {
            throw new RuntimeException('Unexpected private or development archive entry.');
        }
    }
    $tar->extractTo($destination);
    echo $name.' '.$manifest[$name]['revision'].PHP_EOL;
}
