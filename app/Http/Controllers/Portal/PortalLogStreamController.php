<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Actions\LogStreams\CreateLogStream;
use App\Actions\LogStreams\DeleteLogStream;
use App\Actions\LogStreams\OwnedStreams;
use App\Actions\LogStreams\TestLogStream;
use App\Platform\Enums\PortalIntent;
use Cbox\Id\AuditStreaming\Models\AuditStream;
use Cbox\LaravelSiem\Enums\Destination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * LOG STREAMS IN THE ADMIN PORTAL — the organization's own audit trail, shipped to the
 * organization's own SIEM: Splunk, Elastic, Graylog, a CEF collector, or any JSON endpoint.
 *
 * ONLY THEIR OWN. The portal session is confined to its organization, so the streams it
 * lists, creates, tests and deletes are that organization's — never the environment's own
 * streams, which carry every tenant's entries and are the operator's business
 * ({@see OwnedStreams}). A stream created here carries this
 * organization's entries and nobody else's.
 *
 * A generated HMAC key, like a SCIM token, is shown once on the flash channel. "Send test
 * entry" runs `log_streams.test` and says whether their SIEM took it, in their language.
 */
final readonly class PortalLogStreamController extends PortalController
{
    public function show(): Response
    {
        $this->requireIntent(PortalIntent::LogStreams);

        $streams = AuditStream::query()
            ->ownedByOrganization($this->organizationId())
            ->orderByDesc('created_at')
            ->get();

        return $this->portalPage('portal/log-streams', __('portal.log_streams.title'), [
            'streams' => $streams->map(static fn (AuditStream $stream): array => [
                'id' => $stream->id,
                'name' => $stream->name,
                'destination' => $stream->destination->value,
                'endpointUrl' => $stream->endpoint_url,
                'auth' => $stream->auth->value,
                'enabled' => $stream->enabled,
                'lastSuccessAt' => $stream->last_success_at?->toIso8601String(),
                'failing' => $stream->consecutive_failures > 0,
                'testHref' => route('portal.log-streams.test', $stream->id),
                'removeHref' => route('portal.log-streams.destroy', $stream->id),
            ])->values()->all(),
            'destinations' => array_map(static fn (Destination $destination): array => [
                'value' => $destination->value,
                'defaultAuth' => $destination->defaultAuth()->value,
            ], Destination::httpCollectors()),
            'urls' => ['create' => route('portal.log-streams.store')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requireIntent(PortalIntent::LogStreams);

        $secret = trim($request->string('secret')->toString());
        $auth = $request->string('auth')->toString();

        $result = $this->act(CreateLogStream::class, [
            'organization_id' => $this->organizationId(),
            'name' => trim($request->string('name')->toString()),
            'destination' => $request->string('destination')->toString(),
            'endpoint_url' => trim($request->string('endpoint_url')->toString()),
            'auth' => $auth === '' ? null : $auth,
            'secret' => $secret === '' ? null : $secret,
        ], [
            'name' => 'name',
            'destination' => 'destination',
            'endpoint_url' => 'endpoint_url',
            'auth' => 'auth',
            'secret' => 'secret',
        ], 'name');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $generated = $result->payload['secret'] ?? null;

        if (is_string($generated)) {
            $this->inertia->flash('newSecret', $generated);
        }

        return back()->with('status', __('portal.log_streams.created'));
    }

    public function test(string $stream): RedirectResponse
    {
        $this->requireIntent(PortalIntent::LogStreams);

        $result = $this->act(TestLogStream::class, ['id' => $stream], [], 'stream');

        if ($result instanceof RedirectResponse) {
            return $result;
        }

        $delivered = ($result->payload['delivered'] ?? false) === true;

        // The destination's own words stay as it said them — "HTTP 403" means the same in
        // every language, and it is what their SIEM's logs will show.
        $this->inertia->flash('streamTest', [
            'id' => $stream,
            'delivered' => $delivered,
            'error' => is_string($result->payload['error'] ?? null) ? $result->payload['error'] : null,
        ]);

        return back();
    }

    public function destroy(string $stream): RedirectResponse
    {
        $this->requireIntent(PortalIntent::LogStreams);

        $result = $this->act(DeleteLogStream::class, ['id' => $stream], [], 'stream');

        return $result instanceof RedirectResponse
            ? $result
            : back()->with('status', __('portal.log_streams.removed'));
    }
}
