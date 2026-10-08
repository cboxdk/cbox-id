<?php

declare(strict_types=1);

namespace App\Platform\Actions;

use App\Platform\Actions\Approvals\ActionApprovalGate;

/**
 * An action that can say NO before anyone is asked to say yes.
 *
 * {@see ActionRunner} calls {@see preflight()} after the input is validated and BEFORE
 * {@see ActionApprovalGate} holds the action for a person's approval. Without it, a key
 * whose step-up policy names `webhooks.create` had the person approve on their phone, the
 * caller repeat the request, and only then hear `unsafe_url` — an approval spent on a
 * request that could never run, for a refusal that was knowable from the input alone.
 *
 * WHAT GOES HERE: every refusal that depends only on the input and on cheap reads —
 * whose the thing is (`owner_required`, `forbidden`, a 404 for a foreign id), whether a
 * URL is an address at all and whether the SSRF guard lets it out (one DNS resolution),
 * whether a domain is a hostname, whether the settings are complete. NOT what has an
 * effect or calls somebody else's server: no discovery fetch, no metadata download, no
 * write. A preflight changes nothing and runs outside the transaction.
 *
 * {@see Action::handle()} still makes its own checks where it needs the values they
 * produce, or where time matters — the approval can come minutes later, and the
 * registries' own SSRF guards stay the last word on what is stored. Preflight is the early
 * answer, not the only one.
 */
interface Preflight
{
    /**
     * @throws ActionRefused
     */
    public function preflight(ActionContext $context): void;
}
