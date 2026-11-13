<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Support;

use Cbox\Id\Devices\ValueObjects\ApprovalOrigin;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The origin captured when an approval was filed, kept on the approval request's own row
 * (`ciba_requests.origin_*`) so the push and the approvals API say the same thing.
 *
 * Decoration, not state anything depends on: a write that fails is logged and the request
 * stands; a row without an origin reads as "unknown".
 */
final readonly class ApprovalOrigins
{
    public function __construct(private LoggerInterface $logger) {}

    public function put(string $requestId, ApprovalOrigin $origin): void
    {
        try {
            BackchannelAuthRequest::query()->whereKey($requestId)->update($origin->toColumns());
        } catch (Throwable $e) {
            $this->logger->warning('Could not record where an approval was asked from.', [
                'request_id' => $requestId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function find(string $requestId): ?ApprovalOrigin
    {
        try {
            $request = BackchannelAuthRequest::query()->whereKey($requestId)->first();

            return $request === null ? null : $this->of($request);
        } catch (Throwable) {
            return null;
        }
    }

    public function of(BackchannelAuthRequest $request): ?ApprovalOrigin
    {
        return ApprovalOrigin::fromColumns(static fn (string $column): mixed => $request->getAttribute($column));
    }
}
