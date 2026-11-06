<?php

declare(strict_types=1);

namespace Cbox\Id\Billing\Http\Controllers;

use App\Actions\Workspace\Billing\UpdateBillingDetails;
use App\Actions\Workspace\Billing\UpdatePaymentMethod;
use App\Http\Controllers\Console\ConsoleController;
use App\Platform\Actions\ActionRefused;
use App\Platform\OrganizationActivity;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Id\Billing\Contracts\ProjectPayments;
use Cbox\Id\Billing\PlanCatalog;
use Cbox\Id\Billing\Subscriptions;
use Cbox\Id\Platform\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final readonly class PaymentController extends ConsoleController
{
    public function account(Request $request, string $billingOrganization): JsonResponse
    {
        $org = $this->authorize(false);

        return $this->respond(function () use ($org): array {
            $billing = app(BillingManagement::class);
            $profile = $billing->upsertOrganization($org, ['name' => $this->scope->organizationName(), 'customer_account' => true]);
            $plans = array_values(array_filter($billing->plans(null, $org), fn ($p) => $p->product === config('billing.product')));

            return ['profile' => $profile, 'cards' => $billing->paymentMethods($org), 'invoices' => $billing->invoices($org),
                'plans' => array_map(fn ($p): array => ['key' => $p->key, 'name' => $p->name, 'priceMinor' => $p->priceMinor, 'totalMinor' => $p->grossMinor(), 'currency' => $p->currency, 'interval' => $p->interval, 'features' => $p->features], $plans)];
        });
    }

    public function invoice(Request $request, string $billingOrganization): JsonResponse
    {
        $org = $this->authorize(false);
        $request->validate(['number' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9_-]+$/']]);

        return $this->respond(function () use ($org, $request): array {
            $invoice = app(BillingManagement::class)->invoice($org, $request->string('number')->toString());
            abort_unless(is_string($invoice->pdfUrl) && parse_url($invoice->pdfUrl, PHP_URL_SCHEME) === 'https', 404);

            return ['url' => $invoice->pdfUrl];
        });
    }

    public function details(Request $request, string $billingOrganization): JsonResponse
    {
        $this->authorize();
        $input = $this->strings($request, ['billing_email', 'invoice_name', 'address_line_1', 'address_line_2', 'postal_code', 'city', 'country', 'subdivision', 'tax_id']);

        return $this->respond(fn (): array => ['profile' => $this->runAction(UpdateBillingDetails::class, $input)->payload]);
    }

    public function updateCard(Request $request, string $billingOrganization): JsonResponse
    {
        $this->authorize();

        return $this->respond(function () use ($request): array {
            $this->runAction(UpdatePaymentMethod::class, $this->strings($request, ['operation', 'card']));

            return ['ok' => true];
        });
    }

    public function card(Request $request, string $billingOrganization): JsonResponse
    {
        $org = $this->authorize();
        $request->validate(['operation' => ['required', Rule::in(['setup', 'added'])], 'card' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9_-]{1,128}$/']]);
        $data = $this->strings($request, ['operation', 'card']);

        return $this->respond(function () use ($org, $data): array {
            $billing = app(BillingManagement::class);
            if ($data['operation'] === 'setup') {
                return ['intent' => $billing->createSetupIntent($org)];
            }
            $cards = $billing->paymentMethods($org);
            if ($data['operation'] === 'added') {
                if ($cards !== [] && ! array_any($cards, fn ($c) => $c->isDefault)) {
                    $billing->setDefaultPaymentMethod($org, $cards[array_key_last($cards)]->id);
                }

                return ['ok' => true];
            }

            return ['ok' => true];
        });
    }

    public function preview(Request $request, string $project): JsonResponse
    {
        $org = $this->authorize();
        $model = Project::query()->where('organization_id', $org)->findOrFail($project);
        $request->validate(['operation' => ['required', Rule::in(['plan', 'cancel', 'reactivate'])], 'plan' => ['required_if:operation,plan', 'nullable', 'string', 'max:128']]);
        $input = $this->strings($request, ['operation', 'plan']);

        return $this->respond(function () use ($org, $model, $input, $request): array {
            $payments = app(ProjectPayments::class);
            $subscription = $payments->subscription($org, $model->id);
            $operation = $input['operation'];
            $plan = $input['plan'];
            $payload = ['mode' => 'period_end'];
            if ($operation === 'plan') {
                app(PlanCatalog::class)->get($plan, true);
                app(PlanCatalog::class)->environmentLimit($plan);
                $payload = ['plan' => $plan];
                if ($subscription === null) {
                    $priced = array_find(app(BillingManagement::class)->plans(null, $org), fn ($p) => $p->key === $plan && $p->product === config('billing.product'));
                    abort_if($priced === null || $priced->grossMinor() === null, 503, __('billing.unavailable'));
                    $payload['currency'] = $priced->currency;
                    $preview = ['due_now_minor' => $priced->grossMinor(), 'new_recurring_minor' => $priced->grossMinor(), 'currency' => $priced->currency, 'interval' => $priced->interval];
                    $operation = $priced->priceMinor > 0 && app(BillingManagement::class)->paymentMethods($org) === [] ? 'checkout' : 'subscribe';
                } else {
                    $preview = $payments->perform('preview', $org, $model->id, $payload, (string) Str::uuid());
                    $tax = $preview['new_recurring_tax'] ?? null;
                    abort_unless(is_array($tax) && is_int($tax['total_minor'] ?? null), 503, __('billing.unavailable'));
                    $preview['currency'] = $this->subscriptionCurrency($subscription);
                    $operation = 'change';
                }
            } elseif ($operation === 'cancel') {
                $preview = $payments->perform('cancel-preview', $org, $model->id, $payload, (string) Str::uuid());
            } else {
                $preview = $this->renewalPreview($subscription);
                $payload = [];
            }
            $quotes = $request->session()->get('billing.quotes', []);
            $quotes = is_array($quotes) ? array_filter($quotes, fn ($q): bool => is_array($q) && is_int($q['expires'] ?? null) && $q['expires'] >= time()) : [];
            $request->session()->put('billing.quotes', array_slice($quotes, -19, null, true));
            $id = (string) Str::uuid();
            $request->session()->put('billing.quotes.'.$id, ['org' => $org, 'actor' => $this->scope->actorId(), 'project' => $model->id,
                'operation' => $operation, 'input' => $payload, 'preview' => $preview, 'expires' => time() + 300]);

            return ['quote' => $id, 'preview' => $preview, 'operation' => $operation];
        });
    }

    public function confirm(Request $request, string $project): JsonResponse
    {
        $org = $this->authorize();
        $model = Project::query()->where('organization_id', $org)->findOrFail($project);
        $request->validate(['quote' => ['required', 'uuid']]);
        $quoteId = $request->string('quote')->toString();
        $quote = $request->session()->get('billing.quotes.'.$quoteId);
        abort_unless(is_array($quote) && $quote['org'] === $org && $quote['actor'] === $this->scope->actorId() && $quote['project'] === $model->id && $quote['expires'] >= time(), 409, __('billing.expired'));
        abort_unless(is_string($quote['operation']) && is_array($quote['input']) && is_array($quote['preview']), 409);
        $operation = $quote['operation'];
        $payload = array_filter($quote['input'], is_string(...), ARRAY_FILTER_USE_KEY);
        $preview = array_filter($quote['preview'], is_string(...), ARRAY_FILTER_USE_KEY);

        return $this->respond(function () use ($org, $model, $operation, $payload, $preview, $quoteId): array {
            $result = Cache::lock('billing:confirm:'.$quoteId, 30)->block(3, function () use ($org, $model, $operation, $payload, $preview, $quoteId): array {
                $key = 'billing:result:'.$quoteId;
                if (is_array($cached = Cache::get($key))) {
                    return array_filter($cached, is_string(...), ARRAY_FILTER_USE_KEY);
                }
                $payments = app(ProjectPayments::class);
                // Recheck the quoted economics, including bundle consequences, before
                // money moves. A changed quote needs fresh, explicit customer consent.
                if (in_array($operation, ['change', 'cancel'], true)) {
                    $fresh = $payments->perform($operation === 'change' ? 'preview' : 'cancel-preview', $org, $model->id, $payload, (string) Str::uuid());
                    if ($operation === 'change') {
                        $subscription = $payments->subscription($org, $model->id);
                        $fresh['currency'] = $this->subscriptionCurrency($subscription);
                    }
                    abort_unless($this->economics($preview) === $this->economics($fresh), 409, __('billing.expired'));
                } elseif (in_array($operation, ['subscribe', 'checkout'], true)) {
                    $plan = $payload['plan'] ?? null;
                    abort_unless(is_string($plan), 409);
                    app(PlanCatalog::class)->get($plan, true);
                    $priced = array_find(app(BillingManagement::class)->plans(null, $org), fn ($p) => $p->key === $plan && $p->product === config('billing.product'));
                    abort_unless($priced !== null && $priced->grossMinor() !== null && $priced->grossMinor() === ($preview['due_now_minor'] ?? null) && $priced->currency === ($preview['currency'] ?? null) && $priced->interval === ($preview['interval'] ?? null), 409, __('billing.expired'));
                }
                if ($operation === 'reactivate') {
                    $fresh = $this->renewalPreview($payments->subscription($org, $model->id));
                    abort_unless($this->economics($preview) === $this->economics($fresh), 409, __('billing.expired'));
                }
                if ($operation === 'checkout') {
                    $payload += ['return_url' => route('billing'), 'currency' => Config::string('billing.currency')];
                }
                app(OrganizationActivity::class)->record($org, 'billing.'.$operation.'.requested', $this->scope->actorId(), 'project', $model->id, ['confirmation' => $quoteId, 'plan' => $payload['plan'] ?? null], request());
                $remote = $payments->perform($operation, $org, $model->id, $payload, $quoteId);
                $url = $remote['url'] ?? null;
                if ($operation === 'checkout') {
                    abort_unless(is_string($url) && parse_url($url, PHP_URL_SCHEME) === 'https', 502);
                    $answer = ['url' => $url];
                } else {
                    $answer = ['ok' => true];
                }
                Cache::put($key, $answer, 600);
                app(OrganizationActivity::class)->record($org, 'billing.'.$operation.'.completed', $this->scope->actorId(), 'project', $model->id, ['confirmation' => $quoteId], request());
                // Persist success before the read: a failed refresh must not repeat a charge.
                try {
                    app(Subscriptions::class)->refresh($model);
                } catch (Throwable $e) {
                    report($e);
                }

                return $answer;
            });
            if (! is_array($result)) {
                throw new \RuntimeException('Payment confirmation did not return a result.');
            }

            return array_filter($result, is_string(...), ARRAY_FILTER_USE_KEY);
        });
    }

    /** @param array<string, mixed>|null $subscription */
    private function subscriptionCurrency(?array $subscription): string
    {
        $recurring = $subscription['recurring'] ?? null;
        $currency = is_array($recurring) ? ($recurring['currency'] ?? null) : null;
        abort_unless(is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) === 1, 503, __('billing.unavailable'));

        return $currency;
    }

    /** @param array<string, mixed>|null $subscription
     * @return array<string, mixed>
     */
    private function renewalPreview(?array $subscription): array
    {
        // Only undo an ordinary period-end cancellation. Billing's broader reactivate
        // operation can charge paused/ended contracts; those need a separate priced offer.
        abort_unless($subscription !== null && ($subscription['status'] ?? null) === 'active'
            && ($subscription['cancel_at_period_end'] ?? false) === true
            && ($subscription['paused'] ?? false) === false
            && ($subscription['cancel_at'] ?? null) === null, 409, __('billing.expired'));
        $recurring = $subscription['recurring'] ?? null;
        abort_unless(is_array($recurring) && is_int($recurring['net_minor'] ?? null)
            && is_array($recurring['tax'] ?? null) && is_int($recurring['tax']['total_minor'] ?? null)
            && is_string($recurring['currency'] ?? null), 503, __('billing.unavailable'));

        return ['due_now_minor' => 0, 'new_recurring_minor' => $recurring['net_minor'],
            'new_recurring_tax' => $recurring['tax'], 'currency' => $recurring['currency'],
            'interval' => $subscription['interval'] ?? null, 'ends_at' => $subscription['period_end'] ?? null];
    }

    /** @param array<string, mixed> $preview */
    private function economics(array $preview): string
    {
        // effective_at can mean "now" and change by a second; ends_at is a fixed date.
        return json_encode(array_intersect_key($preview, array_flip(['currency', 'interval', 'due_now_tax', 'due_now_minor', 'credit_minor', 'new_recurring_minor', 'new_recurring_tax', 'ends_at', 'mode', 'consequences', 'credit_delta', 'discount'])), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function strings(Request $request, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $request->string($key)->toString();
        }

        return $result;
    }

    private function authorize(bool $write = true): string
    {
        abort_unless($this->scope->capabilities()?->canReadBilling() === true, 403);
        if ($write) {
            abort_unless($this->scope->capabilities()->canManageBilling() === true && $this->scope->actorEmailVerified(), 403);
        }
        abort_unless(config('billing-client.base_url') && config('billing-client.api_token'), 503, __('billing.unavailable'));

        $org = $this->scope->requireOrganizationId();
        $target = request()->route('billingOrganization');
        abort_unless($target === null || $target === $org, 409, __('billing.workspace_changed'));

        return $org;
    }

    /** @param callable(): array<string, mixed> $callback */
    private function respond(callable $callback): JsonResponse
    {
        try {
            return response()->json($callback())->header('Cache-Control', 'no-store');
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (ActionRefused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status)->header('Cache-Control', 'no-store');
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422)->header('Cache-Control', 'no-store');
        } catch (TransportException $e) {
            report($e);
            $body = json_decode($e->body ?? '{}', true);
            if (is_array($body) && ($body['error'] ?? null) === 'billing_address_required') {
                return response()->json(['message' => __('billing.address_required')], 422)->header('Cache-Control', 'no-store');
            }

            return response()->json(['message' => __('billing.unavailable')], 503)->header('Cache-Control', 'no-store');
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => __('billing.unavailable')], 503)->header('Cache-Control', 'no-store');
        }
    }
}
