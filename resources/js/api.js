/**
 * Thin API client for the Twill AI endpoints + the SSE stream parser for
 * laravel/ai's StreamableAgentResponse (`data: {json}` lines, `data: [DONE]`).
 */
export function createApi(config) {
    const chatsUrl = config.urls.chats;

    async function json(method, url, body = null) {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': config.csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: body ? JSON.stringify(body) : null,
        });

        if (!response.ok) {
            let message = `Request failed (${response.status})`;
            try {
                const data = await response.json();
                message = data.message || message;
            } catch { /* keep default */ }
            throw new Error(message);
        }

        return response.status === 204 ? null : response.json();
    }

    async function upload(url, formData) {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': config.csrf,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: formData,
        });

        if (!response.ok) {
            let message = `Upload failed (${response.status})`;
            try {
                const data = await response.json();
                message = data.message || message;
            } catch { /* keep default */ }
            throw new Error(message);
        }

        return response.json();
    }

    return {
        bootstrap: (chatId) => json('GET', `${config.urls.bootstrap}${chatId ? `?chat_id=${chatId}` : ''}`),
        listChats: () => json('GET', chatsUrl),
        createChat: (model) => json('POST', chatsUrl, { model }),
        getChat: (id) => json('GET', `${chatsUrl}/${id}`),
        renameChat: (id, title) => json('PATCH', `${chatsUrl}/${id}`, { title }),
        setChatModel: (id, model) => json('PATCH', `${chatsUrl}/${id}`, { model }),
        deleteChat: (id) => json('DELETE', `${chatsUrl}/${id}`),

        /**
         * Queue a message for the chat (202). The agent runs in a background
         * job; follow progress via pollEvents().
         */
        sendMessage: (chatId, body) => json('POST', `${chatsUrl}/${chatId}/messages`, body),

        /**
         * Poll the in-flight turn's buffered events after the given event id.
         * Returns { status, events: [{ id, data }] }.
         */
        pollEvents: (chatId, afterId) => json('GET', `${chatsUrl}/${chatId}/events?after=${afterId}`),

        /** Ask the running job to stop (it confirms with a turn_complete event). */
        cancelChat: (chatId) => json('POST', `${chatsUrl}/${chatId}/cancel`),

        /** Every file in the shared library (Uploads page + "Use files" picker). */
        listFiles: () => json('GET', config.urls.files),

        /** Upload one or more files into the shared library (multipart). */
        uploadLibraryFiles: (formData) => upload(config.urls.files, formData),

        /** Permanently remove a file from the shared library (and its disk copy). */
        deleteLibraryFile: (id) => json('DELETE', `${config.urls.files}/${id}`),

        /** Content the agent can be pointed at, for the "@" drawer. */
        mentionables: (query) => json('GET', `${config.urls.mentionables}${query ? `?q=${encodeURIComponent(query)}` : ''}`),

        /** Install-wide settings (provider, API key, default model, system prompt). */
        getSettings: () => json('GET', config.urls.settings),
        saveApiKey: (provider, key) => json('PUT', `${config.urls.settings}/key`, { provider, key }),
        saveSettings: (body) => json('PUT', config.urls.settings, body),
        refreshModels: () => json('POST', `${config.urls.settings}/refresh-models`),
    };
}

export const TOOL_LABELS = {
    list_modules: 'Reading module catalog',
    get_module_schema: 'Reading module schema',
    list_blocks: 'Reading block library',
    search_media: 'Searching media library',
    search_content: 'Searching content',
    get_content: 'Reading content',
    create_content: 'Creating draft',
    update_content: 'Updating draft',
    use_attachment_as_media: 'Adding image to media library',
};

export function toolLabel(name) {
    // Tools are named snake_case via name(); normalize CamelCase defensively
    // in case a tool relies on the SDK's class-basename fallback.
    const normalized = name
        .replace(/([a-z0-9])([A-Z])/g, '$1_$2')
        .toLowerCase();

    return TOOL_LABELS[normalized] || normalized.replaceAll('_', ' ');
}
