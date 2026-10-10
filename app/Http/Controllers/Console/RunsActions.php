<?php

declare(strict_types=1);

namespace App\Http\Controllers\Console;

use App\Platform\Actions\Action;
use App\Platform\Actions\ActionRefused;
use App\Platform\Actions\ActionResult;
use App\Platform\Actions\ActionRunner;
use App\Platform\Actions\ActionVia;
use App\Platform\Actions\Principal\ConsoleSessionPrincipal;
use App\Platform\Console\ConsoleScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * How a console page runs an action as the person signed in, and turns the outcome into
 * what a form expects.
 *
 * Every {@see ConsoleController} has it; so does a page that is not one — the person's own
 * account pages extend the plain page controller, and run the account plane's actions
 * through the same three doors. The principal is the console session's
 * ({@see ConsoleSessionPrincipal}), read from the request's {@see ConsoleScope}: the same
 * scoped instance a console controller is constructed with.
 */
trait RunsActions
{
    /**
     * Run an action as the person signed in — the same action, rules and audit entry the
     * management API and MCP run — and turn a refusal into what a form expects: back, with
     * the message on the field it is about.
     *
     * `$fields` maps the action's input names to this page's form field names
     * (`client_id` → `clientId`); a refusal about a field the page does not have lands on
     * `$fallback`. A refusal about several fields lands on each of them. `$messages` lets a
     * page say a refusal in its own words — keyed by the refusal's code — where the API's
     * sentence is written for a machine's caller. A 404 is a 404.
     *
     * @param  class-string<Action>  $action
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $fields
     * @param  array<string, string>  $messages
     * @param  bool  $asEnvironment  for the environment's own settings — see {@see self::runAction()}
     */
    protected function act(string $action, array $input, array $fields = [], string $fallback = 'form', array $messages = [], bool $asEnvironment = false): ActionResult|RedirectResponse
    {
        try {
            return $this->runAction($action, $input, $asEnvironment);
        } catch (ActionRefused $refused) {
            abort_if($refused->status === 404, 404);

            if ($refused->fields !== []) {
                $errors = [];

                foreach ($refused->fields as $name => $message) {
                    $errors[$fields[$name] ?? $fallback] = $message;
                }

                return back()->withInput()->withErrors($errors);
            }

            $field = $refused->field === null ? $fallback : ($fields[$refused->field] ?? $fallback);

            return back()->withInput()->withErrors([$field => $messages[$refused->error] ?? $refused->getMessage()]);
        } catch (ValidationException $invalid) {
            $errors = [];

            foreach ($invalid->errors() as $field => $lines) {
                $first = is_array($lines) ? ($lines[0] ?? null) : null;
                $errors[$fields[$field] ?? $fallback] = is_string($first) ? $first : $invalid->getMessage();
            }

            return back()->withInput()->withErrors($errors);
        }
    }

    /**
     * Run an action as the person signed in, like {@see self::act()}, and hand a refusal
     * BACK instead of answering it — for a page whose refusals are not all a message on one
     * field: a banner across the top, a sentence of the page's own around the action's.
     *
     * The page decides what to say; the action has already decided THAT it refuses, so the
     * rule is the same whichever door asked. Invalid input still lands on the form's fields
     * (`$fields` maps the action's input names to the form's, the rest on `$fallback`), and a
     * 404 is a 404.
     *
     * @param  class-string<Action>  $action
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $fields
     *
     * @throws ValidationException
     */
    protected function attempt(string $action, array $input, array $fields = [], string $fallback = 'form'): ActionResult|ActionRefused
    {
        try {
            return app(ActionRunner::class)->run($action, new ConsoleSessionPrincipal(app(ConsoleScope::class)), $input, via: ActionVia::Console);
        } catch (ActionRefused $refused) {
            abort_if($refused->status === 404, 404);

            return $refused;
        } catch (ValidationException $invalid) {
            $errors = [];

            foreach ($invalid->errors() as $field => $messages) {
                $first = is_array($messages) ? ($messages[0] ?? null) : null;
                $errors[$fields[$field] ?? $fallback] = is_string($first) ? $first : $invalid->getMessage();
            }

            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Run an action as the person signed in and let a refusal through, for a page whose
     * answer to one is not a form error — a quiet no-op, a flash on the button that asked.
     * {@see self::act()} is the form-shaped version.
     *
     * @param  class-string<Action>  $action
     * @param  array<string, mixed>  $input
     * @param  bool  $asEnvironment  act on the environment's own settings — refused unless this
     *                               console's administrator administers the environment
     *                               ({@see ConsoleSessionPrincipal::forEnvironment()})
     *
     * @throws ActionRefused
     * @throws AuthorizationException
     * @throws ValidationException
     */
    protected function runAction(string $action, array $input, bool $asEnvironment = false): ActionResult
    {
        $scope = app(ConsoleScope::class);
        $principal = $asEnvironment ? ConsoleSessionPrincipal::forEnvironment($scope) : new ConsoleSessionPrincipal($scope);

        return app(ActionRunner::class)->run($action, $principal, $input, via: ActionVia::Console);
    }
}
