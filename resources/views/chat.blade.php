@extends('twill::layouts.free')

@section('appTypeClass', 'body--twill-ai')

@section('customPageContent')
    <div id="twill-ai-page"
         class="tai-page-mount"
         data-twill-ai-config='@json($bootstrap)'></div>
@stop

@push('extra_css')
    <link rel="stylesheet" href="{{ TwillAi\Http\Controllers\AssetController::url('twill-ai.css') }}">
    <style>
        /* Let the chat use the full admin content area. */
        .body--twill-ai .custom-page { padding-top: 0; }
        .body--twill-ai .custom-page .container { max-width: none; width: 100%; padding: 0; }
    </style>
@endpush

@push('extra_js')
    <script src="{{ TwillAi\Http\Controllers\AssetController::url('twill-ai.iife.js') }}" defer></script>
@endpush
