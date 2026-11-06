@extends('product-registration.layout')
@section('content')
<p class="registration-eyebrow">{{ config('service-access.name') }}</p>
<h2 id="registration-heading">{{ __('registration.sign_in') }}</h2>
@if(session('error'))<p role="alert">{{ session('error') }}</p>@endif
<p>{{ __('registration.signin_description') }}</p>
<a class="registration-button" href="{{ route('product-signup.signin') }}">{{ __('registration.sign_in') }}</a>
<a class="registration-other" href="{{ route('product-signup.switch') }}">{{ __('registration.change_account') }}</a>
@endsection
