<script setup>
import { inject, onMounted, ref } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    attachedIds: { type: Array, default: () => [] },
});

defineEmits(['select', 'close']);

const api = inject('api');

const files = ref([]);
const loading = ref(true);
const error = ref(null);

onMounted(async () => {
    try {
        const data = await api.listFiles();
        files.value = data.files || [];
    } catch (e) {
        error.value = e.message;
    }
    loading.value = false;
});

function isAttached(file) {
    return props.attachedIds.includes(file.id);
}
</script>

<template>
    <div class="tai-filepicker">
        <header class="tai-filepicker__head">
            <strong>Use files</strong>
            <button type="button" class="tai-icon-button" title="Close" @click="$emit('close')"><Icon name="close" :size="14" /></button>
        </header>

        <p v-if="error" class="tai-error">{{ error }}</p>
        <div v-if="loading" class="tai-filepicker__empty">Loading…</div>
        <div v-else-if="!files.length" class="tai-filepicker__empty">No files in storage yet.</div>

        <ul v-else class="tai-filepicker__list">
            <li
                v-for="file in files"
                :key="file.id"
                class="tai-filepicker__item"
                :class="{ 'tai-filepicker__item--on': isAttached(file) }"
                @click="$emit('select', file)"
            >
                <img v-if="file.is_image" :src="file.preview_url" alt="" class="tai-filepicker__thumb">
                <span v-else class="tai-filepicker__icon"><Icon name="document" :size="16" /></span>
                <span class="tai-filepicker__name" :title="file.name">{{ file.name }}</span>
                <span v-if="isAttached(file)" class="tai-filepicker__check"><Icon name="check" :size="14" /></span>
            </li>
        </ul>
    </div>
</template>
