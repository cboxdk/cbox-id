<?php

declare(strict_types=1);

// A reviewed allowlist, never a repository crawl. No customer records or operational runbooks.
$root = dirname(__DIR__);
$manifest = require $root.'/resources/assistant/manifest.php';
$check = in_array('--check', $argv, true);
$paths = [];
foreach ($manifest as $document) {
    $paths[realpath($root.'/'.$document['source'])] = '/'.$document['path'];
}
foreach ($manifest as $document) {
    $source = $root.'/'.$document['source'];
    $text = file_get_contents($source);
    $text = preg_replace('/\A---\R.*?\R---\R/s', '', $text);
    $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', '', $text);
    $text = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', function (array $match) use ($paths, $source): string {
        if (str_starts_with($match[2], 'https://') || str_starts_with($match[2], 'http://')) {
            return $match[0];
        }
        $target = realpath(dirname($source).'/'.explode('#', $match[2])[0]);

        return isset($paths[$target]) ? '['.$match[1].']('.$paths[$target].')' : $match[1];
    }, $text);
    $text = trim($text)."\n";
    $target = $root.'/resources/assistant/'.$document['file'];
    if ($check && (! is_file($target) || file_get_contents($target) !== $text)) {
        fwrite(STDERR, 'Support guide is stale: '.$document['file'].". Run php bin/prepare-assistant-docs.php and increase its manifest revision.\n");
        exit(1);
    }
    if (! $check) {
        file_put_contents($target, $text);
    }
}
echo "Approved Assistant guides match their sources.\n";
