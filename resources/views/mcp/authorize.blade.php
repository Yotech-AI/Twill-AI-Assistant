{{--
    MCP connector approval screen.

    Deliberately self-contained: no @vite, no dependency on the application's
    Tailwind theme. This is a security decision screen, and it must render
    correctly even when the frontend has not been built — a missing
    public/build/manifest.json would otherwise make it impossible to approve a
    connector at all. It also uses none of the shadcn-style tokens the
    published Laravel MCP view assumed, which this project does not define.

    The forms below are Passport's contract: route, method, csrf, state,
    client_id and auth_token must all be preserved exactly.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Authorize {{ $appName ?? $client->name }} — {{ config('app.name', 'MCP Server') }}</title>

    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="shortcut icon" href="/favicon.ico">
    <meta name="robots" content="noindex, nofollow">

    <script>
        (function () {
            const appearance = '{{ $appearance ?? "system" }}';

            if (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
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
                      d="M20.618 5.984A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.031 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
            </svg>
        </div>

        <h1>Authorize {{ $appName ?? $client->name }}</h1>

        @if ($nameFromApp ?? false)
            {{-- The app chose this name when it registered itself; nobody on
                 this site checked it. --}}
            <p class="given">Name given by the app</p>
        @endif

        <p class="lede">
            This app is asking for access to the CMS.
        </p>

        @isset($appHost)
            <div class="panel">
                <p class="panel-label">Its access goes to</p>
                <p class="panel-value">{{ $appHost }}</p>
            </div>
        @endisset

        <div class="panel">
            <p class="panel-label">What it may do</p>
            <ul class="scopes">
                <li><span class="dot"></span><span>Read content</span></li>
                <li><span class="dot"></span><span>Create and edit drafts</span></li>
                <li><span class="dot dot--never"></span><span>Never publish or delete anything</span></li>
            </ul>
        </div>

        <div class="panel">
            <p class="panel-label">Approving as</p>
            <p class="panel-value">{{ $user->email }}</p>
        </div>

        @if (count($scopes) > 0)
            <div class="panel">
                <p class="panel-label">Permissions</p>
                <ul class="scopes">
                    @foreach ($scopes as $scope)
                        <li><span class="dot"></span><span>{{ $scope->description }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p class="notice">
            Only approve an app you are connecting yourself, right now. If you did not
            just add this connector in your AI app, choose Deny.
        </p>
    </div>

    <div class="actions">
        <form method="POST" action="{{ $denyUrl ?? route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="btn-deny">Deny</button>
        </form>

        <form method="POST" action="{{ $approveUrl ?? route('passport.authorizations.approve') }}" id="authorizeForm">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="btn-approve" id="authorizeButton">
                <svg id="loadingSpinner" class="spinner hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity=".25"></circle>
                    <path fill="currentColor" opacity=".75" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span id="authorizeText">Approve</span>
            </button>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('authorizeForm');
        const button = document.getElementById('authorizeButton');
        const authorizeText = document.getElementById('authorizeText');
        const loadingSpinner = document.getElementById('loadingSpinner');

        form.addEventListener('submit', function () {
            button.disabled = true;
            authorizeText.textContent = 'Approving…';
            loadingSpinner.classList.remove('hidden');
        });
    });
</script>
</body>
</html>
