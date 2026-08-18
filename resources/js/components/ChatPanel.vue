<script setup>
import { computed, inject, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import MessageBubble from './MessageBubble.vue';
import FileLibraryDrawer from './FileLibraryDrawer.vue';
import Icon from './Icon.vue';
import { toolLabel } from '../api';

const props = defineProps({
    mode: { type: String, default: 'page' },
    activeChatId: { type: Number, default: null },
});

const emit = defineEmits(['chat-created', 'stream-finished', 'end-chat', 'collapse']);

const api = inject('api');
const config = inject('config');

const POLL_INTERVAL = 700;
const MENTION_DEBOUNCE = 120;

const messages = ref([]);
const draft = ref('');
const isStreaming = ref(false);
const error = ref(null);
const selectedModel = ref(config.default_model);
const thread = ref(null);
const composer = ref(null);

// Composer attachments (the "+" button) + file library picker.
const fileInput = ref(null);
const files = ref([]);
const uploadError = ref(null);
const plusOpen = ref(false);
const pickerOpen = ref(false);

// "@" mention autocomplete.
const mentions = ref([]);
const mentionOpen = ref(false);
const mentionItems = ref([]);
const mentionIndex = ref(0);
const mentionDrawer = ref(null);

let pollTimer = null;
let pollChatId = null;
let lastEventId = 0;
let turnFinished = false;
let idleEmptyPolls = 0;
let ensuredChatId = null;
let fileKeySeq = 0;
let mentionFetchTimer = null;
let mentionRange = null;

const isWidget = computed(() => props.mode === 'widget');
const uploadConfig = computed(() => config.uploads || { max_files: 5, extensions: [] });
const acceptAttr = computed(() => (uploadConfig.value.extensions || []).map((ext) => `.${ext}`).join(','));
const uploading = computed(() => files.value.some((file) => file.uploading));
const readyFiles = computed(() => files.value.filter((file) => file.id && !file.error));
const isEmpty = computed(() => !draft.value || draft.value.trim() === '');
const canSend = computed(
    () => !isStreaming.value && !uploading.value && (draft.value.trim().length > 0 || readyFiles.value.length > 0),
);

function reset() {
    stopPolling();
    messages.value = [];
    error.value = null;
    uploadError.value = null;
    isStreaming.value = false;
    selectedModel.value = config.default_model;
    clearComposer();
    ensuredChatId = null;
}

function clearComposer() {
    draft.value = '';
    files.value = [];
    mentions.value = [];
    plusOpen.value = false;
    pickerOpen.value = false;
    if (composer.value) composer.value.innerHTML = '';
    closeMention();
}

async function loadChat(id, modelId = null) {
    stopPolling();
    error.value = null;
    clearComposer();
    ensuredChatId = null;

    try {
        const data = await api.getChat(id);
        selectedModel.value = data.model_id || modelId || config.default_model;
        messages.value = (data.messages || []).map((message) => ({
            role: message.role,
            content: message.content || '',
            attachments: [],
            toolEvents: (message.tool_calls || []).map((call) => ({
                name: call.name,
                label: toolLabel(call.name),
                status: 'done',
                editUrl: null,
            })),
            streaming: false,
            error: null,
        }));

        // A generation is still running for this chat (e.g. we navigated away
        // mid-turn): replay the buffered turn and keep following it live.
        if (data.status === 'queued' || data.status === 'streaming') {
            isStreaming.value = true;
            startPolling(id);
        }

        scrollToBottom();
    } catch (e) {
        error.value = e.message;
    }
}

function scrollToBottom() {
    nextTick(() => {
        if (thread.value) thread.value.scrollTop = thread.value.scrollHeight;
    });
}

async function changeModel() {
    if (props.activeChatId) {
        try {
            await api.setChatModel(props.activeChatId, selectedModel.value);
        } catch (e) {
            error.value = e.message;
        }
    }
}

async function stop() {
    const chatId = pollChatId || props.activeChatId;
    if (!chatId) return;

    try {
        await api.cancelChat(chatId);
        // Keep polling: the job confirms the stop with a turn_complete event.
    } catch (e) {
        error.value = e.message;
    }
}

/** Resolve the chat to act on, lazily creating one for the first message. */
async function ensureChat() {
    let chatId = props.activeChatId || ensuredChatId;
    if (chatId) return chatId;

    const chat = await api.createChat(selectedModel.value);
    ensuredChatId = chat.id;
    emit('chat-created', chat.id);
    return chat.id;
}

/* ---------- File library ("+" menu) ---------- */

function pickFiles() {
    fileInput.value?.click();
}

function openUpload() {
    plusOpen.value = false;
    pickFiles();
}

function openPicker() {
    plusOpen.value = false;
    pickerOpen.value = true;
}

async function onFilesPicked(event) {
    const picked = Array.from(event.target.files || []);
    event.target.value = '';
    if (!picked.length) return;

    uploadError.value = null;

    const room = (uploadConfig.value.max_files || 5) - files.value.length;
    const accepted = picked.slice(0, Math.max(0, room));

    if (accepted.length < picked.length) {
        uploadError.value = `You can attach up to ${uploadConfig.value.max_files} files.`;
    }
    if (!accepted.length) return;

    const keys = accepted.map(() => ++fileKeySeq);
    files.value.push(...accepted.map((file, index) => ({
        key: keys[index],
        id: null,
        name: file.name,
        is_image: (file.type || '').startsWith('image/'),
        preview_url: null,
        size: file.size,
        uploading: true,
        error: false,
    })));

    // Mutate via files.value so we touch Vue's reactive proxies, not the raw
    // objects we just pushed (otherwise the UI never leaves the spinner state).
    const byKey = (key) => files.value.find((file) => file.key === key);

    const form = new FormData();
    accepted.forEach((file) => form.append('files[]', file));

    try {
        const data = await api.uploadLibraryFiles(form);
        (data.files || []).forEach((meta, index) => {
            const target = byKey(keys[index]);
            if (target) Object.assign(target, meta, { uploading: false, error: false });
        });
    } catch (e) {
        keys.forEach((key) => {
            const target = byKey(key);
            if (target) {
                target.uploading = false;
                target.error = true;
            }
        });
        uploadError.value = e.message;
    }
}

/** Toggle a library file as an attachment on this message. */
function selectFromLibrary(file) {
    const index = files.value.findIndex((item) => item.id === file.id);

    if (index !== -1) {
        files.value.splice(index, 1);
        return;
    }

    files.value.push({
        key: ++fileKeySeq,
        id: file.id,
        name: file.name,
        is_image: file.is_image,
        preview_url: file.preview_url,
        size: file.size,
        uploading: false,
        error: false,
    });
}

/** Detach a file from this message. The file stays in the shared library. */
function removeFile(file) {
    const index = files.value.indexOf(file);
    if (index !== -1) files.value.splice(index, 1);
}

/* ---------- @ mention autocomplete (contenteditable) ---------- */

function syncFromDom() {
    if (!composer.value) return;

    draft.value = composer.value.innerText;
    mentions.value = Array.from(composer.value.querySelectorAll('.tai-mention-pill')).map((el) => ({
        module: el.dataset.module,
        id: Number(el.dataset.id),
        title: (el.textContent || '').replace(/^@/, ''),
    }));
}

function onComposerInput() {
    syncFromDom();
    detectMention();
}

function detectMention() {
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0 || !composer.value) {
        if (mentionOpen.value) closeMention();
        return;
    }

    const range = selection.getRangeAt(0);
    if (!range.collapsed || !composer.value.contains(range.startContainer)) {
        if (mentionOpen.value) closeMention();
        return;
    }

    const node = range.startContainer;
    if (node.nodeType !== Node.TEXT_NODE) {
        if (mentionOpen.value) closeMention();
        return;
    }

    const before = node.textContent.slice(0, range.startOffset);
    const match = before.match(/(?:^|\s)@([^\s@]*)$/);

    if (!match) {
        if (mentionOpen.value) closeMention();
        return;
    }

    const atIndex = range.startOffset - match[1].length - 1;
    mentionRange = document.createRange();
    mentionRange.setStart(node, atIndex);
    mentionRange.setEnd(node, range.startOffset);

    mentionOpen.value = true;
    fetchMentionables(match[1] || null);
}

function fetchMentionables(query) {
    if (mentionFetchTimer) clearTimeout(mentionFetchTimer);
    mentionFetchTimer = setTimeout(async () => {
        try {
            const data = await api.mentionables(query);
            mentionItems.value = data.items || [];
            mentionIndex.value = 0;
        } catch {
            mentionItems.value = [];
        }
    }, MENTION_DEBOUNCE);
}

function closeMention() {
    mentionOpen.value = false;
    mentionItems.value = [];
    mentionIndex.value = 0;
    mentionRange = null;
    if (mentionFetchTimer) clearTimeout(mentionFetchTimer);
}

function moveMention(step) {
    const count = mentionItems.value.length;
    if (!count) return;
    mentionIndex.value = (mentionIndex.value + step + count) % count;

    // Keep the highlighted row visible when navigating with the keyboard.
    nextTick(() => {
        mentionDrawer.value?.querySelector('.tai-mention__item--active')?.scrollIntoView({ block: 'nearest' });
    });
}

function insertMention(item) {
    if (!item || !mentionRange || !composer.value) return;

    // Replace the typed "@query" with a non-editable pill + a trailing space.
    mentionRange.deleteContents();

    const pill = document.createElement('span');
    pill.className = 'tai-mention-pill';
    pill.setAttribute('contenteditable', 'false');
    pill.dataset.module = item.module;
    pill.dataset.id = String(item.id);
    pill.textContent = `@${item.title}`;

    const space = document.createTextNode(' ');
    const fragment = document.createDocumentFragment();
    fragment.appendChild(pill);
    fragment.appendChild(space);
    mentionRange.insertNode(fragment);

    const selection = window.getSelection();
    const after = document.createRange();
    after.setStartAfter(space);
    after.collapse(true);
    selection.removeAllRanges();
    selection.addRange(after);

    closeMention();
    syncFromDom();
    composer.value.focus();
}

function onComposerKeydown(event) {
    if (mentionOpen.value && mentionItems.value.length) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            moveMention(1);
            return;
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveMention(-1);
            return;
        }
        if (event.key === 'Enter' || event.key === 'Tab') {
            event.preventDefault();
            insertMention(mentionItems.value[mentionIndex.value]);
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            closeMention();
            return;
        }
    }

    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        send();
    }
}

/** Paste as plain text so no foreign markup enters the contenteditable. */
function onComposerPaste(event) {
    event.preventDefault();
    const text = (event.clipboardData || window.clipboardData).getData('text/plain');
    const selection = window.getSelection();
    if (!selection || selection.rangeCount === 0) return;

    const range = selection.getRangeAt(0);
    range.deleteContents();
    const node = document.createTextNode(text);
    range.insertNode(node);
    range.setStartAfter(node);
    range.collapse(true);
    selection.removeAllRanges();
    selection.addRange(range);

    syncFromDom();
    detectMention();
}

/* ---------- Sending ---------- */

async function send() {
    if (!canSend.value) return;

    syncFromDom();

    const text = draft.value.trim();
    const attached = readyFiles.value.slice();
    const attachments = attached.map((file) => file.id);
    const activeMentions = mentions.value.map((mention) => ({ module: mention.module, id: mention.id }));
    const mentionChips = mentions.value.map((mention) => ({ module: mention.module, id: mention.id, title: mention.title }));

    error.value = null;
    uploadError.value = null;
    closeMention();

    let chatId;
    try {
        chatId = await ensureChat();
    } catch (e) {
        error.value = e.message;
        return;
    }

    messages.value.push({
        role: 'user',
        content: text,
        attachments: attached.map((file) => ({ name: file.name, is_image: file.is_image, preview_url: file.preview_url })),
        mentions: mentionChips,
        toolEvents: [],
        streaming: false,
        error: null,
    });
    messages.value.push(makeAssistantPlaceholder());

    isStreaming.value = true;
    clearComposer();
    scrollToBottom();

    try {
        await api.sendMessage(chatId, { message: text, model: selectedModel.value, attachments, mentions: activeMentions });
    } catch (e) {
        // Queueing failed (e.g. previous turn still running): roll back.
        messages.value.splice(-2, 2);
        isStreaming.value = false;
        error.value = e.message;
        draft.value = text;
        if (composer.value) composer.value.innerText = text;
        return;
    }

    startPolling(chatId);
}

function makeAssistantPlaceholder() {
    return { role: 'assistant', content: '', attachments: [], toolEvents: [], streaming: true, error: null };
}

function currentAssistant() {
    const last = messages.value[messages.value.length - 1];

    if (!last || last.role !== 'assistant' || !last.streaming) {
        const placeholder = makeAssistantPlaceholder();
        messages.value.push(placeholder);
        return placeholder;
    }

    return last;
}

function startPolling(chatId) {
    stopPolling();
    pollChatId = chatId;
    lastEventId = 0;
    turnFinished = false;
    idleEmptyPolls = 0;
    pollOnce(chatId);
}

function stopPolling() {
    if (pollTimer) clearTimeout(pollTimer);
    pollTimer = null;
    pollChatId = null;
}

async function pollOnce(chatId) {
    if (pollChatId !== chatId) return;

    let data;

    try {
        data = await api.pollEvents(chatId, lastEventId);
    } catch (e) {
        finishTurn(e.message);
        return;
    }

    if (pollChatId !== chatId) return;

    for (const event of data.events || []) {
        lastEventId = event.id;
        handleBufferedEvent(event.data || {});
    }

    if (turnFinished) {
        finishTurn();
        return;
    }

    // Failsafe: the buffer says nothing more is coming and the chat is idle
    // (e.g. the worker died before writing turn_complete).
    if (data.status === 'idle' && (data.events || []).length === 0) {
        if (++idleEmptyPolls >= 3) {
            finishTurn();
            return;
        }
    } else {
        idleEmptyPolls = 0;
    }

    pollTimer = setTimeout(() => pollOnce(chatId), POLL_INTERVAL);
}

function finishTurn(errorMessage = null) {
    const last = messages.value[messages.value.length - 1];

    if (last && last.role === 'assistant') {
        last.streaming = false;
        if (errorMessage && !last.error) last.error = errorMessage;
        // A turn that produced nothing visible (e.g. worker not running yet).
        if (!errorMessage && !last.content && !last.toolEvents.length && !last.error) {
            last.error = 'No response received — is the "twill-ai" queue worker running?';
        }
    }

    stopPolling();
    isStreaming.value = false;
    emit('stream-finished');
    scrollToBottom();
}

function handleBufferedEvent(event) {
    switch (event.type) {
        case 'twill_ai.user_message': {
            // Resume case: render the in-flight user message unless it is
            // already the latest user message (optimistic send / history).
            const lastUser = [...messages.value].reverse().find((message) => message.role === 'user');
            if (!lastUser || lastUser.content !== event.content) {
                messages.value.push({
                    role: 'user',
                    content: event.content,
                    attachments: event.attachments || [],
                    mentions: event.mentions || [],
                    toolEvents: [],
                    streaming: false,
                    error: null,
                });
            }
            break;
        }

        case 'text_delta':
            currentAssistant().content += event.delta;
            break;

        case 'tool_call':
            currentAssistant().toolEvents.push({
                name: event.tool_name,
                label: toolLabel(event.tool_name),
                status: 'running',
                editUrl: null,
            });
            break;

        case 'tool_result': {
            const assistant = currentAssistant();
            const toolEvent = [...assistant.toolEvents].reverse()
                .find((candidate) => candidate.name === event.tool_name && candidate.status === 'running');

            if (toolEvent) {
                toolEvent.status = event.successful === false ? 'error' : 'done';

                try {
                    const result = JSON.parse(event.result);
                    if (result && result.edit_url) toolEvent.editUrl = result.edit_url;
                    if (result && result.error) toolEvent.status = 'error';
                } catch { /* non-JSON results are fine */ }
            }
            break;
        }

        case 'error':
            currentAssistant().error = event.message || 'The model returned an error.';
            break;

        case 'twill_ai.cancelled':
            currentAssistant().error = 'Stopped.';
            break;

        case 'twill_ai.turn_complete':
            turnFinished = true;
            break;

        default:
            // laravel/ai Error events carry provider-specific type strings;
            // recognize them by shape.
            if (event.message !== undefined && event.recoverable !== undefined) {
                currentAssistant().error = event.message;
            }
    }

    scrollToBottom();
}

defineExpose({ loadChat, reset });

watch(() => props.activeChatId, (id, previous) => {
    if (!id && previous) reset();
});

onBeforeUnmount(() => {
    stopPolling();
    if (mentionFetchTimer) clearTimeout(mentionFetchTimer);
});
</script>

<template>
    <section class="tai-panel" :class="{ 'tai-panel--widget': isWidget }">
        <header class="tai-panel__header">
            <div class="tai-panel__brand">
                <Icon name="sparkles" :size="16" class="tai-panel__spark" />
                <strong>{{ config.title }}</strong>
            </div>

            <div class="tai-panel__actions">
                <a v-if="isWidget" class="tai-icon-button" :href="config.urls.page" title="Open history"><Icon name="clock" :size="15" /></a>
                <button
                    v-if="messages.length"
                    type="button"
                    class="tai-icon-button"
                    title="End chat (history is kept on the Twill AI page)"
                    @click="$emit('end-chat')"
                ><Icon name="close" :size="14" /> End</button>
                <button
                    v-if="isWidget"
                    type="button"
                    class="tai-icon-button"
                    title="Minimize"
                    @click="$emit('collapse')"
                ><Icon name="minus" :size="15" /></button>
            </div>
        </header>

        <div ref="thread" class="tai-thread">
            <div v-if="!messages.length" class="tai-empty">
                <p class="tai-empty__spark"><Icon name="sparkles" :size="26" /></p>
                <p class="tai-empty__title">{{ config.title }}</p>
                <p v-if="config.intro">{{ config.intro }}</p>
                <p v-if="config.hint" class="tai-empty__hint">{{ config.hint }}</p>
            </div>

            <MessageBubble
                v-for="(message, index) in messages"
                :key="index"
                :message="message"
            />
        </div>

        <p v-if="error" class="tai-error">{{ error }}</p>
        <p v-if="uploadError" class="tai-error">{{ uploadError }}</p>

        <footer class="tai-composer" :class="{ 'tai-composer--working': isStreaming }">
            <div v-if="files.length" class="tai-attachments">
                <div
                    v-for="file in files"
                    :key="file.key"
                    class="tai-attachment"
                    :class="{ 'tai-attachment--error': file.error }"
                    :title="file.name"
                >
                    <img v-if="file.is_image && file.preview_url" :src="file.preview_url" class="tai-attachment__thumb" alt="">
                    <span v-else class="tai-attachment__icon"><Icon name="document" :size="14" /></span>
                    <span class="tai-attachment__name">{{ file.name }}</span>
                    <span v-if="file.uploading" class="tai-attachment__spinner"></span>
                    <button v-else type="button" class="tai-attachment__remove" title="Remove" @click="removeFile(file)"><Icon name="close" :size="13" /></button>
                </div>
            </div>

            <div class="tai-composer__field">
                <div
                    ref="composer"
                    class="tai-composer__input"
                    :class="{ 'tai-composer__input--empty': isEmpty }"
                    :contenteditable="isStreaming ? 'false' : 'true'"
                    role="textbox"
                    aria-multiline="true"
                    :data-placeholder="isStreaming ? '' : 'Describe the content you need…  (Enter to send, @ to reference content)'"
                    @input="onComposerInput"
                    @click="detectMention"
                    @keydown="onComposerKeydown"
                    @paste="onComposerPaste"
                ></div>

                <span v-if="isStreaming" class="tai-working" aria-live="polite">
                    Working<span class="tai-working__dots"><span>.</span><span>.</span><span>.</span></span>
                </span>

                <div v-if="mentionOpen" ref="mentionDrawer" class="tai-mention">
                    <ul class="tai-mention__list">
                        <li
                            v-for="(item, index) in mentionItems"
                            :key="`${item.module}-${item.id}`"
                            class="tai-mention__item"
                            :class="{ 'tai-mention__item--active': index === mentionIndex }"
                            @mousedown.prevent="insertMention(item)"
                            @mouseenter="mentionIndex = index"
                        >
                            <span class="tai-mention__module">{{ item.module_label }}</span>
                            <span class="tai-mention__sep">:</span>
                            <span class="tai-mention__title">{{ item.title }}</span>
                        </li>
                        <li v-if="!mentionItems.length" class="tai-mention__empty">No matching content</li>
                    </ul>
                </div>
            </div>

            <div class="tai-composer__toolbar">
                <div class="tai-composer__left">
                    <div class="tai-plus">
                        <button
                            type="button"
                            class="tai-tool-button"
                            title="Add files"
                            :disabled="isStreaming"
                            @click="plusOpen = !plusOpen"
                        ><Icon name="plus" :size="20" /></button>

                        <div v-if="plusOpen" class="tai-plus__backdrop" @click="plusOpen = false"></div>
                        <div v-if="plusOpen" class="tai-plus__menu">
                            <button type="button" class="tai-plus__item" @click="openUpload">
                                <Icon name="upload" :size="16" /> Upload files
                            </button>
                            <button type="button" class="tai-plus__item" @click="openPicker">
                                <Icon name="folder" :size="16" /> Use files
                            </button>
                        </div>
                    </div>

                    <input
                        ref="fileInput"
                        type="file"
                        multiple
                        class="tai-file-input"
                        :accept="acceptAttr"
                        @change="onFilesPicked"
                    >
                </div>

                <div class="tai-composer__right">
                    <select v-model="selectedModel" class="tai-model-picker" :disabled="isStreaming" @change="changeModel">
                        <option v-for="model in config.models" :key="model.id" :value="model.id">
                            {{ model.label }}
                        </option>
                    </select>

                    <button v-if="isStreaming" type="button" class="tai-button tai-button--stop" @click="stop">
                        Stop
                    </button>
                    <button v-else type="button" class="tai-button" :disabled="!canSend" @click="send">
                        Send
                    </button>
                </div>
            </div>

            <FileLibraryDrawer
                v-if="pickerOpen"
                :attached-ids="files.map((file) => file.id).filter(Boolean)"
                @select="selectFromLibrary"
                @close="pickerOpen = false"
            />
        </footer>
    </section>
</template>
