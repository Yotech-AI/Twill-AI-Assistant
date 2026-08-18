<script setup>
import { computed, ref } from 'vue';
import Icon from './Icon.vue';

const props = defineProps({
    chats: { type: Array, default: () => [] },
    activeChatId: { type: Number, default: null },
});

defineEmits(['select', 'new-chat', 'rename', 'delete']);

const filter = ref('');

const filteredChats = computed(() => {
    const term = filter.value.trim().toLowerCase();
    if (!term) return props.chats;
    return props.chats.filter((chat) => (chat.title || '').toLowerCase().includes(term));
});

function formatDate(value) {
    if (!value) return '';
    const date = new Date(value);
    const today = new Date();
    const sameDay = date.toDateString() === today.toDateString();
    return sameDay
        ? date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
        : date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
}
</script>

<template>
    <aside class="tai-sidebar">
        <button type="button" class="tai-button tai-button--block" @click="$emit('new-chat')">
            <Icon name="plus" :size="16" /> New chat
        </button>

        <input
            v-model="filter"
            type="search"
            class="tai-sidebar__search"
            placeholder="Search chats…"
        >

        <ul class="tai-sidebar__list">
            <li v-if="!filteredChats.length" class="tai-sidebar__empty">No chats yet.</li>
            <li
                v-for="chat in filteredChats"
                :key="chat.id"
                class="tai-sidebar__item"
                :class="{ 'tai-sidebar__item--active': chat.id === activeChatId }"
            >
                <button type="button" class="tai-sidebar__title" @click="$emit('select', chat.id)">
                    <span class="tai-sidebar__name">{{ chat.title }}</span>
                    <span class="tai-sidebar__meta">{{ formatDate(chat.last_activity_at) }}</span>
                </button>
                <span class="tai-sidebar__tools">
                    <button type="button" class="tai-icon-button" title="Rename" @click.stop="$emit('rename', chat)"><Icon name="rename" :size="14" /></button>
                    <button type="button" class="tai-icon-button" title="Delete chat" @click.stop="$emit('delete', chat)"><Icon name="trash" :size="14" /></button>
                </span>
            </li>
        </ul>
    </aside>
</template>
