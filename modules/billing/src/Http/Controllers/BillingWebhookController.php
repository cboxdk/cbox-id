<?php

declare(strict_types=1);

namespace Cbox\Id\Billing\Http\Controllers;

use Cbox\Id\Billing\Jobs\SyncBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BillingWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $secret = config('billing.webhook_secret');
        abort_unless(config('billing.enabled') === true && is_string($secret) && $secret !== '', 404);
        abort_if(strlen($request->getContent()) > 65536, 413);
        $header = (string) $request->header('X-Cbox-Signature');
        preg_match('/(?:^|,)\s*t=(\d+)(?:,|$)/', $header, $timestamp);
        preg_match_all('/(?:^|,)\s*v1=([a-f0-9]{64})(?=,|$)/', $header, $signatures);
        $time = isset($timestamp[1]) ? (int) $timestamp[1] : 0;
        abort_if(abs(time() - $time) > 300, 401);
        $expected = hash_hmac('sha256', $time.'.'.$request->getContent(), $secret);
        abort_unless(collect($signatures[1])->contains(fn (string $signature): bool => hash_equals($expected, $signature)), 401);
        // Fetch authoritative state; never project a possibly duplicated/out-of-order body.
        SyncBilling::dispatch();

        return response()->json(['accepted' => true], 202);
    }
}
