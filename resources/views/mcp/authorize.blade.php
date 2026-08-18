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

    <title>Authorize {{ $client->name }} — {{ config('app.name', 'MCP Server') }}</title>

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

    <style>
        :root {
            --bg: #f4f4f5;
            --card: #ffffff;
            --text: #111827;
            --muted: #6b7280;
            --border: #e4e4e7;
            --panel: #f9fafb;
            --accent: #00a859;
            --accent-text: #ffffff;
            --warn: #7a5b00;
            --warn-bg: #fff8e1;
            --warn-border: #ffc400;
        }

        html.dark {
            --bg: #09090b;
            --card: #18181b;
            --text: #f4f4f5;
            --muted: #a1a1aa;
            --border: #2f2f35;
            --panel: #202024;
            --accent: #00a859;
            --accent-text: #ffffff;
            --warn: #ffd75e;
            --warn-bg: #2a2410;
            --warn-border: #7a5b00;
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            background: var(--bg);
            color: var(--text);
        }

        body {
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .card {
            width: 100%;
            max-width: 27rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 0.75rem;
            box-shadow: 0 1px 3px rgba(0, 0, 0, .08), 0 8px 24px rgba(0, 0, 0, .06);
            overflow: hidden;
        }

        .card-body { padding: 1.5rem; }

        .icon-wrap {
            display: flex;
            justify-content: center;
            margin-bottom: 1rem;
        }

        .icon-wrap svg {
            width: 2.75rem;
            height: 2.75rem;
            color: var(--accent);
            fill: none;
        }

        h1 {
            margin: 0 0 .5rem;
            font-size: 1.4rem;
            font-weight: 600;
            text-align: center;
            line-height: 1.3;
        }

        .lede {
            margin: 0;
            text-align: center;
            font-size: .875rem;
            color: var(--muted);
            line-height: 1.5;
        }

        .panel {
            margin-top: 1.25rem;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: .5rem;
            padding: .875rem 1rem;
        }

        .panel-label {
            margin: 0 0 .25rem;
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--muted);
        }

        .panel-value {
            margin: 0;
            font-size: .9rem;
            font-weight: 500;
            word-break: break-all;
        }

        .scopes {
            list-style: none;
            margin: .5rem 0 0;
            padding: 0;
        }

        .scopes li {
            display: flex;
            align-items: flex-start;
            gap: .5rem;
            font-size: .875rem;
            color: var(--muted);
            padding: .2rem 0;
        }

        .dot {
            flex: 0 0 auto;
            width: .4rem;
            height: .4rem;
            margin-top: .45rem;
            border-radius: 999px;
            background: var(--accent);
        }

        .notice {
            margin-top: 1.25rem;
            background: var(--warn-bg);
            border: 1px solid var(--warn-border);
            border-radius: .5rem;
            padding: .75rem .875rem;
            font-size: .8125rem;
            line-height: 1.45;
            color: var(--warn);
        }

        .actions {
            display: flex;
            gap: .75rem;
            padding: 0 1.5rem 1.5rem;
        }

        .actions form { flex: 1 1 0; margin: 0; }

        button {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            height: 2.6rem;
            padding: 0 1rem;
            border-radius: .5rem;
            font-family: inherit;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .15s ease, background-color .15s ease;
        }

        button:focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
        }

        .btn-approve {
            background: var(--accent);
            color: var(--accent-text);
            border: 1px solid var(--accent);
        }

        .btn-approve:hover { opacity: .9; }
        .btn-approve[disabled] { opacity: .6; cursor: default; }

        .btn-deny {
            background: transparent;
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-deny:hover { background: var(--panel); }

        .spinner {
            width: 1rem;
            height: 1rem;
            animation: spin 1s linear infinite;
        }

        .hidden { display: none; }

        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
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

        <h1>Authorize {{ $client->name }}</h1>

        <p class="lede">
            This connector is asking to read and draft content in the CMS.
            It cannot publish or delete anything.
        </p>

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
            Only approve a connector you were expecting. If you do not recognise the name
            above, choose Cancel and tell a developer.
        </p>
    </div>

    <div class="actions">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="btn-deny">Cancel</button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.approve') }}" id="authorizeForm">
            @csrf
            <input type="hidden" name="state" value="">
            <input type="hidden" name="client_id" value="{{ $client->id }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="btn-approve" id="authorizeButton">
                <svg id="loadingSpinner" class="spinner hidden" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity=".25"></circle>
                    <path fill="currentColor" opacity=".75" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                <span id="authorizeText">Authorize</span>
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
            authorizeText.textContent = 'Authorizing…';
            loadingSpinner.classList.remove('hidden');
        });
    });
</script>
</body>
</html>
