<?php

declare(strict_types=1);

namespace App\Platform\Appearance;

/**
 * WHERE A BRAND'S UPLOADED LOGO AND FAVICON LIVE — the socket between the hosted pages and
 * whichever module stores the images.
 *
 * The white-label module owns the storage (its brand profiles and the database-backed
 * asset store the application serves at `/brand-assets/…`) and binds this contract from
 * its own provider, the way it plugs into every other socket. The hosted pages, the
 * Appearance editor, the checklists and the mail layout ask here and nowhere else, so
 * there is one logo per altitude rather than one per screen that happens to draw it.
 *
 * Altitudes are the Appearance editor's: `null` is the ENVIRONMENT default every
 * organization inherits, an id is one ORGANIZATION's own. A read is EXACT — the
 * organization's own image or none; falling back to the environment is the caller's
 * decision ({@see BrandContext::logo()}), because the editor needs to tell "this
 * organization has no logo of its own" apart from "it inherits one".
 *
 * Without the module bound, {@see NoBrandImages} answers: nothing stored, uploads refused
 * with a sentence that says why.
 */
interface BrandImages
{
    /** Whether images can be stored at all on this install. */
    public function accepting(): bool;

    /**
     * The image as the hosted pages draw it, or null. ROOT-RELATIVE when this application
     * serves it — a branded door is reached on the environment's host, a custom domain or
     * the root, and an absolute URL minted on whichever host the upload happened would be
     * a cross-origin request the page's `img-src 'self'` refuses.
     */
    public function url(BrandImage $kind, ?string $organizationId): ?string;

    /** The same image as an absolute URL, for a mail client that has no page to be relative to. */
    public function absoluteUrl(BrandImage $kind, ?string $organizationId): ?string;

    /** Store a checked image at this altitude, replacing (and deleting) any previous one. */
    public function store(BrandImageUpload $upload, ?string $organizationId): void;

    /** Remove the image at this altitude. */
    public function remove(BrandImage $kind, ?string $organizationId): void;

    /**
     * Origins OTHER than this application's that serve stored images — a CDN in front of
     * object storage, say — so the content security policy can admit exactly those and
     * nothing else. Empty for the default database store.
     *
     * @return list<string>
     */
    public function origins(): array;
}
