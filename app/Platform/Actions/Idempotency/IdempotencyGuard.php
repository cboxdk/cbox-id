<?php

declare(strict_types=1);

namespace App\Platform\Actions\Idempotency;

use App\Platform\Actions\ActionDefinition;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\Principal\Principal;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Runs a write at most once per (principal, `Idempotency-Key`).
 *
 * - The first request runs; a successful answer is stored for {@see TTL_HOURS} hours.
 * - A retry with the same key and the same request gets that answer back, unchanged.
 * - The same key on a DIFFERENT request is refused (`idempotency_key_reused`): replaying
 *   the first answer would tell the caller something happened that did not.
 * - A retry that arrives while the first is still running is refused
 *   (`idempotency_in_progress`) rather than run a second time.
 *
 * Only successes are kept. A refusal is cheap to repeat and may stop being one — the slug
 * freed, the scope granted — so retrying after one runs the request again.
 *
 * SECRETS ARE NEVER KEPT. An action that returns one (a key's value, a client secret)
 * names the field in `redact`; it is stored as null, so a replay returns everything but
 * the secret — which was shown once, to the first answer. A caller that lost that answer
 * revokes what it made and asks again; a table of plaintext credentials kept "for retries"
 * would be the most valuable thing in the database.
 */
final class IdempotencyGuard
{
    public const int TTL_HOURS = 24;

    private const int LOCK_SECONDS = 30;

    /**
     * @param  array<string, mixed>  $input
     * @param  Closure(): ActionResult  $run
     *
     * @throws ActionRefused
     */
    public function once(Principal $principal, string $key, ActionDefinition $action, array $input, Closure $run): ActionResult
    {
        $owner = $principal->kind().':'.$principal->id();
        $hash = $this->hash($action, $input);

        $lock = Cache::lock('action-idempotency:'.hash('sha256', $owner.'|'.$key), self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new ActionRefused('idempotency_in_progress', 'A request with this Idempotency-Key is still being processed. Retry shortly.', 409);
        }

        try {
            $stored = IdempotencyRecord::query()
                ->where('principal', $owner)
                ->where('idempotency_key', $key)
                ->where('expires_at', '>', Carbon::now())
                ->first();

            if ($stored !== null) {
                if ($stored->action !== $action->name || ! hash_equals($stored->request_hash, $hash)) {
                    throw ActionRefused::because(
                        'idempotency_key_reused',
                        'This Idempotency-Key was already used for a different request. Use a new key for a new request.',
                    );
                }

                return ActionResult::replayed($stored->payload, $stored->status, $stored->meta, replay: true);
            }

            $result = $run();

            IdempotencyRecord::query()->where('principal', $owner)->where('idempotency_key', $key)->delete();
            IdempotencyRecord::query()->create([
                'principal' => $owner,
                'idempotency_key' => $key,
                'action' => $action->name,
                'request_hash' => $hash,
                'status' => $result->status ?? $action->status,
                'payload' => $this->redacted($result->payload, $action->redact),
                'meta' => $result->meta,
                'expires_at' => Carbon::now()->addHours(self::TTL_HOURS),
            ]);

            return $result;
        } catch (LockTimeoutException) {
            throw new ActionRefused('idempotency_in_progress', 'A request with this Idempotency-Key is still being processed. Retry shortly.', 409);
        } finally {
            $lock->release();
        }
    }

    /**
     * The payload with every named secret set to null. A dotted name reaches into a nested
     * object (`initial_key.token`); a field the payload does not carry is left absent
     * rather than invented.
     *
     * @param  array<mixed>|null  $payload
     * @param  list<string>  $fields
     * @return array<mixed>|null
     */
    private function redacted(?array $payload, array $fields): ?array
    {
        if ($payload === null) {
            return null;
        }

        foreach ($fields as $field) {
            if (Arr::has($payload, $field)) {
                Arr::set($payload, $field, null);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function hash(ActionDefinition $action, array $input): string
    {
        return hash('sha256', $action->name.'|'.json_encode($this->canonical($input), JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->canonical(...), $value);
    }
}
