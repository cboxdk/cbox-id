<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Platform\Realtime\ChannelAuthorizer;
use App\Platform\Realtime\Channels;
use App\Platform\Realtime\LiveUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Signs a browser's request to join one live channel (the Pusher protocol's channel
 * authorization, which Reverb speaks).
 *
 * Mounted three times — on the environment console's path, under `/orgs/{org}` and once
 * for a signed-in person's own pages — so each is reached through exactly the middleware of
 * the pages that listen: the host and surface fences, the impersonation window, the
 * environment or organization binding. {@see ChannelAuthorizer} then only compares.
 *
 * A GET, not the protocol's customary POST: subscribing is a read — a read-only console
 * role must be able to listen to what it may look at — and the console refuses every
 * other method to exactly those roles. It changes nothing but a cache mark, and its answer
 * is useless to anybody but the page that asked: a signature over that page's own socket.
 */
final class RealtimeAuthController extends Controller
{
    public function __invoke(Request $request, ChannelAuthorizer $authorizer, LiveUpdates $live): JsonResponse
    {
        abort_unless(LiveUpdates::enabled(), 404);

        $socket = $request->query('socket_id');
        $channel = $request->query('channel_name');

        abort_unless(is_string($socket) && preg_match('/\A\d{1,20}\.\d{1,20}\z/', $socket) === 1, 422);
        abort_unless(is_string($channel) && $authorizer->allows($request, $channel), 403);

        $key = config()->string('broadcasting.connections.reverb.key');
        $secret = config()->string('broadcasting.connections.reverb.secret');

        $live->watch(substr($channel, strlen(Channels::private(''))));

        return response()
            ->json(['auth' => $key.':'.hash_hmac('sha256', $socket.':'.$channel, $secret)])
            ->header('Cache-Control', 'no-store, private');
    }
}
