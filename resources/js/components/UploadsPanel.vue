<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import Icon from './Icon.vue';

const api = inject('api');
const config = inject('config');

const files = ref([]);
const loading = ref(true);
const error = ref(null);
const uploadError = ref(null);
const uploading = ref(false);
const fileInput = ref(null);

const uploadConfig = computed(() => config.uploads || { max_files: 5, extensions: [] });
const acceptAttr = computed(() => (uploadConfig.value.extensions || []).map((ext) => `.${ext}`).join(','));

async function load() {
    loading.value = true;
    try {
        const data = await api.listFiles();
        files.value = data.files || [];
    } catch (e) {
        error.value = e.message;
    }
    loading.value = false;
}

function pick() {
    if (!uploading.value) fileInput.value?.click();
}

async function onPicked(event) {
    const picked = Array.from(event.target.files || []);
    event.target.value = '';
    if (!picked.length) return;

    uploadError.value = null;
    uploading.value = true;

    const form = new FormData();
    picked.forEach((file) => form.append('files[]', file));

    try {
        const data = await api.uploadLibraryFiles(form);
        files.value = [...(data.files || []), ...files.value];
    } catch (e) {
        uploadError.value = e.message;
    }

    uploading.value = false;
}

async function remove(file) {
    if (!window.confirm(`Delete "${file.name}" from Twill AI storage? If it was already used in the CMS, the media-library copy is kept.`)) {
        return;
    }
    try {
        await api.deleteLibraryFile(file.id);
        files.value = files.value.filter((item) => item.id !== file.id);
    } catch (e) {
        window.alert(e.message);
    }
}

function sizeLabel(bytes) {
    if (!bytes) return '';
    const kb = bytes / 1024;
    return kb >= 1024 ? `${(kb / 1024).toFixed(1)} MB` : `${Math.round(kb)} KB`;
}

onMounted(load);
</script>

<template>
    <section class="tai-uploads">
        <header class="tai-uploads__head">
            <div>
                <h2 class="tai-uploads__title">Uploads</h2>
                <p class="tai-uploads__sub">Shared Twill AI storage. Attach these in chat with the “+” → “Use files”.</p>
            </div>
            <button type="button" class="tai-button" :disabled="uploading" @click="pick">
                <Icon name="upload" :size="16" /> {{ uploading ? 'Uploading…' : 'Upload files' }}
            </button>
            <input ref="fileInput" type="file" multiple class="tai-file-input" :accept="acceptAttr" @change="onPicked">
        </header>

        <p v-if="uploadError" class="tai-error">{{ uploadError }}</p>
        <p v-if="error" class="tai-error">{{ error }}</p>

        <div v-if="loading" class="tai-uploads__empty">Loading…</div>
        <div v-else-if="!files.length" class="tai-uploads__empty">
            No files yet. Upload images, PDFs or documents to reuse them in chat.
        </div>

        <div v-else class="tai-file-grid">
            <div v-for="file in files" :key="file.id" class="tai-file-card">
                <a :href="file.preview_url" target="_blank" rel="noopener" class="tai-file-card__preview">
                    <img v-if="file.is_image" :src="file.preview_url" alt="" class="tai-file-card__thumb">
                    <span v-else class="tai-file-card__icon"><Icon name="document" :size="30" /></span>
                </a>
                <div class="tai-file-card__body">
                    <span class="tai-file-card__name" :title="file.name">{{ file.name }}</span>
                    <span class="tai-file-card__meta">
                        {{ sizeLabel(file.size) }}<template v-if="file.uploaded_by"> · {{ file.uploaded_by }}</template>
                    </span>
                    <span v-if="file.media_id" class="tai-file-card__badge">In media library</span>
                </div>
                <button type="button" class="tai-file-card__remove" title="Delete from storage" @click="remove(file)"><Icon name="trash" :size="13" /></button>
            </div>
        </div>
    </section>
</template>
