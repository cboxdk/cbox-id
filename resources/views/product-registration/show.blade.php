@extends('product-registration.layout')
@section('content')
@if($policy === null)
    <h2 id="registration-heading">{{ __('registration.unavailable_title') }}</h2>
    <p>{{ __('registration.unavailable') }}</p>
    <a class="registration-button" href="{{ route('product-signup.show') }}">{{ __('registration.retry') }}</a>
@else
    <p class="registration-eyebrow">{{ __('registration.'.$policy->mode.'_eyebrow') }}</p>
    <h2 id="registration-heading">{{ __('registration.'.$policy->mode.'_title') }}</h2>
    <p>{{ __('registration.'.$policy->mode.'_description', ['service' => config('service-access.name')]) }}</p>
    @if($identity !== null)
        <div class="registration-account"><span>{{ $identity['email'] }}</span><a href="{{ route('product-signup.switch') }}">{{ __('registration.change_account') }}</a></div>
    @endif
    @if($errors->any())
        <div class="registration-errors" role="alert" tabindex="-1"><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif
    @if(session('service-registration.joined'))
        <div class="registration-status" role="status"><strong>{{ __('registration.joined_title') }}</strong><p>{{ __('registration.'.(session('service-registration.joined') === 'guest' ? 'check_email' : 'joined')) }}</p></div>
    @elseif($policy->mode === 'waitlist')
        @if($policy->loginRequired && $identity === null)
            <p>{{ __('registration.login_required') }}</p>
            <a class="registration-button" href="{{ route('product-signup.identity') }}">{{ __('registration.join') }}</a>
        @elseif($identity !== null && ! $identity['verified'])
            <p>{{ __('registration.verify_email') }}</p>
            <a class="registration-button" href="{{ route('product-signup.signin') }}">{{ __('registration.retry_signin') }}</a>
        @else
            <form method="POST" action="{{ route('product-signup.join') }}" class="registration-form">
                @csrf
                @if($identity === null)
                    <label for="waitlist-email">{{ __('registration.email') }} <span aria-hidden="true">*</span></label>
                    <input id="waitlist-email" type="email" name="email" autocomplete="email" maxlength="190" value="{{ is_string(old('email')) ? old('email') : '' }}" required>
                @endif
                @if($policy->countryCollection !== 'hidden')
                    <label for="waitlist-country">{{ __('registration.country') }} {{ $policy->countryCollection === 'required' ? '*' : __('registration.optional') }}</label>
                    <select id="waitlist-country" name="country" data-combobox="search" autocomplete="country" @required($policy->countryCollection === 'required')>
                        <option value="">{{ __('registration.choose_country') }}</option>
                        @foreach($policy->countries as $code => $label)<option value="{{ $code }}" @selected(old('country') === $code)>{{ $label }}</option>@endforeach
                    </select>
                @endif
                @foreach($policy->questions as $question)
                    @include('product-registration.question')
                @endforeach
                <button class="registration-button" type="submit">{{ __('registration.join') }}</button>
                <p class="registration-small">{{ __('registration.waitlist_note') }}</p>
            </form>
        @endif
    @elseif($policy->mode === 'open')
        @if($identity === null)
            <a class="registration-button" href="{{ route('product-signup.identity') }}">{{ __('registration.create_account') }}</a>
        @elseif($identity['organization'] !== null && $identity['verified'])
            <form method="POST" action="{{ route('product-signup.admit') }}">@csrf<button class="registration-button" type="submit">{{ __('registration.continue') }}</button></form>
        @endif
    @endif
    @if($identity !== null && $policy->mode !== 'paused')
        <details class="registration-invitation" @if($identity['organization'] === null) open @endif>
            <summary>{{ __('registration.select_team') }}</summary>
            <form method="POST" action="{{ route('product-signup.team') }}" class="registration-form">
                @csrf
                <label for="product-team">{{ __('registration.select_team') }}</label>
                <select id="product-team" name="organization" data-combobox="search"><option value="">{{ __('registration.choose') }}</option>@foreach($teams as $team)<option value="{{ $team['id'] }}" @selected($identity['organization'] === $team['id'])>{{ $team['name'] }}</option>@endforeach</select>
                <button class="registration-button registration-secondary" type="submit">{{ __('registration.continue') }}</button>
            </form>
            <form method="POST" action="{{ route('product-signup.team') }}" class="registration-form">
                @csrf
                <label for="new-team-name">{{ __('registration.new_team_name') }}</label>
                <input id="new-team-name" name="name" type="text" required maxlength="120" autocomplete="organization">
                <button class="registration-button registration-secondary" type="submit">{{ __('registration.continue') }}</button>
            </form>
        </details>
    @endif
    @if($policy->mode !== 'paused')
        <details class="registration-invitation" @if(request()->boolean('invite') || $errors->has('code')) open @endif>
            <summary>{{ __('registration.have_invitation') }}</summary>
            <form method="POST" action="{{ route('product-signup.admit') }}" class="registration-form">
                @csrf
                <label for="invitation-code">{{ __('registration.invitation_code') }}</label>
                <input id="invitation-code" name="code" type="text" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="100" aria-describedby="invitation-note">
                <p id="invitation-note" class="registration-small">{{ __('registration.invitation_note') }}</p>
                <button type="submit" class="registration-button registration-secondary">{{ __('registration.use_invitation') }}</button>
            </form>
        </details>
    @endif
@endif
@endsection
