@extends('twill::layouts.free')

{{--
    The Connectors page. The stylesheet goes to `extra_css` in <head> rather
    than inline: Twill yields page content inside Vue's mount point, and Vue
    drops <style> elements it finds there (see the Addons page).
--}}
@push('extra_css')
    <style>
        .yo-connectors {
            color-scheme: light;
            --yo-c-surface: #fff;
            --yo-c-border: #e0e0e0;
            --yo-c-text: #1a1a1a;
            --yo-c-muted: #6b6b6b;
            --yo-c-ok-bg: #edf7ef;
            --yo-c-ok-border: #9fd3ab;
            --yo-c-warn-bg: #fff8e1;
            --yo-c-warn-border: #ffc400;
            --yo-c-error: #b3261e;

            margin: 32px 0 64px;
            color: var(--yo-c-text);
        }

        .yo-connectors h1 { margin: 0; font-size: 20px; font-weight: 600; line-height: 1.3; }
        .yo-connectors h2 { margin: 40px 0 0; font-size: 16px; font-weight: 600; }
        .yo-connectors p { font-size: 14px; line-height: 1.5; }
        .yo-connectors code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; overflow-wrap: anywhere; }

        .yo-connectors__intro { margin: 6px 0 0; color: var(--yo-c-muted); }
        .yo-connectors__muted { color: var(--yo-c-muted); font-size: 12px; }

        .yo-connectors__notice {
            margin: 20px 0 0;
            padding: 14px 16px;
            border: 1px solid var(--yo-c-ok-border);
            border-radius: 8px;
            background: var(--yo-c-ok-bg);
        }

        .yo-connectors__notice--secret { border-color: var(--yo-c-warn-border); background: var(--yo-c-warn-bg); }
        .yo-connectors__notice p { margin: 0; }
        .yo-connectors__notice dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 12px 0; font-size: 13px; }
        .yo-connectors__notice dt { color: var(--yo-c-muted); }
        .yo-connectors__notice dd { margin: 0; }

        .yo-connectors__table-wrap { margin-top: 24px; overflow-x: auto; }

        .yo-connectors__table {
            width: 100%;
            border-collapse: collapse;
            background: var(--yo-c-surface);
            border: 1px solid var(--yo-c-border);
            border-radius: 8px;
            font-size: 13px;
        }

        .yo-connectors__table th,
        .yo-connectors__table td { padding: 10px 14px; text-align: left; vertical-align: top; border-bottom: 1px solid var(--yo-c-border); }
        .yo-connectors__table th { font-weight: 600; color: var(--yo-c-muted); font-size: 12px; }
        .yo-connectors__table td.yo-connectors__number { font-variant-numeric: tabular-nums; }
        .yo-connectors__table form { margin: 0; }

        .yo-connectors__empty {
            margin-top: 24px;
            padding: 24px 20px;
            border: 1px dashed var(--yo-c-border);
            border-radius: 8px;
            color: var(--yo-c-muted);
        }

        .yo-connectors__form { display: grid; gap: 14px; max-width: 560px; margin-top: 16px; }
        .yo-connectors__form label { display: grid; gap: 6px; font-size: 13px; font-weight: 600; }
        .yo-connectors__form input,
        .yo-connectors__form textarea { font: inherit; font-weight: 400; padding: 8px 10px; border: 1px solid var(--yo-c-border); border-radius: 6px; }
        .yo-connectors__error { margin: 0; color: var(--yo-c-error); font-size: 13px; font-weight: 400; }

        .yo-connectors__button {
            justify-self: start;
            padding: 7px 14px;
            border: 1px solid var(--yo-c-border);
            border-radius: 6px;
            background: var(--yo-c-surface);
            font: inherit;
            font-size: 13px;
            cursor: pointer;
        }

        .yo-connectors__button:hover { border-color: #b9b9b9; }
    </style>
@endpush

@section('customPageContent')
    <div class="yo-connectors">
        <h1>Connectors</h1>
        <p class="yo-connectors__intro">
            AI apps that may read and draft content in this CMS. None of them can publish or delete anything.
            To connect an app, add a connector in the app with <code>{{ $endpoint }}</code> and approve it here when the app asks.
        </p>

        @if ($status)
            <div class="yo-connectors__notice" role="status"><p>{{ $status }}</p></div>
        @endif

        @if ($created)
            <div class="yo-connectors__notice yo-connectors__notice--secret" role="status">
                <p><strong>{{ $created['name'] }}</strong> is added. Enter these in the app. The secret is shown once, so copy it now.</p>
                <dl>
                    <dt>URL</dt>
                    <dd><code>{{ $endpoint }}</code></dd>
                    <dt>Client ID</dt>
                    <dd><code data-client-id>{{ $created['client_id'] }}</code></dd>
                    <dt>Client secret</dt>
                    <dd><code data-client-secret>{{ $created['client_secret'] }}</code></dd>
                </dl>
                <p>The first time the app connects, you approve it like any other.</p>
            </div>
        @endif

        @if ($connectors->isEmpty())
            <p class="yo-connectors__empty">No connectors yet.</p>
        @else
            <div class="yo-connectors__table-wrap">
                <table class="yo-connectors__table">
                    <thead>
                        <tr>
                            <th scope="col">Name</th>
                            <th scope="col">Access goes to</th>
                            <th scope="col">Approved</th>
                            <th scope="col">Last used</th>
                            <th scope="col">Live tokens</th>
                            <th scope="col"><span class="sr-only">Revoke</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($connectors as $connector)
                            <tr>
                                <td>{{ $connector->name }}</td>
                                <td>{{ implode(', ', $hosts[$connector->id]) ?: '—' }}</td>
                                <td>
                                    @if ($connector->approved_by === null)
                                        artisan
                                    @else
                                        {{ $connector->approver?->name ?: ($connector->approver?->email ?? 'user #'.$connector->approved_by) }}
                                        @if ($connector->approved_at)
                                            <br><span class="yo-connectors__muted">{{ $connector->approved_at->diffForHumans() }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td>{{ $connector->last_used_at?->diffForHumans() ?? 'never' }}</td>
                                <td class="yo-connectors__number">{{ $connector->liveTokenCount() }}</td>
                                <td>
                                    <form method="POST"
                                          action="{{ route($routePrefix.'revoke', $connector) }}"
                                          onsubmit="return confirm('Revoke this connector? The app is cut off at once, and can only connect again through a new approval.');">
                                        @csrf
                                        <button type="submit" class="yo-connectors__button">Revoke</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <h2>Add an app by hand</h2>

        @if ($selfService)
            <p class="yo-connectors__intro">
                For an app that cannot register itself: it asks for a client ID and secret instead of finding them on its own.
            </p>

            <form class="yo-connectors__form" method="POST" action="{{ route($routePrefix.'store') }}">
                @csrf

                <label>
                    Name
                    <input type="text" name="name" value="{{ old('name') }}" maxlength="255" required>
                    @error('name')
                        <span class="yo-connectors__error">{{ $message }}</span>
                    @enderror
                </label>

                <label>
                    Redirect URIs, one per line
                    <textarea name="redirect_uris" rows="3" required>{{ old('redirect_uris') }}</textarea>
                    @error('redirect_uris')
                        <span class="yo-connectors__error">{{ $message }}</span>
                    @enderror
                </label>

                <button type="submit" class="yo-connectors__button">Add app</button>
            </form>
        @else
            <p class="yo-connectors__intro">
                Self-service is off on this site, so a server admin adds apps with <code>php artisan mcp:client-create</code>.
            </p>
        @endif
    </div>
@stop
