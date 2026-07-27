<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * The ONE error shape of the REST API, applied to everything that leaves the API
 * surface as an exception.
 *
 * Both OpenAPI specs promise `{ "error": "<stable code>", "message": "<human text>" }`
 * for every failure, and account.yaml declares both fields REQUIRED. Without this,
 * the single most common failure — `$request->validate()` — rendered Laravel's default
 * `{"message": …, "errors": {…}}`, which has no `error` key at all: a generated client
 * that switches on `error` breaks on the first bad payload. Same for a 429 from the
 * throttle ("Too Many Attempts.") and a 405 from a wrong verb.
 *
 * Deliberately NOT RFC 9457 problem+json. The spec already documents this envelope and
 * clients are generated from it; introducing a third "correct" shape would make the
 * inconsistency worse, not better. The RFC-mandated surfaces keep their own shapes —
 * OAuth's `{error, error_description}` (RFC 6750 challenges) and SCIM's Error envelope
 * are protocol conformance and never pass through here, because they are returned as
 * responses rather than thrown.
 */
final class ApiErrorRenderer
{
    /**
     * Stable machine codes, keyed by HTTP status. These are part of the contract: a
     * client switches on them, so they must not drift with the human message.
     *
     * @var array<int, string>
     */
    private const CODES = [
        400 => 'bad_request',
        401 => 'unauthorized',
        403 => 'forbidden',
        404 => 'not_found',
        405 => 'method_not_allowed',
        406 => 'not_acceptable',
        409 => 'conflict',
        410 => 'gone',
        413 => 'payload_too_large',
        415 => 'unsupported_media_type',
        422 => 'unprocessable_entity',
        429 => 'rate_limited',
        503 => 'service_unavailable',
    ];

    /**
     * Fallbacks for the many framework exceptions that carry an empty message
     * (`abort(404)` throws with `''`), so `message` is never blank.
     *
     * @var array<int, string>
     */
    private const MESSAGES = [
        400 => 'The request could not be understood.',
        401 => 'A valid credential is required.',
        403 => 'This credential may not perform that action.',
        404 => 'No such resource.',
        405 => 'That method is not allowed on this endpoint.',
        406 => 'This endpoint cannot produce the requested representation.',
        409 => 'The request conflicts with the current state of the resource.',
        410 => 'That resource is no longer available.',
        413 => 'The request payload is too large.',
        415 => 'The request payload media type is not supported.',
        422 => 'The request was well-formed but could not be processed.',
        429 => 'Too many requests — retry after the interval in the Retry-After header.',
        503 => 'The service is temporarily unavailable.',
    ];

    /**
     * Register with `$exceptions->render(ApiErrorRenderer::render(...))`.
     *
     * Returns null for anything that is not on the API surface, so the web console and
     * the hosted UI keep Laravel's HTML error pages.
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        if ($e instanceof ValidationException) {
            // `errors` is kept — it is the useful part of a 422 and both specs now
            // document it as an optional member of the envelope. `error` is what a
            // client branches on; `errors` is what it shows a human.
            return self::envelope('validation_failed', $e->getMessage(), $e->status, ['errors' => $e->errors()]);
        }

        if ($e instanceof AuthenticationException) {
            return self::envelope('unauthorized', self::MESSAGES[401], 401);
        }

        if ($e instanceof AuthorizationException) {
            return self::envelope('forbidden', self::text($e->getMessage(), 403), 403);
        }

        if ($e instanceof ModelNotFoundException) {
            return self::envelope('not_found', self::MESSAGES[404], 404);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            // Headers matter: a 429 carries Retry-After / X-RateLimit-*, and a 401
            // may carry WWW-Authenticate. Dropping them would break the client's
            // ability to back off correctly.
            return self::envelope(
                self::CODES[$status] ?? 'request_failed',
                self::text($e->getMessage(), $status),
                $status,
                headers: self::headers($e),
            );
        }

        // Anything left is a bug on our side. In production the message is generic —
        // an exception message can carry internals — but the class and message are
        // surfaced under APP_DEBUG so local development is not made blind by this.
        return self::envelope(
            'server_error',
            config('app.debug') === true
                ? $e::class.': '.$e->getMessage()
                : 'An unexpected error occurred.',
            500,
        );
    }

    /**
     * Symfony declares `getHeaders(): array` with no value type, so narrow it here
     * rather than casting at the call site.
     *
     * @return array<string, string|list<string>>
     */
    private static function headers(HttpExceptionInterface $e): array
    {
        $headers = [];

        foreach ($e->getHeaders() as $name => $value) {
            if (! is_string($name)) {
                continue;
            }

            // Scalars, not just strings: the throttle sets `Retry-After` and the
            // `X-RateLimit-*` family as INTEGERS, and dropping them would leave a
            // rate-limited client with no way to know how long to back off.
            if (is_scalar($value)) {
                $headers[$name] = (string) $value;

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $values = [];

            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $values[] = (string) $item;
                }
            }

            $headers[$name] = $values;
        }

        return $headers;
    }

    private static function text(string $message, int $status): string
    {
        return trim($message) !== '' ? $message : (self::MESSAGES[$status] ?? 'The request could not be completed.');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string|list<string>>  $headers
     */
    private static function envelope(string $error, string $message, int $status, array $extra = [], array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => $error, 'message' => $message] + $extra, $status, $headers);
    }
}
