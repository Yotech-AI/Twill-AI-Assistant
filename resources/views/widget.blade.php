{{--
    Floating Twill AI chat widget.

    Pushed onto @stack('extra_js') of twill::layouts.main by a view composer in
    TwillAiServiceProvider, so it appears on every Twill admin page without the
    host application overriding any vendor view.
--}}
@php
    $twillAiWidgetConfig = TwillAi\Http\Controllers\TwillAiPageController::clientConfig('widget');
@endphp

<div id="twill-ai-widget" data-twill-ai-config='@json($twillAiWidgetConfig)'></div>

<link rel="stylesheet" href="{{ TwillAi\Http\Controllers\AssetController::url('twill-ai.css') }}">
<script src="{{ TwillAi\Http\Controllers\AssetController::url('twill-ai.iife.js') }}" defer></script>
