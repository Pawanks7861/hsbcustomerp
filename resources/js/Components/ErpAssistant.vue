<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { GripHorizontal, MessageCircle, Minimize2, Send, Trash2, X } from '@lucide/vue';
import { applyAssistantMarkup, escapeHtml, formatAssistantText, safeInternalHref } from '@/chatbot/formatAssistantText';

const purifier = shallowRef(null);
let purifierLoading = null;

/** DOMPurify is only needed once the chat is opened, so it is fetched on demand. */
function loadPurifier() {
    purifierLoading ??= import('dompurify')
        .then((module) => {
            purifier.value = module.default;

            return module.default;
        })
        .catch(() => {
            purifierLoading = null;

            return null;
        });

    return purifierLoading;
}

const STORAGE_KEY = 'buildify360.assistant.position';
const FAB_SIZE = 56;
const PANEL_WIDTH = 380;
const PANEL_HEIGHT = 560;
const MARGIN = 12;

const open = ref(false);
const input = ref('');
const loading = ref(false);
const error = ref('');
const messages = ref([]);
const listEl = ref(null);

const pos = ref({ x: 0, y: 0 });
const dragging = ref(false);
const dragMoved = ref(false);
const ready = ref(false);

let dragOffsetX = 0;
let dragOffsetY = 0;
let startClientX = 0;
let startClientY = 0;

function xsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match ? decodeURIComponent(match[1]) : '';
}

const starterSuggestions = [
    'What is our total payable?',
    'Show pending purchase requisitions',
    'How do I create a purchase order?',
    'Show my projects',
    'Show outstanding invoices',
];

function widgetSize() {
    if (open.value) {
        return {
            width: Math.min(PANEL_WIDTH, window.innerWidth - MARGIN * 2),
            height: Math.min(PANEL_HEIGHT, window.innerHeight * 0.7, window.innerHeight - MARGIN * 2),
        };
    }

    return { width: FAB_SIZE, height: FAB_SIZE };
}

function defaultPosition() {
    const { width, height } = widgetSize();

    return {
        x: Math.max(MARGIN, window.innerWidth - width - 24),
        y: Math.max(MARGIN, window.innerHeight - height - 24),
    };
}

function clampPosition(x, y) {
    const { width, height } = widgetSize();
    const maxX = Math.max(MARGIN, window.innerWidth - width - MARGIN);
    const maxY = Math.max(MARGIN, window.innerHeight - height - MARGIN);

    return {
        x: Math.min(Math.max(MARGIN, Number(x) || MARGIN), maxX),
        y: Math.min(Math.max(MARGIN, Number(y) || MARGIN), maxY),
    };
}

function loadPosition() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw) {
            const saved = JSON.parse(raw);
            if (typeof saved?.x === 'number' && typeof saved?.y === 'number') {
                pos.value = clampPosition(saved.x, saved.y);
                ready.value = true;
                return;
            }
        }
    } catch (e) {
        // ignore
    }
    pos.value = defaultPosition();
    ready.value = true;
}

function savePosition() {
    localStorage.setItem(STORAGE_KEY, JSON.stringify({ x: pos.value.x, y: pos.value.y }));
}

const shellStyle = computed(() => ({
    left: `${pos.value.x}px`,
    top: `${pos.value.y}px`,
    right: 'auto',
    bottom: 'auto',
    zIndex: 9999,
}));

function dockToRight() {
    // Collapsed button always returns to the bottom-right corner.
    pos.value = defaultPosition();
    savePosition();
}

function closeAssistant() {
    open.value = false;
    dockToRight();
}

watch(open, async (isOpen) => {
    if (isOpen) {
        loadPurifier();
        // Opening: keep button position, only clamp so the panel stays on-screen.
        pos.value = clampPosition(pos.value.x, pos.value.y);
        savePosition();
        await nextTick();
        scrollToBottom();
        return;
    }

    dockToRight();
});

function onResize() {
    pos.value = clampPosition(pos.value.x, pos.value.y);
    savePosition();
}

function clientPoint(event) {
    if (event.touches && event.touches[0]) {
        return { x: event.touches[0].clientX, y: event.touches[0].clientY };
    }
    if (event.changedTouches && event.changedTouches[0]) {
        return { x: event.changedTouches[0].clientX, y: event.changedTouches[0].clientY };
    }

    return { x: event.clientX, y: event.clientY };
}

function beginDrag(event, { allowFromButton = false } = {}) {
    if (event.type.startsWith('mouse') && event.button !== 0) {
        return;
    }

    const target = event.target;
    if (!allowFromButton && target?.closest?.('[data-no-drag]')) {
        return;
    }

    const point = clientPoint(event);
    dragging.value = true;
    dragMoved.value = false;
    startClientX = point.x;
    startClientY = point.y;
    dragOffsetX = point.x - pos.value.x;
    dragOffsetY = point.y - pos.value.y;

    window.addEventListener('mousemove', onDragMove);
    window.addEventListener('mouseup', onDragEnd);
    window.addEventListener('touchmove', onDragMove, { passive: false });
    window.addEventListener('touchend', onDragEnd);
    window.addEventListener('touchcancel', onDragEnd);

    // Prevent text selection / page scroll while dragging.
    event.preventDefault();
}

function onDragMove(event) {
    if (!dragging.value) {
        return;
    }

    const point = clientPoint(event);
    if (Math.abs(point.x - startClientX) > 4 || Math.abs(point.y - startClientY) > 4) {
        dragMoved.value = true;
    }

    pos.value = clampPosition(point.x - dragOffsetX, point.y - dragOffsetY);
    event.preventDefault();
}

function onDragEnd(event) {
    if (!dragging.value) {
        return;
    }

    dragging.value = false;
    pos.value = clampPosition(pos.value.x, pos.value.y);
    savePosition();

    window.removeEventListener('mousemove', onDragMove);
    window.removeEventListener('mouseup', onDragEnd);
    window.removeEventListener('touchmove', onDragMove);
    window.removeEventListener('touchend', onDragEnd);
    window.removeEventListener('touchcancel', onDragEnd);

    // FAB: treat as click-to-open only when the pointer barely moved.
    if (!open.value && !dragMoved.value) {
        open.value = true;
    }
}

function onFabPointerDown(event) {
    beginDrag(event, { allowFromButton: true });
}

function onHeaderPointerDown(event) {
    beginDrag(event, { allowFromButton: false });
}

function scrollToBottom() {
    if (listEl.value) {
        listEl.value.scrollTop = listEl.value.scrollHeight;
    }
}

function pushUser(text) {
    messages.value.push({ role: 'user', text });
}

function pushAssistant(reply) {
    messages.value.push({
        role: 'assistant',
        text: reply.message,
        links: reply.links || [],
        suggestions: reply.suggestions || [],
        items: reply.items || [],
        confidence: reply.confidence,
    });
}

async function send(text = null) {
    const message = (text ?? input.value).trim();
    if (!message || loading.value) {
        return;
    }

    error.value = '';
    input.value = '';
    pushUser(message);
    loading.value = true;
    await nextTick();
    scrollToBottom();

    try {
        const response = await fetch('/assistant/ask', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ message }),
        });

        if (!response.ok) {
            if (response.status === 419) {
                throw new Error('Session expired. Please refresh the page and try again.');
            }
            throw new Error('Request failed');
        }

        const data = await response.json();
        await loadPurifier();
        pushAssistant(data.reply || { message: 'No response received.' });
    } catch (e) {
        error.value = e?.message || 'Could not reach the assistant. Please try again.';
        pushAssistant({ message: 'Sorry — I could not process that request right now.' });
    } finally {
        loading.value = false;
        await nextTick();
        scrollToBottom();
    }
}

async function clearChat() {
    messages.value = [];
    error.value = '';
    try {
        await fetch('/assistant/clear', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
    } catch (e) {
        // ignore
    }
}

function onKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        send();
    }
}

function formatText(text) {
    if (purifier.value) {
        return formatAssistantText(text, purifier.value);
    }

    // Sanitizer not loaded yet: text is escaped first, so only <strong>/<br> from our own markup can appear.
    return applyAssistantMarkup(escapeHtml(text));
}

function assistantItems(items) {
    return (items || []).map((item) => ({
        ...item,
        url: safeInternalHref(item.url),
    }));
}

function assistantLinks(links) {
    return (links || []).flatMap((link) => {
        const url = safeInternalHref(link.url);

        return url ? [{ label: link.label, url }] : [];
    });
}

onMounted(() => {
    loadPosition();
    window.addEventListener('resize', onResize);
});

onBeforeUnmount(() => {
    window.removeEventListener('resize', onResize);
    window.removeEventListener('mousemove', onDragMove);
    window.removeEventListener('mouseup', onDragEnd);
    window.removeEventListener('touchmove', onDragMove);
    window.removeEventListener('touchend', onDragEnd);
    window.removeEventListener('touchcancel', onDragEnd);
});
</script>

<template>
    <div
        v-show="ready"
        class="fixed print:hidden"
        :class="dragging ? 'select-none' : ''"
        :style="shellStyle"
    >
        <!-- Collapsed floating button: drag to move, tap/click to open -->
        <button
            v-if="!open"
            type="button"
            class="flex h-14 w-14 items-center justify-center rounded-full bg-slate-900 text-white shadow-lg shadow-slate-900/30 transition hover:bg-slate-800"
            :class="dragging ? 'cursor-grabbing scale-105' : 'cursor-grab'"
            aria-label="Open buildify360 Assistant"
            title="Drag to move · Click to open"
            @mousedown="onFabPointerDown"
            @touchstart.prevent="onFabPointerDown"
        >
            <MessageCircle class="pointer-events-none h-6 w-6" />
        </button>

        <!-- Open chat panel -->
        <div
            v-else
            class="flex h-[min(70vh,560px)] w-[min(100vw-2rem,380px)] flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl shadow-slate-900/20"
        >
            <header
                class="flex items-start justify-between gap-3 border-b border-slate-100 bg-slate-900 px-4 py-3 text-white"
                :class="dragging ? 'cursor-grabbing' : 'cursor-grab'"
                title="Drag to move"
                @mousedown="onHeaderPointerDown"
                @touchstart.prevent="onHeaderPointerDown"
            >
                <div class="min-w-0 flex-1">
                    <div class="mb-1 flex items-center gap-1.5 text-slate-400">
                        <GripHorizontal class="pointer-events-none h-3.5 w-3.5 shrink-0" />
                        <span class="text-[10px] uppercase tracking-wide">Drag to move</span>
                    </div>
                    <h2 class="pointer-events-none text-sm font-semibold">buildify360 Assistant</h2>
                    <p class="pointer-events-none mt-0.5 text-xs text-slate-300">Ask questions about your ERP</p>
                </div>
                <div class="flex items-center gap-1" data-no-drag @mousedown.stop @touchstart.stop>
                    <button type="button" class="rounded-lg p-1.5 hover:bg-white/10" title="Clear conversation" @click="clearChat">
                        <Trash2 class="h-4 w-4" />
                    </button>
                    <button type="button" class="rounded-lg p-1.5 hover:bg-white/10" title="Minimize" @click="closeAssistant">
                        <Minimize2 class="h-4 w-4" />
                    </button>
                    <button type="button" class="rounded-lg p-1.5 hover:bg-white/10" title="Close" @click="closeAssistant">
                        <X class="h-4 w-4" />
                    </button>
                </div>
            </header>

            <div ref="listEl" class="flex-1 space-y-3 overflow-y-auto bg-slate-50 px-3 py-3">
                <div v-if="messages.length === 0" class="space-y-3">
                    <div class="rounded-2xl bg-white p-3 text-sm text-slate-600 shadow-sm">
                        Hi — I can help with projects, BOQ, procurement, inventory, vendors, invoices, expenses, work orders, and reports.
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="suggestion in starterSuggestions"
                            :key="suggestion"
                            type="button"
                            class="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-left text-xs text-slate-700 hover:border-slate-300 hover:bg-slate-50"
                            @click="send(suggestion)"
                        >
                            {{ suggestion }}
                        </button>
                    </div>
                </div>

                <div
                    v-for="(msg, index) in messages"
                    :key="index"
                    class="flex"
                    :class="msg.role === 'user' ? 'justify-end' : 'justify-start'"
                >
                    <div
                        class="max-w-[95%] rounded-2xl px-3 py-2 text-sm shadow-sm"
                        :class="msg.role === 'user' ? 'bg-slate-900 text-white' : 'bg-white text-slate-700'"
                    >
                        <div v-if="msg.role === 'assistant'" class="leading-relaxed" v-html="formatText(msg.text)" />
                        <div v-else class="whitespace-pre-wrap leading-relaxed">{{ msg.text }}</div>

                        <div v-if="assistantItems(msg.items).length" class="mt-2 space-y-2">
                            <component
                                :is="item.url ? Link : 'div'"
                                v-for="(item, itemIndex) in assistantItems(msg.items)"
                                :key="`${item.title}-${itemIndex}`"
                                v-bind="item.url ? { href: item.url } : {}"
                                class="block rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 transition"
                                :class="item.url ? 'hover:border-slate-300 hover:bg-slate-100' : ''"
                                @click="item.url ? (open = false) : null"
                            >
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-semibold text-slate-900">{{ item.title }}</p>
                                        <p v-if="item.subtitle" class="mt-0.5 truncate text-xs text-slate-600">{{ item.subtitle }}</p>
                                    </div>
                                    <p v-if="item.amount" class="shrink-0 text-sm font-semibold text-slate-900">{{ item.amount }}</p>
                                </div>
                                <div v-if="item.date || item.meta" class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-slate-500">
                                    <span v-if="item.date">{{ item.date }}</span>
                                    <span v-if="item.date && item.meta">·</span>
                                    <span v-if="item.meta">{{ item.meta }}</span>
                                </div>
                            </component>
                        </div>

                        <div v-if="assistantLinks(msg.links).length" class="mt-2 flex flex-wrap gap-2 border-t border-slate-100 pt-2">
                            <Link
                                v-for="link in assistantLinks(msg.links)"
                                :key="link.url"
                                :href="link.url"
                                class="rounded-lg bg-slate-100 px-2 py-1 text-xs font-medium text-slate-800 hover:bg-slate-200"
                                @click="closeAssistant"
                            >
                                {{ link.label }}
                            </Link>
                        </div>

                        <div v-if="msg.suggestions?.length" class="mt-2 flex flex-wrap gap-1.5">
                            <button
                                v-for="suggestion in msg.suggestions.slice(0, 3)"
                                :key="suggestion"
                                type="button"
                                class="rounded-full border border-slate-200 px-2 py-1 text-[11px] text-slate-600 hover:bg-slate-50"
                                @click="send(suggestion)"
                            >
                                {{ suggestion }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="loading" class="flex justify-start">
                    <div class="rounded-2xl bg-white px-3 py-2 text-xs text-slate-500 shadow-sm">Looking up ERP records…</div>
                </div>
                <p v-if="error" class="text-center text-xs text-rose-600">{{ error }}</p>
            </div>

            <form class="border-t border-slate-100 bg-white p-3" @submit.prevent="send()">
                <div class="flex items-end gap-2">
                    <textarea
                        v-model="input"
                        rows="2"
                        class="max-h-24 min-h-[2.75rem] flex-1 resize-none rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-slate-400"
                        placeholder="Ask about payables, PRs, stock, how-tos…"
                        :disabled="loading"
                        @keydown="onKeydown"
                    />
                    <button
                        type="submit"
                        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white disabled:opacity-50"
                        :disabled="loading || !input.trim()"
                        aria-label="Send"
                    >
                        <Send class="h-4 w-4" />
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
