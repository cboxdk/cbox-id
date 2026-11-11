<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use Cbox\Id\Kernel\Events\EventDelivered;
use Illuminate\Support\Facades\DB;

/** Copies durable identity events into a product outbox; no billing networking in identity requests. */
final class CaptureProductUsage
{
    public function handle(EventDelivered $delivered): void
    {
        $event = $delivered->event;
        $meter = UsageMeters::EVENTS[$event->type] ?? null;
        if ($meter === null || $event->environment_id === null) {
            return;
        }
        $workspace = DB::table('environments')->join('projects', 'projects.id', '=', 'environments.project_id')
            ->where('environments.id', $event->environment_id)->value('projects.organization_id');
        // Root identity traffic and operator infrastructure have no customer project.
        if (! is_string($workspace) || $workspace === '') {
            return;
        }
        $payload = $event->payload;
        $dimensions = ['environment' => $event->environment_id];
        if ($meter === 'sign_ins') {
            $dimensions['method'] = self::method($payload['amr'] ?? null);
        }
        $rows = [self::row($event->id, $workspace, $meter, $event->occurred_at, $dimensions)];

        $user = $payload['user_id'] ?? null;
        if ($meter === 'sign_ins' && is_string($user) && $user !== '') {
            $rows[] = self::row($event->id.':active', $workspace, UsageMeters::ACTIVE_USERS, $event->occurred_at,
                ['environment' => $event->environment_id], $user);
        }
        DB::table('product_billing_usage_outbox')->insertOrIgnore($rows);
    }

    /**
     * The first authentication method of the session, as a small fixed vocabulary. A
     * session minted by a handoff (console → tenant admin, app switcher) is not a person
     * signing in again, so it is told apart rather than inflating sign-ins.
     */
    private static function method(mixed $amr): string
    {
        $first = is_array($amr) ? ($amr[0] ?? null) : null;

        return match (true) {
            is_array($amr) && in_array('handoff', $amr, true) => 'handoff',
            ! is_string($first) || $first === '' => 'unknown',
            in_array($first, ['pwd', 'password'], true) => 'password',
            in_array($first, ['hwk', 'swk', 'webauthn', 'passkey'], true) => 'passkey',
            in_array($first, ['sso', 'social', 'magic_link', 'otp', 'sms', 'mfa'], true) => $first,
            default => 'other',
        };
    }

    /**
     * The column keeps its v2 name, `workspace_id`, through v3.0: the release still running
     * while v3 rolls out writes it too. It is renamed one release later (UPGRADING, v3.1).
     *
     * @param  array<string, string>  $dimensions
     * @return array<string, mixed>
     */
    private static function row(string $id, string $workspace, string $meter, mixed $occurredAt, array $dimensions, ?string $uniqueKey = null): array
    {
        return [
            'event_id' => $id, 'workspace_id' => $workspace, 'meter' => UsageMeters::key($meter),
            'occurred_at' => $occurredAt, 'value' => 1, 'unique_key' => $uniqueKey,
            'dimensions' => json_encode($dimensions, JSON_THROW_ON_ERROR),
        ];
    }
}
