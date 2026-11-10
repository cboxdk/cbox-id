<?php

declare(strict_types=1);

namespace Cbox\Id\Billing\Http\Controllers;

use App\Actions\OrgPlane\Billing\RemovePaymentMethod;
use App\Actions\OrgPlane\Billing\SetDefaultPaymentMethod;
use App\Actions\OrgPlane\Billing\UpdateBillingAccount;
use App\Http\Controllers\Console\ConsoleController;
use App\Http\Props\Shared\HelpProps;
use App\Platform\Billing\ProductBilling;
use App\Platform\Help\HelpTopic;
use Cbox\Billing\Client\BillingManagement;
use Cbox\Billing\Client\Exceptions\TransportException;
use Cbox\Id\Billing\Contracts\ProjectPayments;
use Cbox\Id\Platform\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\Intl\Countries;
use Throwable;

/** Financial data uses the exact current workspace and fresh native product capabilities. */
final readonly class BillingController extends ConsoleController
{
    public function __invoke(Request $request, BillingManagement $billing, ProductBilling $product): Response|HttpResponse
    {
        if (! $request->has('organization')) {
            abort_unless($this->scope->capabilities()?->canReadBilling() === true, 403);

            return redirect()->route('orgs.settings.billing', ['organization' => $this->scope->organizationId()]);
        }
        $organization = $this->organization();
        if ($request->hasAny(['setup_intent', 'setup_intent_client_secret', 'redirect_status'])) {
            return redirect()->route('orgs.settings.billing', ['organization' => $organization, 'setup_return' => 1]);
        }
        if ($request->hasHeader('X-Inertia') && $request->header('X-Inertia-Partial-Component') !== 'billing::billing') {
            return Inertia::location($request->fullUrl());
        }
        $errors = [];
        $read = static function (string $section, callable $callback) use (&$errors): mixed {
            try {
                return $callback();
            } catch (Throwable $error) {
                report($error);
                $errors[$section] = __('billing.unavailable');

                return null;
            }
        };

        return $this->page('billing::billing', __('console.billing_3ac8bbca'), [
            'help' => HelpProps::for(HelpTopic::Billing),
            'organization' => $organization,
            'countries' => collect(Countries::getNames(app()->getLocale()))->map(static fn (string $label, string $value): array => compact('value', 'label'))->values()->all(),
            'canManage' => $this->scope->capabilities()?->canManageBilling() === true,
            'account' => $read('account', fn () => $billing->upsertOrganization($organization, ['name' => DB::table('organizations')->where('id', $organization)->value('name'), 'customer_account' => true])),
            'subscription' => $read('subscription', fn () => $this->subscription($billing, $organization)),
            'paymentMethods' => $read('paymentMethods', fn () => $billing->paymentMethods($organization)),
            'invoices' => $read('invoices', fn () => array_map(static fn ($invoice): array => [
                'number' => $invoice->number, 'date' => $invoice->date?->format('Y-m-d'),
                'amountMinor' => $invoice->amountMinor, 'subtotalMinor' => $invoice->subtotalMinor,
                'taxMinor' => $invoice->taxMinor, 'currency' => $invoice->currency, 'status' => $invoice->status,
                'downloadUrl' => route('orgs.settings.billing.invoice', ['invoice' => $invoice->number, 'organization' => $organization]),
            ], $billing->invoices($organization))),
            'projectPayments' => $read('projectPayments', fn () => $this->projectPayments($billing, $organization)),
            'environmentAllowance' => $read('features', fn () => $product->environmentLimit($organization)),
            'sectionErrors' => $errors,
        ]);
    }

    public function updateAccount(Request $request): RedirectResponse
    {
        $this->organization(true);
        $attributes = [];
        foreach (['billing_email', 'invoice_name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'tax_id'] as $field) {
            if ($request->exists($field)) {
                $attributes[$field] = $request->input($field);
            }
        }
        $fields = ['billing_email', 'invoice_name', 'address_line1', 'address_line2', 'postal_code', 'city', 'country', 'tax_id'];
        $result = $this->act(UpdateBillingAccount::class, $attributes, array_combine($fields, $fields), 'payment');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('billing.account_saved'));
    }

    public function setup(Request $request, BillingManagement $billing): JsonResponse
    {
        $organization = $this->organization(true);
        try {
            $existing = array_map(static fn ($method): string => $method->id, $billing->paymentMethods($organization));
            $intent = $billing->createSetupIntent($organization);
            $request->session()->put('billing.pending_setup', ['organization' => $organization, 'reference' => $intent->reference, 'existing' => $existing, 'expires' => now()->addHour()->timestamp]);

            return response()->json($intent)->header('Cache-Control', 'private, no-store');
        } catch (TransportException $error) {
            report($error);

            return response()->json(['message' => __('billing.payment_failed')], 503);
        }
    }

    public function complete(Request $request, BillingManagement $billing): JsonResponse
    {
        $organization = $this->organization(true);
        $pending = $request->session()->get('billing.pending_setup');
        abort_unless(is_array($pending) && ($pending['organization'] ?? null) === $organization && is_int($pending['expires'] ?? null) && $pending['expires'] >= now()->timestamp, 403);
        $request->validate(['payment_method' => ['nullable', 'string', 'max:255']]);
        $asked = $request->input('payment_method');
        try {
            $methods = $billing->paymentMethods($organization);
            $existing = is_array($pending['existing'] ?? null) ? $pending['existing'] : [];
            $newMethods = collect($methods)->reject(static fn ($method): bool => in_array($method->id, $existing, true));
            $selected = is_string($asked) ? $newMethods->firstWhere('id', $asked) : ($newMethods->count() === 1 ? $newMethods->first() : null);
            if ($selected === null) {
                return response()->json(['message' => __('billing.payment_pending')], 409);
            }
            if (! collect($methods)->contains('isDefault', true)) {
                $billing->setDefaultPaymentMethod($organization, $selected->id);
            }
            $request->session()->forget('billing.pending_setup');

            return response()->json(['status' => 'saved', 'paymentMethods' => $billing->paymentMethods($organization)])->header('Cache-Control', 'private, no-store');
        } catch (TransportException $error) {
            report($error);

            return response()->json(['message' => __('billing.payment_failed')], 503);
        }
    }

    public function setDefault(string $method): RedirectResponse
    {
        $this->organization(true);
        $result = $this->act(SetDefaultPaymentMethod::class, ['id' => $method], [], 'payment');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('billing.default_saved'));
    }

    public function remove(string $method): RedirectResponse
    {
        $this->organization(true);
        $result = $this->act(RemovePaymentMethod::class, ['id' => $method], [], 'payment');

        return $result instanceof RedirectResponse ? $result : back()->with('status', __('billing.card_removed'));
    }

    public function invoice(string $invoice, BillingManagement $billing): HttpResponse
    {
        $organization = $this->organization();
        try {
            $document = $billing->invoice($organization, $invoice);
            $url = $document->pdfUrl;
            $base = config('billing-client.base_url');
            abort_unless(is_string($url) && is_string($base) && parse_url($url, PHP_URL_SCHEME) === 'https'
                && parse_url($url, PHP_URL_HOST) === parse_url($base, PHP_URL_HOST)
                && parse_url($url, PHP_URL_PORT) === parse_url($base, PHP_URL_PORT), 503);
            $response = Http::timeout(15)->withoutRedirecting()->get($url);
            abort_unless($response->successful() && strlen($response->body()) <= 20_000_000, 503);

            return response($response->body(), 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store', 'Content-Disposition' => 'attachment; filename="invoice.pdf"']);
        } catch (TransportException $error) {
            report($error);
            abort(503, __('billing.unavailable'));
        }
    }

    /** @return array<string, mixed>|null */
    private function subscription(BillingManagement $billing, string $organization): ?array
    {
        $subscription = $billing->subscription($organization);
        if ($subscription === null) {
            return null;
        }

        return [
            'plan' => $subscription->plan, 'status' => $subscription->status, 'paused' => $subscription->paused,
            'pauseReason' => $subscription->pauseReason, 'periodStart' => $subscription->periodStart?->format(DATE_ATOM),
            'periodEnd' => $subscription->periodEnd?->format(DATE_ATOM), 'renewsAt' => $subscription->renewsAt?->format(DATE_ATOM),
            'trialEndsAt' => $subscription->trialEndsAt?->format(DATE_ATOM),
            'recurring' => $subscription->recurring === null ? null : [
                'netMinor' => $subscription->recurring->netMinor, 'totalMinor' => $subscription->recurring->totalMinor(),
                'currency' => $subscription->recurring->currency, 'tax' => $subscription->recurring->tax?->toArray(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function projectPayments(BillingManagement $billing, string $organization): array
    {
        $plans = array_values(array_filter($billing->plans(null, $organization),
            fn ($plan) => $plan->product === config('billing.product')));
        $payments = app(ProjectPayments::class);
        $projects = Project::query()->where('organization_id', $organization)->orderBy('name')->get();

        return [
            'projects' => $projects->map(static function (Project $project) use ($payments, $organization): array {
                $subscription = $payments->subscription($organization, $project->id);

                return [
                    'id' => $project->id, 'name' => $project->name,
                    'previewHref' => route('orgs.settings.billing.preview', $project->id),
                    'confirmHref' => route('orgs.settings.billing.confirm', $project->id),
                    'subscription' => $subscription === null ? null : [
                        'plan' => $subscription['plan'] ?? null, 'status' => $subscription['status'] ?? null,
                        'data' => array_intersect_key($subscription, array_flip(['cancel_at_period_end', 'period_end'])),
                    ],
                ];
            })->all(),
            'plans' => array_map(static fn ($plan): array => [
                'key' => $plan->key, 'name' => $plan->name, 'priceMinor' => $plan->priceMinor,
                'totalMinor' => $plan->grossMinor(), 'currency' => $plan->currency,
                'interval' => $plan->interval, 'features' => $plan->features,
            ], $plans),
            'labels' => __('billing'),
        ];
    }

    private function organization(bool $write = false): string
    {
        abort_unless($write ? $this->scope->capabilities()?->canManageBilling() === true : $this->scope->capabilities()?->canReadBilling() === true, 403);
        $organization = $this->scope->organizationId();
        abort_unless(is_string($organization) && $organization !== '' && request()->input('organization') === $organization, 403);

        return $organization;
    }
}
