<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/build/dependencies/laravel-signals-client.json'), true, 512, JSON_THROW_ON_ERROR);
$archive = $root.'/build/dependencies/laravel-signals-client.tar';
if (! hash_equals($manifest['sha256'], hash_file('sha256', $archive))) {
    throw new RuntimeException('Bundled Signals SDK archive checksum mismatch.');
}
$source = new PharData($archive);
$prefix = 'phar://'.$archive.'/';
$expected = [];
foreach (new RecursiveIteratorIterator($source) as $file) {
    if (! $file->isFile()) {
        continue;
    }
    $path = substr($file->getPathname(), strlen($prefix));
    if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
        throw new RuntimeException('Invalid bundled Signals SDK path.');
    }
    $local = $root.'/packages/signals-client/'.$path;
    if (! is_file($local) || is_link($local) || ! hash_equals(hash('sha256', $file->getContent()), hash_file('sha256', $local))) {
        throw new RuntimeException('Bundled Signals SDK differs from its immutable archive: '.$path);
    }
    $expected[$path] = true;
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/packages/signals-client', FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile() && ! isset($expected[substr($file->getPathname(), strlen($root.'/packages/signals-client/'))])) {
        throw new RuntimeException('Unexpected file in bundled Signals SDK: '.$file->getPathname());
    }
}
echo 'Bundled Signals SDK matches immutable source '.$manifest['revision'].".\n";
