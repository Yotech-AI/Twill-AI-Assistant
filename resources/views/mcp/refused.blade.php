{{--
    The screen for an approval that cannot happen: a user who may not approve,
    or an app the site does not allow. It has no forms, and nothing was stored
    in the session for it, so nothing can be approved from here.

    Self-contained for the same reasons as authorize.blade.php.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? ($appName ? "Cannot connect {$appName}" : 'Cannot connect this app') }} — {{ config('app.name', 'MCP Server') }}</title>

    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">
    <meta name="robots" content="noindex, nofollow">

    <script>
        (function () {
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    @include('twill-ai::mcp._styles')
</head>
<body>
<div class="card">
    <div class="card-body">
        <div class="icon-wrap">
            <svg stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                      d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
            </svg>
        </div>

        <h1>{{ $title ?? ($appName ? "Cannot connect {$appName}" : 'Cannot connect this app') }}</h1>

        @isset($appHost)
            <div class="panel">
                <p class="panel-label">Its access goes to</p>
                <p class="panel-value">{{ $appHost }}</p>
            </div>
        @endisset

        <p class="refusal">{{ $message }}</p>
    </div>
</div>
</body>
</html>
