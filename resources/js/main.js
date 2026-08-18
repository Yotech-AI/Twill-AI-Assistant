import { createApp } from 'vue';
import App from './App.vue';
import './styles.css';

function mount(element) {
    let config;

    try {
        config = JSON.parse(element.dataset.twillAiConfig || '{}');
    } catch {
        console.error('[twill-ai] invalid config payload');
        return;
    }

    createApp(App, { config }).mount(element);
}

function boot() {
    document.querySelectorAll('#twill-ai-page, #twill-ai-widget').forEach(mount);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
