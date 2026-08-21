<script setup>
import { computed, onMounted, provide, ref } from 'vue';
import ChatPanel from './components/ChatPanel.vue';
import Sidebar from './components/Sidebar.vue';
import UploadsPanel from './components/UploadsPanel.vue';
import SettingsPanel from './components/SettingsPanel.vue';
import Icon from './components/Icon.vue';
import { createApi } from './api';

const props = defineProps({
    config: { type: Object, required: true },
});

const api = createApi(props.config);
provide('api', api);
provide('config', props.config);

const ACTIVE_CHAT_KEY = 'twill_ai_active_chat';
const DRAWER_OPEN_KEY = 'twill_ai_drawer_open';
const ACTIVE_VIEW_KEY = 'twill_ai_active_view';
const EXPANDED_KEY = 'twill_ai_expanded';

const isWidget = computed(() => props.config.mode === 'widget');
const drawerOpen = ref(localStorage.getItem(DRAWER_OPEN_KEY) === '1');

// Three states, not two: launcher -> drawer -> fullscreen. Remembered, so the
// panel reopens the way it was left.
const expanded = ref(localStorage.getItem(EXPANDED_KEY) === '1');

function toggleExpanded() {
    expanded.value = !expanded.value;
    localStorage.setItem(EXPANDED_KEY, expanded.value ? '1' : '0');
}
const activeView = ref(localStorage.getItem(ACTIVE_VIEW_KEY) || 'chat');
const activeChatId = ref(null);
const chats = ref([]);
const panel = ref(null);

function setView(view) {
    activeView.value = view;
    localStorage.setItem(ACTIVE_VIEW_KEY, view);
}

function persistActiveChat(id) {
    activeChatId.value = id;
    if (id) {
        localStorage.setItem(ACTIVE_CHAT_KEY, String(id));
    } else {
        localStorage.removeItem(ACTIVE_CHAT_KEY);
    }
}

// The widget used to skip this, because it had nowhere to show a chat list.
// It now has two: the header's history dropdown and the fullscreen sidebar.
async function refreshChats() {
    try {
        const data = await api.listChats();
        chats.value = data.chats || [];
    } catch { /* history stays empty */ }
}

function toggleDrawer() {
    drawerOpen.value = !drawerOpen.value;
    localStorage.setItem(DRAWER_OPEN_KEY, drawerOpen.value ? '1' : '0');
}

// "End chat": detach the conversation. It stays available in the history on
// the Twill AI page; the next message simply starts a fresh chat.
function endChat() {
    persistActiveChat(null);
    panel.value?.reset();
}

async function selectChat(id) {
    persistActiveChat(id);
    await panel.value?.loadChat(id);
}

async function newChat() {
    endChat();
}

function onChatCreated(id) {
    persistActiveChat(id);
    refreshChats();
}

async function renameChat(chat) {
    const title = window.prompt('Rename chat', chat.title);
    if (!title || title === chat.title) return;
    try {
        await api.renameChat(chat.id, title);
        await refreshChats();
    } catch (error) {
        window.alert(error.message);
    }
}

async function deleteChat(chat) {
    if (!window.confirm(`Delete chat "${chat.title}"? This only removes the chat history, never CMS content.`)) return;
    try {
        await api.deleteChat(chat.id);
        if (activeChatId.value === chat.id) endChat();
        await refreshChats();
    } catch (error) {
        window.alert(error.message);
    }
}

onMounted(async () => {
    const stored = parseInt(localStorage.getItem(ACTIVE_CHAT_KEY) || '', 10);

    if (stored) {
        // Validate the stored chat still exists & belongs to this user.
        try {
            const data = await api.bootstrap(stored);
            if (data.active_chat) {
                persistActiveChat(data.active_chat.id);
                await panel.value?.loadChat(data.active_chat.id, data.active_chat.model_id);
            } else {
                persistActiveChat(null);
            }
        } catch {
            persistActiveChat(null);
        }
    }

    await refreshChats();
});
</script>

<template>
    <!-- Floating widget: launcher pill + right drawer -->
    <template v-if="isWidget">
        <button
            v-show="!drawerOpen"
            type="button"
            class="tai-launcher"
            :title="config.title"
            @click="toggleDrawer"
        >
            <Icon name="sparkles" :size="15" class="tai-launcher__spark" />
            <span>{{ config.title }}</span>
        </button>

        <transition name="tai-slide">
            <div
                v-show="drawerOpen"
                class="tai-drawer"
                :class="{ 'tai-drawer--expanded': expanded }"
            >
                <!-- Only in fullscreen: at drawer width a sidebar would leave
                     the conversation too narrow to read, which is why the
                     collapsed state keeps history in the header dropdown. -->
                <Sidebar
                    v-if="expanded"
                    :chats="chats"
                    :active-chat-id="activeChatId"
                    :settings-url="config.urls.page + '#settings'"
                    @select="selectChat"
                    @new-chat="newChat"
                    @rename="renameChat"
                    @delete="deleteChat"
                />

                <ChatPanel
                    ref="panel"
                    mode="widget"
                    :active-chat-id="activeChatId"
                    :chats="chats"
                    :expanded="expanded"
                    @chat-created="onChatCreated"
                    @select-chat="selectChat"
                    @new-chat="newChat"
                    @toggle-expand="toggleExpanded"
                    @collapse="toggleDrawer"
                />
            </div>
        </transition>
    </template>

    <!-- Full page: secondary nav + (chat | uploads) -->
    <div v-else class="tai-page-wrap">
        <nav class="tai-subnav">
            <button
                type="button"
                class="tai-subnav__item"
                :class="{ 'tai-subnav__item--active': activeView === 'chat' }"
                @click="setView('chat')"
            >Chat</button>
            <button
                type="button"
                class="tai-subnav__item"
                :class="{ 'tai-subnav__item--active': activeView === 'uploads' }"
                @click="setView('uploads')"
            >Uploads</button>
            <button
                type="button"
                class="tai-subnav__item"
                :class="{ 'tai-subnav__item--active': activeView === 'settings' }"
                @click="setView('settings')"
            >Settings</button>
        </nav>

        <div v-show="activeView === 'chat'" class="tai-page">
            <Sidebar
                :chats="chats"
                :active-chat-id="activeChatId"
                @select="selectChat"
                @new-chat="newChat"
                @rename="renameChat"
                @delete="deleteChat"
            />
            <ChatPanel
                ref="panel"
                mode="page"
                :active-chat-id="activeChatId"
                @chat-created="onChatCreated"
                @stream-finished="refreshChats"
                @end-chat="endChat"
            />
        </div>

        <UploadsPanel v-if="activeView === 'uploads'" />
        <SettingsPanel v-if="activeView === 'settings'" />
    </div>
</template>
