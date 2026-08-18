<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import Icon from './Icon.vue';

const api = inject('api');

const loading = ref(true);
const data = ref(null);

const provider = ref('anthropic');
const keyInput = ref('');
const editingKey = ref(false);
const savingKey = ref(false);
const keyError = ref(null);

const defaultModel = ref('');
const systemPrompt = ref('');
const savingSettings = ref(false);
const settingsError = ref(null);
const settingsSaved = ref(false);
const refreshing = ref(false);

const verified = computed(() => !!data.value?.verified);
const hasKey = computed(() => !!data.value?.has_key);
const providers = computed(() => data.value?.providers || {});
const availableModels = computed(() => data.value?.available_models || []);

function hydrate(payload) {
    data.value = payload;
    provider.value = payload.provider || 'anthropic';
    defaultModel.value = payload.default_model || '';
    systemPrompt.value = payload.system_prompt || '';
}

async function load() {
    loading.value = true;
    try {
        hydrate(await api.getSettings());
    } catch (e) {
        keyError.value = e.message;
    }
    loading.value = false;
}

function startReplace() {
    editingKey.value = true;
    keyInput.value = '';
    keyError.value = null;
}

async function saveKey() {
    if (!keyInput.value.trim()) return;
    savingKey.value = true;
    keyError.value = null;
    try {
        hydrate(await api.saveApiKey(provider.value, keyInput.value.trim()));
        editingKey.value = false;
        keyInput.value = '';
    } catch (e) {
        keyError.value = e.message;
    }
    savingKey.value = false;
}

async function saveSettings() {
    savingSettings.value = true;
    settingsError.value = null;
    settingsSaved.value = false;
    try {
        hydrate(await api.saveSettings({ default_model: defaultModel.value, system_prompt: systemPrompt.value }));
        settingsSaved.value = true;
    } catch (e) {
        settingsError.value = e.message;
    }
    savingSettings.value = false;
}

async function refresh() {
    refreshing.value = true;
    settingsError.value = null;
    try {
        hydrate(await api.refreshModels());
    } catch (e) {
        settingsError.value = e.message;
    }
    refreshing.value = false;
}

const fetchedLabel = computed(() => {
    if (!data.value?.models_fetched_at) return '';
    return new Date(data.value.models_fetched_at).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
});

onMounted(load);
</script>

<template>
    <section class="tai-settings">
        <header class="tai-settings__head">
            <h2 class="tai-settings__title">Settings</h2>
            <p class="tai-settings__sub">Configure the AI provider and assistant for this CMS.</p>
        </header>

        <div v-if="loading" class="tai-settings__empty">Loading…</div>

        <template v-else>
            <div class="tai-settings__group">
                <label class="tai-settings__label">Provider</label>
                <select v-model="provider" class="tai-settings__select" :disabled="hasKey && !editingKey">
                    <option v-for="(label, key) in providers" :key="key" :value="key">{{ label }}</option>
                </select>

                <label class="tai-settings__label" style="margin-top: 14px">API key</label>
                <div v-if="hasKey && !editingKey" class="tai-settings__keyrow">
                    <code class="tai-settings__masked">{{ data.key_masked }}</code>
                    <span v-if="verified" class="tai-settings__badge tai-settings__badge--ok">
                        <Icon name="check" :size="13" /> Verified
                    </span>
                    <button type="button" class="tai-icon-button" @click="startReplace">Replace</button>
                </div>
                <div v-else class="tai-settings__keyrow">
                    <input
                        v-model="keyInput"
                        type="password"
                        class="tai-settings__input"
                        placeholder="Paste the provider API key"
                        autocomplete="off"
                        @keydown.enter="saveKey"
                    >
                    <button type="button" class="tai-button" :disabled="savingKey || !keyInput.trim()" @click="saveKey">
                        {{ savingKey ? 'Verifying…' : 'Save & verify' }}
                    </button>
                    <button v-if="hasKey" type="button" class="tai-icon-button" @click="editingKey = false">Cancel</button>
                </div>

                <p v-if="keyError" class="tai-settings__error"><Icon name="warning" :size="13" /> {{ keyError }}</p>
                <p class="tai-settings__hint">Stored encrypted and validated against the provider on save. Only the last 4 characters are ever shown.</p>
            </div>

            <template v-if="verified">
                <div class="tai-settings__group">
                    <label class="tai-settings__label">Default model</label>
                    <select v-model="defaultModel" class="tai-settings__select">
                        <option v-for="model in availableModels" :key="model.id" :value="model.id">{{ model.label }}</option>
                    </select>
                    <p class="tai-settings__hint">
                        New chats start with this model. Loaded from {{ providers[data.provider] || data.provider }}<template v-if="fetchedLabel"> · {{ fetchedLabel }}</template>.
                        <button type="button" class="tai-settings__link" :disabled="refreshing" @click="refresh">
                            {{ refreshing ? 'Refreshing…' : 'Refresh models' }}
                        </button>
                    </p>
                </div>

                <div class="tai-settings__group">
                    <label class="tai-settings__label">Extra system prompt</label>
                    <textarea
                        v-model="systemPrompt"
                        class="tai-settings__textarea"
                        rows="6"
                        placeholder="Optional. Added after the assistant's built-in instructions — e.g. tone, house style, project specifics."
                    ></textarea>
                    <p class="tai-settings__hint">Appended to the assistant's instructions; it does not replace them.</p>
                </div>

                <div class="tai-settings__actions">
                    <button type="button" class="tai-button" :disabled="savingSettings" @click="saveSettings">
                        {{ savingSettings ? 'Saving…' : 'Save settings' }}
                    </button>
                    <span v-if="settingsSaved" class="tai-settings__badge tai-settings__badge--ok">
                        <Icon name="check" :size="13" /> Saved
                    </span>
                    <span v-if="settingsError" class="tai-settings__error"><Icon name="warning" :size="13" /> {{ settingsError }}</span>
                </div>
            </template>
        </template>
    </section>
</template>
