<?php

declare(strict_types=1);

namespace App\Platform\Api;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * An RFC 9457 problem document — every failure on the API host.
 *
 * `type` is `https://api.cboxid.com/problems/{error}`, `title` the status's reason, `detail`
 * the human sentence, and `error` keeps the machine code the old envelope carried, so a
 * client that branched on it keeps working. A validation failure adds `errors`, keyed by
 * field.
 */
final class Problem
{
    public const string CONTENT_TYPE = 'application/problem+json';

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, string|list<string>>  $headers
     */
    public static function response(string $error, string $detail, int $status, array $extra = [], array $headers = []): JsonResponse
    {
        $body = [
            'type' => ApiUrls::problem($error),
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => $detail,
            'error' => $error,
            ...$extra,
        ];

        return new JsonResponse($body, $status, ['Content-Type' => self::CONTENT_TYPE, ...$headers], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The problem a JSON error body becomes: the old `{error, message}` envelope and the
     * RFC 6750 `{error, error_description}` pair alike. Null for a body that is neither.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    public static function fromEnvelope(array $body, int $status): ?array
    {
        if (isset($body['type'], $body['status'])) {
            return null;
        }

        $error = is_string($body['error'] ?? null) && $body['error'] !== '' ? $body['error'] : null;
        $detail = $body['message'] ?? $body['error_description'] ?? null;

        if ($error === null && ! is_string($detail)) {
            return null;
        }

        $extra = array_diff_key($body, array_flip(['error', 'message', 'error_description']));

        return [
            'type' => ApiUrls::problem($error ?? 'request_failed'),
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
            'detail' => is_string($detail) && $detail !== '' ? $detail : (Response::$statusTexts[$status] ?? 'The request failed.'),
            'error' => $error ?? 'request_failed',
            ...$extra,
        ];
    }
}
