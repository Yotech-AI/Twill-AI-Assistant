<span class="plugins-page__card-title">
    @isset($plugin['icon'])<span>{{ $plugin['icon'] }}</span>@endisset
    <span>{{ $plugin['name'] ?? 'Unknown plugin' }}</span>
    @isset($plugin['version'])
        <span class="plugins-page__card-version">{{ $plugin['version'] }}</span>
    @endisset
</span>

@isset($plugin['description'])
    <span class="plugins-page__card-description">{{ $plugin['description'] }}</span>
@endisset

@isset($plugin['package'])
    <span class="plugins-page__card-package">{{ $plugin['package'] }}</span>
@endisset
