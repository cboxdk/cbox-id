<?php

declare(strict_types=1);

namespace Cbox\Id\Devices\Http\Controllers;

use Cbox\Id\Kernel\Audit\Contracts\AuditLog;
use Cbox\Id\Kernel\Audit\Enums\ActorType;
use Cbox\Id\Kernel\Audit\ValueObjects\AuditEvent;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\OAuthServer\Contracts\ClientRegistry;
use Cbox\Id\OAuthServer\Enums\GrantPollStatus;
use Cbox\Id\OAuthServer\Models\BackchannelAuthRequest;
use Cbox\Id\OAuthServer\ValueObjects\Introspection;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The mobile half of the CIBA approval surface — the same decisions the console's
 * /approvals page makes, over HTTP.
 *
 * The scope labels are duplicated from that page on purpose: web and mobile must
 * describe the same permission in the same words, because a user comparing them is the
 * entire anti-phishing mechanism here.
 */
final class ApprovalController
{
    /** Human wording for the scopes an agent commonly asks for. */
    private const SCOPE_LABELS = [
        'openid' => 'Verify your identity',
        'profile' => 'See your basic profile',
        'email' => 'See your email address',
        'offline_access' => 'Stay signed in',
        'organizations' => 'See your organizations',
    ];

    public function __construct(
        private readonly BackchannelAuthentication $ciba,
        private readonly ClientRegistry $clients,
        private readonly AuditLog $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $requests = BackchannelAuthRequest::query()
            ->where('user_id', $this->subject($request))
            ->where('status', GrantPollStatus::Pending->value)
            ->where('expires_at', '>', Carbon::now())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return new JsonResponse([
            'data' => $requests->map(fn (BackchannelAuthRequest $r): array => $this->present($r))->all(),
            'meta' => ['limit' => 50, 'has_more' => false, 'next_cursor' => null],
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $approval = $this->findForCaller($request, $id);

        if ($approval === null) {
            return $this->notFound();
        }

        return new JsonResponse(['data' => $this->present($approval)]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, approve: true);
    }

    public function deny(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, approve: false);
    }

    private function decide(Request $request, string $id, bool $approve): JsonResponse
    {
        $subjectId = $this->subject($request);

        // The organization is taken from the REQUEST's own recorded context, not from
        // the approver's token. Reading it off the token means a user who belongs to
        // orgs A and B, whose app happens to hold a token claiming org A, mints the
        // agent a token scoped to A even when the request came from a client owned by
        // B. The console has that bug; the app, which has no org switcher at all, would
        // hit it constantly.
        $organizationId = $approve
            ? BackchannelAuthRequest::query()->whereKey($id)->value('organization_id')
            : null;

        $settled = $approve
            ? $this->ciba->approve($id, $subjectId, is_string($organizationId) ? $organizationId : null)
            : $this->ciba->deny($id, $subjectId);

        if (! $settled) {
            // approve()/deny() collapse unknown, expired, not-pending and wrong-subject
            // into a single false, and that collapse is KEPT here. Distinguishing them
            // would tell someone holding a stolen handset whether a given request id
            // exists and whose it is.
            return new JsonResponse([
                'error' => 'approval_not_actionable',
                'message' => 'This request is no longer pending. It may have expired or already been answered.',
            ], 409);
        }

        $this->audit->record(new AuditEvent(
            action: $approve ? 'approval.approved' : 'approval.denied',
            actorType: ActorType::User,
            actorId: $subjectId,
            organizationId: is_string($organizationId) ? $organizationId : null,
            targetType: 'ciba_request',
            targetId: $id,
            context: ['surface' => 'authenticator_app'],
            ip: $request->ip(),
        ));

        return new JsonResponse(['data' => [
            'id' => $id,
            'status' => $approve ? GrantPollStatus::Approved->value : GrantPollStatus::Denied->value,
        ]]);
    }

    private function findForCaller(Request $request, string $id): ?BackchannelAuthRequest
    {
        return BackchannelAuthRequest::query()
            ->whereKey($id)
            ->where('user_id', $this->subject($request))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BackchannelAuthRequest $approval): array
    {
        $client = $this->clients->byClientId($approval->client_id);

        $scopes = array_map(
            static fn (string $scope): array => [
                'scope' => $scope,
                'label' => self::SCOPE_LABELS[$scope] ?? $scope,
            ],
            array_values(array_filter($approval->scopes, 'is_string')),
        );

        return [
            // The INTERNAL id. Never auth_req_id, which is the initiating client's
            // polling secret and is stored only as a hash.
            'id' => $approval->id,
            'client_id' => $approval->client_id,
            'client_name' => $client->name ?? $approval->client_id,
            // The transaction description the user is meant to compare against what the
            // requesting app showed them. This is the one field that must never ride in
            // a push payload, and the reason this endpoint exists at all.
            'binding_message' => $approval->binding_message,
            'scopes' => $scopes,
            'organization_id' => $approval->organization_id,
            'requested_at' => $this->iso($approval->getAttribute('created_at')),
            'expires_at' => $this->iso($approval->getAttribute('expires_at')),
        ];
    }

    /**
     * Narrow a timestamp read off the CIBA model.
     *
     * Read through getAttribute() and checked here rather than accessed directly: this
     * is a laravel-id model, its `created_at` carries no property annotation, and
     * `expires_at` is annotated non-nullable while the column is not. Neither is this
     * module's to fix, so the uncertainty is resolved at the boundary where it is used.
     */
    private function iso(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? Carbon::instance($value)->toIso8601String()
            : null;
    }

    private function notFound(): JsonResponse
    {
        // Constant, whether the request does not exist or belongs to someone else.
        return new JsonResponse(['error' => 'not_found', 'message' => 'No such request.'], 404);
    }

    private function subject(Request $request): string
    {
        $token = $request->attributes->get('cbox_token');

        abort_unless($token instanceof Introspection, 401);

        $subject = $token->subject;

        abort_if($subject === null || $subject === '', 403);

        return $subject;
    }
}
