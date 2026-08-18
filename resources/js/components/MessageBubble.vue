<script setup>
import { computed } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';
import Icon from './Icon.vue';

const props = defineProps({
    message: { type: Object, required: true },
});

// breaks: false so a single newline is a soft space (not a hard <br> that jams
// the next line directly underneath); blank lines still start a new paragraph.
marked.setOptions({ breaks: false });

const html = computed(() => {
    if (props.message.role !== 'assistant') return '';
    return DOMPurify.sanitize(marked.parse(props.message.content || ''));
});

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}

// User messages render their @-mentions as pills (matching the composer);
// everything else stays escaped plain text.
const userHtml = computed(() => {
    if (props.message.role === 'assistant') return '';

    let text = escapeHtml(props.message.content || '');

    const mentions = [...(props.message.mentions || [])]
        .filter((mention) => mention && mention.title)
        .sort((a, b) => b.title.length - a.title.length);

    for (const mention of mentions) {
        const token = escapeHtml('@' + mention.title);
        const pill = `<span class="tai-mention-pill">@${escapeHtml(mention.title)}</span>`;
        text = text.split(token).join(pill);
    }

    return DOMPurify.sanitize(text);
});
</script>

<template>
    <div class="tai-message" :class="`tai-message--${message.role}`">
        <div v-if="message.toolEvents && message.toolEvents.length" class="tai-tools">
            <span
                v-for="(toolEvent, index) in message.toolEvents"
                :key="index"
                class="tai-tool-chip"
                :class="`tai-tool-chip--${toolEvent.status}`"
            >
                <span v-if="toolEvent.status === 'running'" class="tai-spinner"></span>
                <Icon v-else-if="toolEvent.status === 'error'" name="warning" :size="13" />
                <Icon v-else name="check" :size="13" />
                {{ toolEvent.label }}
                <a v-if="toolEvent.editUrl" :href="toolEvent.editUrl" class="tai-tool-chip__link">Open in CMS <Icon name="external" :size="12" /></a>
            </span>
        </div>

        <div v-if="message.attachments && message.attachments.length" class="tai-msg-files">
            <span
                v-for="(file, index) in message.attachments"
                :key="index"
                class="tai-msg-file"
                :title="file.name"
            >
                <img v-if="file.is_image && file.preview_url" :src="file.preview_url" class="tai-msg-file__thumb" alt="">
                <span v-else class="tai-msg-file__icon"><Icon name="document" :size="14" /></span>
                <span class="tai-msg-file__name">{{ file.name }}</span>
            </span>
        </div>

        <div v-if="message.role === 'assistant'" class="tai-message__bubble">
            <div v-if="message.content" class="tai-markdown" v-html="html"></div>
            <span v-else-if="message.streaming" class="tai-typing"><span></span><span></span><span></span></span>
        </div>
        <div v-else-if="message.content" class="tai-message__bubble" v-html="userHtml"></div>

        <p v-if="message.error" class="tai-message__error">{{ message.error }}</p>
    </div>
</template>
