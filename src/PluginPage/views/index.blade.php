@extends('twill::layouts.free')

{{--
    The stylesheet is pushed to `extra_css` in <head>, NOT inlined in the
    section below. Twill yields page content inside <div id="app">, which is
    Vue's mount point, and Vue's template compiler discards <style> elements it
    finds while compiling the in-DOM template — so an inline block renders the
    markup unstyled. The head stack is outside Vue's reach.
--}}
@push('extra_css')
    <style>
        .yo-plugins {
            color-scheme: light;
            --yo-plugins-surface: #fff;
            --yo-plugins-border: #e0e0e0;
            --yo-plugins-border-hover: #b9b9b9;
            --yo-plugins-text: #1a1a1a;
            --yo-plugins-muted: #6b6b6b;
            --yo-plugins-faint: #9a9a9a;

            margin: 32px 0 64px;
        }

        .yo-plugins__title {
            margin: 0;
            font-size: 20px;
            font-weight: 600;
            line-height: 1.3;
            color: var(--yo-plugins-text);
        }

        .yo-plugins__intro {
            margin: 6px 0 0;
            font-size: 14px;
            line-height: 1.5;
            color: var(--yo-plugins-muted);
        }

        .yo-plugins__list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
            margin-top: 24px;
            padding: 0;
            list-style: none;
        }

        .yo-plugins__card {
            display: flex;
            flex-direction: column;
            height: 100%;
            padding: 20px;
            background: var(--yo-plugins-surface);
            border: 1px solid var(--yo-plugins-border);
            border-radius: 8px;
            color: inherit;
            text-decoration: none;
            transition: border-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
        }

        a.yo-plugins__card:hover,
        a.yo-plugins__card:focus-visible {
            border-color: var(--yo-plugins-border-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.07);
            text-decoration: none;
        }

        a.yo-plugins__card:focus-visible {
            outline: 2px solid #1652f0;
            outline-offset: 2px;
        }

        .yo-plugins__head {
            display: flex;
            align-items: baseline;
            gap: 8px;
        }

        .yo-plugins__icon {
            flex: none;
            font-size: 15px;
            line-height: 1;
        }

        .yo-plugins__name {
            font-size: 15px;
            font-weight: 600;
            line-height: 1.3;
            color: var(--yo-plugins-text);
        }

        /* Pushed right so the version reads as metadata, not part of the name. */
        .yo-plugins__version {
            margin-left: auto;
            flex: none;
            font-size: 11px;
            font-weight: 500;
            font-variant-numeric: tabular-nums;
            color: var(--yo-plugins-muted);
        }

        .yo-plugins__description {
            margin: 10px 0 0;
            font-size: 13px;
            line-height: 1.55;
            color: var(--yo-plugins-muted);
        }

        /* Sits at the card's foot whatever the description length, so a row of
           cards keeps its package names on one line. */
        .yo-plugins__package {
            margin-top: auto;
            padding-top: 16px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 11px;
            color: var(--yo-plugins-faint);
            overflow-wrap: anywhere;
        }

        .yo-plugins__empty {
            margin-top: 24px;
            padding: 32px 20px;
            border: 1px dashed var(--yo-plugins-border);
            border-radius: 8px;
            text-align: center;
            font-size: 13px;
            color: var(--yo-plugins-muted);
        }

        /* No prefers-color-scheme block on purpose. Twill's admin appearance is
           a CMS setting and Twill emits no class or media signal for it, so
           keying off the operating system would turn these cards dark while the
           admin around them stayed light. Pinning color-scheme also stops the
           browser darkening form controls on a dark OS. */

        @media (prefers-reduced-motion: reduce) {
            .yo-plugins__card {
                transition: none;
            }

            a.yo-plugins__card:hover,
            a.yo-plugins__card:focus-visible {
                transform: none;
            }
        }
    </style>
@endpush

@section('customPageContent')
    <div class="yo-plugins">
        <h1 class="yo-plugins__title">{{ __('Plugins') }}</h1>
        <p class="yo-plugins__intro">{{ __('Installed Twill plugins. Select one to open it.') }}</p>

        @if($plugins->isEmpty())
            <p class="yo-plugins__empty">
                {{ __('No plugins are registered yet. Installing a Yotech Twill package adds it here automatically.') }}
            </p>
        @else
            <ul class="yo-plugins__list">
                @foreach($plugins as $plugin)
                    @php
                        $href = $plugin['url']
                            ?? (isset($plugin['route']) && \Illuminate\Support\Facades\Route::has($plugin['route'])
                                ? route($plugin['route'])
                                : null);
                    @endphp

                    <li>
                        @if($href)
                            <a class="yo-plugins__card" href="{{ $href }}">
                                @include('twill-plugins::_card', ['plugin' => $plugin])
                            </a>
                        @else
                            <div class="yo-plugins__card">
                                @include('twill-plugins::_card', ['plugin' => $plugin])
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@stop
