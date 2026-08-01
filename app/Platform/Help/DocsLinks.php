<?php

declare(strict_types=1);

namespace App\Platform\Help;

/**
 * Resolves a {@see HelpTopic} to a documentation URL.
 *
 * Two things this deliberately will not do. It will not invent a link for a topic
 * with no guide behind it ({@see HelpTopic::docsPath()} returns null), and it will
 * not emit anything at all when `docs.base_url` is blank — an air-gapped deployment
 * gets the in-app explanation and no dead outbound links. Callers treat null as
 * "render no link", which every help surface already does.
 */
final readonly class DocsLinks
{
    public function url(HelpTopic $topic): ?string
    {
        $path = $topic->docsPath();

        if ($path === null) {
            return null;
        }

        $base = config('docs.base_url');

        if (! is_string($base) || trim($base) === '') {
            return null;
        }

        $suffix = config('docs.suffix');

        return rtrim($base, '/').'/'.ltrim($path, '/').(is_string($suffix) ? $suffix : '');
    }
}
