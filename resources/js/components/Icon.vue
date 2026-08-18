<script setup>
import { computed } from 'vue';
import {
    ArrowTopRightOnSquareIcon,
    ArrowUpTrayIcon,
    CheckIcon,
    ClockIcon,
    DocumentIcon,
    ExclamationTriangleIcon,
    FolderIcon,
    MinusIcon,
    PencilSquareIcon,
    PlusIcon,
    SparklesIcon,
    TrashIcon,
    XMarkIcon,
} from '@heroicons/vue/24/outline';

/**
 * Single icon wrapper so every surface uses the same Heroicons set at a
 * consistent stroke. Add a mapping here rather than importing icons ad hoc.
 */
const props = defineProps({
    name: { type: String, required: true },
    size: { type: [Number, String], default: 18 },
});

const ICONS = {
    plus: PlusIcon,
    close: XMarkIcon,
    minus: MinusIcon,
    sparkles: SparklesIcon,
    clock: ClockIcon,
    document: DocumentIcon,
    upload: ArrowUpTrayIcon,
    folder: FolderIcon,
    check: CheckIcon,
    trash: TrashIcon,
    rename: PencilSquareIcon,
    warning: ExclamationTriangleIcon,
    external: ArrowTopRightOnSquareIcon,
};

const icon = computed(() => ICONS[props.name] || null);
const dimension = computed(() => `${props.size}px`);
</script>

<template>
    <component
        :is="icon"
        v-if="icon"
        class="tai-icon"
        :style="{ width: dimension, height: dimension }"
        aria-hidden="true"
    />
</template>
