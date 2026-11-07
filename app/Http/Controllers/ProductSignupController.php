<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Account\JoinServiceWaitlist;
use App\Http\Controllers\Console\RunsActions;
use App\Platform\Actions\ActionRefused;
use App\Platform\CurrentUser;
use App\Platform\IntendedUrl;
use App\Platform\PlatformAuth;
use App\Platform\ServiceAccess\BillingWaitlists;
use App\Platform\ServiceAccess\ProductAdmission;
use App\Platform\ServiceAccess\RegistrationPolicy;
use App\Platform\ServiceAccess\ServiceAccessSettings;
use App\Platform\ServiceAccess\ServiceAccessUnavailable;
use App\Platform\ServiceAccess\WaitlistTeams;
use Cbox\Id\Organization\Contracts\Memberships;
use Cbox\Id\Organization\Contracts\Organizations;
use Cbox\Id\Organization\Enums\MembershipRole;
use Cbox\Id\Organization\ValueObjects\NewOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** The ID product has its own front door; /signup only creates an identity. */
final readonly class ProductSignupController
{
    use RunsActions;

    public function __construct(private CurrentUser $me, private BillingWaitlists $billing, private WaitlistTeams $teams, private ProductAdmission $admission) {}

    public function show(Request $request, ServiceAccessSettings $settings): Response|RedirectResponse
    {
        abort_unless($settings->enabled() || ! $this->admission->enabled(), 404);
        if ($request->filled('invitation')) {
            $request->validate(['invitation' => ['string', 'max:100']]);
            $request->session()->put('product-signup.invitation', Crypt::encryptString($request->string('invitation')->toString()));

            return to_route('product-signup.show', ['invite' => 1])->header('Referrer-Policy', 'no-referrer');
        }
        try {
            $policy = $this->policy();
        } catch (ServiceAccessUnavailable) {
            return response()->view('product-registration.show', ['policy' => null, 'identity' => null, 'teams' => []], 503)->header('Cache-Control', 'no-store');
        }

        return response()->view('product-registration.show', [
            'policy' => $policy,
            'identity' => $this->me->check() ? ['email' => $this->me->email(), 'verified' => $this->me->emailVerified(), 'organization' => $this->me->organizationId()] : null,
            'teams' => $this->me->check() ? $this->teams->choices($this->me->id()) : [],
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function identity(Request $request): RedirectResponse
    {
        $request->session()->put(IntendedUrl::KEY, route('product-signup.show'));

        return to_route('signup');
    }

    public function signin(Request $request): RedirectResponse
    {
        $request->session()->put(IntendedUrl::KEY, route('product-signup.show'));

        return to_route('login');
    }

    public function switch(Request $request): RedirectResponse
    {
        $request->session()->put(IntendedUrl::KEY, route('product-signup.show'));

        return to_route('accounts');
    }

    public function team(Request $request, Organizations $organizations, Memberships $members, PlatformAuth $auth): RedirectResponse
    {
        abort_unless($this->me->check(), 403);
        $request->validate(['organization' => ['nullable', 'string', 'max:64'], 'name' => ['required_without:organization', 'nullable', 'string', 'max:120']]);
        if ($request->filled('organization')) {
            $id = $request->string('organization')->toString();
            try {
                $this->teams->assertMember($this->me->id(), $id);
            } catch (ActionRefused) {
                abort(404);
            }
        } else {
            // A team is identity data. Creating it does not create a project or an IdP.
            $id = DB::transaction(function () use ($request, $organizations, $members): string {
                $name = trim($request->string('name')->toString());
                $organization = $organizations->create(new NewOrganization($name, (Str::slug($name) ?: 'team').'-'.Str::lower(Str::random(8))));
                $members->add($organization->id, $this->me->id(), MembershipRole::Owner);

                return $organization->id;
            });
        }
        $auth->switchOrganization($request, $id);

        return to_route('product-signup.show', ['invite' => $request->session()->has('product-signup.invitation') ? 1 : 0]);
    }

    public function join(Request $request): RedirectResponse
    {
        try {
            $policy = $this->policy();
            abort_unless($policy->mode === 'waitlist', 403);
            if ($policy->loginRequired && ! $this->me->check()) {
                return $this->identity($request);
            }
            if ($this->me->check() && (! $this->me->emailVerified() || $this->me->email() === null)) {
                throw ValidationException::withMessages(['identity' => __('registration.verify_email')]);
            }
            $request->validate(['email' => [$this->me->check() ? 'nullable' : 'required', 'email:rfc', 'max:190'], 'country' => ['nullable', 'string', 'size:2'], 'answers' => ['sometimes', 'array', 'max:20'], 'answers.*' => ['nullable', Rule::anyOf([['string', 'max:2000'], ['array', 'list', 'max:50']])], 'answers.*.*' => ['string', 'max:200']]);
            $answers = [];
            foreach ($request->array('answers') as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $answers[$key] = $value;
                } elseif (is_string($key) && is_array($value)) {
                    $choices = [];
                    foreach ($value as $choice) {
                        if (is_string($choice)) {
                            $choices[] = $choice;
                        }
                    }
                    $answers[$key] = $choices;
                }
            }
            $email = $this->me->check() ? ($this->me->email() ?? '') : $request->string('email')->toString();
            $country = $request->filled('country') ? $request->string('country')->toString() : null;
            $attempt = 'product-signup.join.'.hash('sha256', json_encode([$this->me->id(), $email, $country, $answers], JSON_THROW_ON_ERROR));
            $reference = $request->session()->get($attempt);
            if (! is_string($reference)) {
                $reference = (string) Str::uuid();
                $request->session()->put($attempt, $reference);
            }
            if ($this->me->check()) {
                $this->runAction(JoinServiceWaitlist::class, ['program' => $policy->id, 'country' => $country, 'answers' => $answers, 'reference' => $reference]);
            } else {
                $this->billing->joinProductGuest($email, $country, $answers, $reference);
            }

            return to_route('product-signup.show')->with('service-registration.joined', $this->me->check() ? 'person' : 'guest');
        } catch (ActionRefused $error) {
            return to_route('product-signup.show')->withErrors(['registration' => $error->getMessage()]);
        } catch (ValidationException $error) {
            return to_route('product-signup.show')->withErrors($error->errors())->withInput($request->except(['code', 'invitation']));
        } catch (ServiceAccessUnavailable) {
            return to_route('product-signup.show')->withErrors(['registration' => __('registration.unavailable')]);
        }
    }

    public function admit(Request $request): RedirectResponse
    {
        try {
            $request->validate(['code' => ['nullable', 'string', 'max:100']]);
            if ($request->filled('code')) {
                $request->session()->put('product-signup.invitation', Crypt::encryptString(trim($request->string('code')->toString())));
            }
            if (! $this->me->check()) {
                return $this->signin($request);
            }
            if (! $this->me->emailVerified() || $this->me->email() === null) {
                throw ValidationException::withMessages(['identity' => __('registration.verify_email')]);
            }
            $organization = $this->me->organization();
            if ($organization === null) {
                throw ValidationException::withMessages(['organization' => __('registration.choose_team')]);
            }
            try {
                $this->teams->assertMember($this->me->id(), $organization->id);
            } catch (ActionRefused) {
                abort(404);
            }
            $stored = $request->session()->get('product-signup.invitation');
            $code = is_string($stored) ? Crypt::decryptString($stored) : null;
            if ($this->admission->enabled()) {
                $reference = hash('sha256', implode('|', [$this->me->id(), $organization->id, $code ?? 'open']));
                $this->billing->admitProduct($this->me->id(), $this->me->email(), $organization->id, $organization->name, $code, $reference);
            }
            $request->session()->forget('product-signup.invitation');

            return to_route('dashboard');
        } catch (ValidationException $error) {
            return to_route('product-signup.show', ['invite' => 1])->withErrors($error->errors());
        } catch (ServiceAccessUnavailable) {
            return to_route('product-signup.show')->withErrors(['registration' => __('registration.unavailable')]);
        }
    }

    private function policy(): RegistrationPolicy
    {
        return $this->admission->enabled() ? $this->billing->productPolicy() : new RegistrationPolicy(0, 'open', false, 'hidden', [], []);
    }
}
