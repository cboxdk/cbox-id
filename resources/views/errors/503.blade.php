@extends('errors.layout')
@section('code', '503')
@section('title', __('errors.503.title'))
@section('message', __('errors.503.message', ['brand' => config('cbox-id.branding.name', 'Cbox ID')]))
