<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\PageController;
use App\Platform\Actions\Action;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\ActionVia;
use App\Platform\Actions\Principal\PortalPrincipal;
use App\Platform\AdminPortal;
use App\Platform\Enums\PortalIntent;
use App\Platform\Portal\PortalProgress;
use Cbox\Id\Organization\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Response;
use Inertia\ResponseFactory;

/**
 * WHAT EVERY ADMIN PORTAL PAGE SHARES — an external IT administrator, with no account here
 * at all, setting up one organization under a single-use link.
 *
 * EVERY WRITE IS AN ACTION, run as the session's {@see PortalPrincipal}: the same action,
 * rules and trail entry the console and the management API run, with the principal
 * confined to the link's organization and refusing anything the link's intents do not
 * cover. The organization is never read from the request — every action is handed the
 * session's — so the id somebody would need to reach another tenant is not one they hold.
 *
 * EVERY PAGE ASKS ITS INTENT ({@see requireIntent()}): a link opened for directory sync has
 * no domains page, and a 404 says so rather than a page of disabled buttons.
 *
 * REFUSALS IN THE VISITOR'S LANGUAGE. An action's refusal is a sentence for the API's
 * caller, in English; here it is looked up by its code in `portal.errors.*`, and an
 * unknown code gets the catalogue's general sentence rather than the English one.
 */
abstract readonly class PortalController extends PageController
{
    public function __construct(
        ResponseFactory $inertia,
        protected AdminPortal $portal,
        protected PortalProgress $progress,
    ) {
        parent::__construct($inertia);
    }

    /**
     * Render a portal page with the chrome every one of them draws: the organization's name
     * and the way back to the checklist.
     *
     * @param  array<string, mixed>  $props
     */
    protected function portalPage(string $component, string $title, array $props = []): Response
    {
        return $this->page($component, $title, [
            ...$props,
            'portal' => [
                'organizationName' => $this->organizationName(),
                'homeHref' => route('portal.setup'),
            ],
        ]);
    }

    /**
     * The organization bound to the session — the only one any portal page acts on.
     */
    protected function organizationId(): string
    {
        $organizationId = $this->portal->boundOrgId();

        abort_if($organizationId === null, 403);

        return $organizationId;
    }

    protected function organizationName(): ?string
    {
        $name = Organization::query()->whereKey($this->organizationId())->value('name');

        return is_string($name) ? $name : null;
    }

    /**
     * Refuse a page or a write the link does not cover — a 404, the honest answer to a
     * session that has no such page.
     */
    protected function requireIntent(PortalIntent ...$intents): void
    {
        foreach ($intents as $intent) {
            if ($this->portal->canConfigure($intent)) {
                return;
            }
        }

        abort(404);
    }

    /**
     * Run an action as the portal session, and turn a refusal into what a form expects:
     * back, with a translated message on the field it is about. `$fields` maps the action's
     * input names to the form's; anything else lands on `$fallback`.
     *
     * @param  class-string<Action>  $action
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $fields
     */
    protected function act(string $action, array $input, array $fields = [], string $fallback = 'form'): ActionResult|RedirectResponse
    {
        try {
            return app(ActionRunner::class)->run($action, $this->principal(), $input, via: ActionVia::Portal);
        } catch (ActionRefused $refused) {
            abort_if($refused->status === 404, 404);
            abort_if($refused->status === 403 && $refused->error === 'forbidden', 403);

            $field = $refused->field ?? array_key_first($refused->fields);
            $field = $field === null ? $fallback : ($fields[$field] ?? $fallback);

            return back()->withInput()->withErrors([$field => self::sentence($refused->error)]);
        } catch (ValidationException $invalid) {
            $errors = [];

            foreach ($invalid->errors() as $name => $lines) {
                $first = is_array($lines) ? ($lines[0] ?? null) : null;
                $base = explode('.', (string) $name)[0];
                $errors[$fields[$base] ?? $fallback] = is_string($first) ? $first : $invalid->getMessage();
            }

            return back()->withInput()->withErrors($errors);
        } catch (AuthorizationException) {
            abort(403);
        }
    }

    /**
     * The session's principal; a 403 when the session lapsed between the middleware and
     * here — which the middleware's own redirect will answer on the next request.
     */
    protected function principal(): PortalPrincipal
    {
        $principal = $this->portal->principal();

        abort_if($principal === null, 403);

        return $principal;
    }

    /** A refusal code as a sentence in the visitor's language. */
    public static function sentence(string $code): string
    {
        $key = 'portal.errors.'.$code;
        $line = __($key);

        if (is_string($line) && $line !== $key) {
            return $line;
        }

        $generic = __('portal.errors.generic');

        return is_string($generic) ? $generic : $code;
    }
}
