@extends('twill::layouts.free')

@section('customPageContent')
    <style>
        .plugins-page__header {
            margin: 40px 0 20px;
        }
        .plugins-page__header h2 {
            font-size: 20px;
            font-weight: 600;
        }
        .plugins-page__header p {
            margin-top: 5px;
            opacity: 0.6;
        }
        .plugins-page__list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin: 20px 0 60px;
        }
        .plugins-page__card {
            display: block;
            padding: 20px;
            border: 1px solid rgba(115, 115, 115, 0.3);
            border-radius: 4px;
            text-decoration: none;
            color: inherit;
        }
        a.plugins-page__card:hover {
            border-color: rgba(115, 115, 115, 0.7);
        }
        .plugins-page__card-title {
            display: flex;
            align-items: baseline;
            gap: 8px;
            font-size: 16px;
            font-weight: 600;
        }
        .plugins-page__card-version {
            font-size: 12px;
            font-weight: 400;
            opacity: 0.6;
        }
        .plugins-page__card-description {
            margin-top: 8px;
            line-height: 1.4;
        }
        .plugins-page__card-package {
            display: block;
            margin-top: 12px;
            font-size: 12px;
            opacity: 0.5;
        }
    </style>

    <div class="plugins-page__header">
        <h2>{{ __('Plugins') }}</h2>
        <p>{{ __('Installed Twill plugins. Click a plugin to open it.') }}</p>
    </div>

    <div class="plugins-page__list">
        @forelse($plugins as $plugin)
            @php
                $href = $plugin['url']
                    ?? (isset($plugin['route']) && \Illuminate\Support\Facades\Route::has($plugin['route'])
                        ? route($plugin['route'])
                        : null);
            @endphp

            @if($href)
                <a class="plugins-page__card" href="{{ $href }}">
                    @include('twill-plugins::_card', ['plugin' => $plugin])
                </a>
            @else
                <div class="plugins-page__card">
                    @include('twill-plugins::_card', ['plugin' => $plugin])
                </div>
            @endif
        @empty
            <p>{{ __('No plugins registered.') }}</p>
        @endforelse
    </div>
@stop
