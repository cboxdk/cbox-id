<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Discovery;

use App\Platform\OAuth\SupportedPrompts;
use Cbox\Id\Api\Http\Controllers\AuthorizationServerMetadataController as FrameworkMetadataController;
use Cbox\Id\Api\Support\ServerMetadata;
use Illuminate\Http\JsonResponse;

/**
 * `GET /.well-known/oauth-authorization-server` (RFC 8414) — the same document as OIDC
 * discovery, so the same prompt values. Two documents that must agree are one answer.
 */
final class AuthorizationServerMetadataController extends FrameworkMetadataController
{
    public function __construct(private readonly SupportedPrompts $prompts) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->prompts->describe(ServerMetadata::document()));
    }
}
