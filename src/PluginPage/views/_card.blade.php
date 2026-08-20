<span class="yo-plugins__head">
    @isset($plugin['icon'])
        <span class="yo-plugins__icon" aria-hidden="true">{{ $plugin['icon'] }}</span>
    @endisset

    <span class="yo-plugins__name">{{ $plugin['name'] ?? __('Unknown plugin') }}</span>

    @isset($plugin['version'])
        <span class="yo-plugins__version">{{ $plugin['version'] }}</span>
    @endisset
</span>

@isset($plugin['description'])
    <span class="yo-plugins__description">{{ $plugin['description'] }}</span>
@endisset

@isset($plugin['package'])
    <span class="yo-plugins__package">{{ $plugin['package'] }}</span>
@endisset
