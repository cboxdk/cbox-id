<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Platform\CurrentUser;
use App\Platform\EnvironmentAdminAuth;
use App\Platform\EnvironmentSudo;
use App\Platform\IntendedUrl;
use App\Platform\Mfa\BrowserApproval;
use App\Platform\PlatformAuth;
use App\Platform\SamlSsoHandoff;
use App\Platform\StepUpReason;
use App\Platform\Sudo;
use Cbox\Id\OAuthServer\Contracts\BackchannelAuthentication;
use Cbox\Id\Platform\PlatformRoot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The existing phone approval, bound to the pending login or this console session. */
final readonly class MobileApprovalController
{
    public function __invoke(Request $request, BrowserApproval $approvals, PlatformAuth $auth): JsonResponse
    {
        $purpose = $request->route('purpose');
        abort_unless(in_array($purpose, ['login', 'organization', 'environment'], true), 404);
        if (! $request->isMethod('GET')) {
            $request->validate(['operation' => ['required', 'in:start,complete,cancel']]);
        }
        $operation = $request->isMethod('GET') ? 'status' : $request->input('operation');

        if ($purpose === 'login') {
            $subject = $auth->pendingMfaSubject($request);
            abort_if($subject === null, 409);
            $anchor = $auth->pendingMfaChallenge($request);
        } elseif ($purpose === 'environment') {
            $admin = app(EnvironmentAdminAuth::class);
            abort_unless($admin->check(), 403);
            $subject = $admin->subjectId();
            $anchor = $admin->sessionId();
            abort_if($subject === null || $anchor === null, 403);
        } else {
            $me = app(CurrentUser::class);
            abort_unless($me->check(), 401);
            $subject = $me->id();
            $anchor = ($me->session()->id ?? '').':'.($me->organizationId() ?? '');
        }

        $run = function () use ($request, $approvals, $auth, $purpose, $subject, $anchor, $operation): JsonResponse {
            if ($operation === 'cancel') {
                $key = 'cbox.browser_approval.'.$purpose;
                if (! $request->has('code') || $request->input('code') === $request->session()->get($key.'.code')) {
                    $id = $request->session()->get($key.'.id');
                    if (is_string($id)) {
                        app(BackchannelAuthentication::class)->deny($id, $subject);
                    }
                    $request->session()->forget($key);
                }

                return $this->json(['status' => 'cancelled']);
            }
            if ($operation !== 'complete') {
                return $this->json($approvals->respond($request, $purpose, $subject, $anchor, $operation === 'start'));
            }
            if ($purpose === 'login') {
                abort_unless($auth->completeMfaWithMobile($request), 409);
                $redirect = app(SamlSsoHandoff::class)->resumeUrl() ?? IntendedUrl::pullForSubject() ?? route('dashboard');
            } else {
                abort_unless($approvals->consume($request, $purpose, $subject, $anchor), 409);
                $purpose === 'environment' ? app(EnvironmentSudo::class)->confirm() : app(Sudo::class)->confirm();
                $plane = $purpose === 'environment' ? 'environment.sudo' : 'sudo';
                $redirect = $request->session()->pull($plane.'.intended');
                StepUpReason::forget($plane);
                $redirect = is_string($redirect) ? $redirect : route($purpose === 'environment' ? 'environment.home' : 'account');
            }

            return $this->json(['status' => 'confirmed', 'redirect' => $redirect]);
        };

        return $purpose === 'environment'
            ? (app(PlatformRoot::class)->run($run) ?? abort(403))
            : $run();
    }

    /** @param array<string, mixed> $data */
    private function json(array $data): JsonResponse
    {
        return new JsonResponse($data, 200, ['Cache-Control' => 'no-store']);
    }
}
