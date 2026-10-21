<script setup>
import { computed, inject, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const sidebarOpen = inject('sidebarOpen', ref(false));

const visible = ref(false);
const stepIndex = ref(0);
const hole = ref(null);
const tooltipStyle = ref({
    left: '50%',
    top: '30%',
    transform: 'translate(-50%, 0)',
});
const missingTarget = ref(false);

const tutorial = computed(() => page.props.menu_tutorial ?? null);

const steps = computed(() => {
    const menus = tutorial.value?.menus ?? [];

    return menus.flatMap((group) => (group.items || []).map((item) => ({
        group: group.group,
        label: item.label,
        target: item.target || null,
        hint: item.hint || '',
        action: item.action || item.hint || `Click ${item.label}.`,
    })));
});

const step = computed(() => steps.value[stepIndex.value] ?? null);
const isLast = computed(() => steps.value.length > 0 && stepIndex.value >= steps.value.length - 1);
const progress = computed(() => `${stepIndex.value + 1} / ${steps.value.length}`);

function storageKey() {
    const id = page.props.auth?.user?.id;

    return id ? `buildify360.menu-tutorial.${id}` : '';
}

function stepStorageKey() {
    const key = storageKey();

    return key ? `${key}.step` : '';
}

function lockScroll(lock) {
    if (typeof document === 'undefined') {
        return;
    }
    document.body.style.overflow = lock ? 'hidden' : '';
}

function finish() {
    const key = storageKey();
    if (key) {
        localStorage.setItem(key, 'done');
        sessionStorage.removeItem(stepStorageKey());
    }
    visible.value = false;
    hole.value = null;
    lockScroll(false);
}

function skip() {
    finish();
}

function next() {
    if (isLast.value) {
        finish();
        return;
    }
    stepIndex.value += 1;
}

function back() {
    if (stepIndex.value > 0) {
        stepIndex.value -= 1;
    }
}

function persistStep() {
    const key = stepStorageKey();
    if (key && visible.value) {
        sessionStorage.setItem(key, String(stepIndex.value));
    }
}

function findTarget(target) {
    if (!target || typeof document === 'undefined') {
        return null;
    }

    return document.querySelector(`[data-tour="${target}"]`);
}

function place() {
    if (!visible.value || !step.value) {
        return;
    }

    sidebarOpen.value = true;

    nextTick(() => {
        window.setTimeout(() => {
            let el = findTarget(step.value.target);
            missingTarget.value = !el;

            if (!el && step.value.group === 'Inside a project') {
                el = findTarget('projects');
            }

            if (!el) {
                hole.value = null;
                tooltipStyle.value = {
                    left: '50%',
                    top: '28%',
                    transform: 'translate(-50%, 0)',
                };
                return;
            }

            el.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'auto' });

            const rect = el.getBoundingClientRect();
            const pad = 6;
            hole.value = {
                top: Math.max(8, rect.top - pad),
                left: Math.max(8, rect.left - pad),
                width: rect.width + pad * 2,
                height: rect.height + pad * 2,
            };

            const tipWidth = Math.min(320, window.innerWidth - 32);
            const tipHeight = 230;
            const gap = 14;
            let left = rect.right + gap;
            let top = rect.top;
            let transform = 'none';

            if (left + tipWidth > window.innerWidth - 16) {
                left = Math.max(16, window.innerWidth - tipWidth - 16);
                top = rect.bottom + gap;
            }

            if (top + tipHeight > window.innerHeight - 12) {
                top = Math.max(12, rect.top - tipHeight - 8);
            }

            if (top < 12) {
                top = 12;
            }

            if (window.innerWidth < 640) {
                left = 16;
                top = window.innerHeight - tipHeight - 16;
                transform = 'none';
            }

            tooltipStyle.value = {
                left: `${left}px`,
                top: `${top}px`,
                transform,
            };
        }, 60);
    });
}

function onKey(event) {
    if (!visible.value) {
        return;
    }
    if (event.key === 'Escape') {
        event.preventDefault();
        skip();
        return;
    }
    if (event.key === 'ArrowRight' || event.key === 'Enter') {
        event.preventDefault();
        next();
        return;
    }
    if (event.key === 'ArrowLeft') {
        event.preventDefault();
        back();
    }
}

watch(stepIndex, () => {
    persistStep();
    place();
});

onMounted(() => {
    if (!tutorial.value || steps.value.length === 0) {
        return;
    }

    const key = storageKey();
    if (key && localStorage.getItem(key) === 'done') {
        return;
    }

    const saved = key ? Number(sessionStorage.getItem(stepStorageKey()) || 0) : 0;
    stepIndex.value = Number.isFinite(saved) ? Math.min(Math.max(saved, 0), steps.value.length - 1) : 0;
    visible.value = true;
    sidebarOpen.value = true;
    lockScroll(true);
    place();
    window.addEventListener('resize', place);
    window.addEventListener('scroll', place, true);
    window.addEventListener('keydown', onKey);
});

onUnmounted(() => {
    lockScroll(false);
    window.removeEventListener('resize', place);
    window.removeEventListener('scroll', place, true);
    window.removeEventListener('keydown', onKey);
});
</script>

<template>
    <Teleport to="body">
        <div v-if="visible && step" class="fixed inset-0 z-[70] overflow-hidden print:hidden" @click.prevent>
            <div
                v-if="hole"
                class="pointer-events-none absolute rounded-xl transition-all duration-300"
                :style="{
                    top: `${hole.top}px`,
                    left: `${hole.left}px`,
                    width: `${hole.width}px`,
                    height: `${hole.height}px`,
                    boxShadow: '0 0 0 9999px rgba(15, 23, 42, 0.58)',
                }"
            >
                <span class="tour-ring" />
                <span class="tour-pulse" />
                <span class="tour-cursor" />
            </div>
            <div v-else class="absolute inset-0 bg-slate-900/55" />

            <div
                class="absolute z-[71] w-[min(20rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl shadow-slate-900/20"
                :style="tooltipStyle"
                @click.stop
            >
                <div class="flex items-center justify-between gap-3">
                    <p class="text-[11px] font-medium uppercase tracking-wide text-slate-400">
                        {{ step.group }}
                    </p>
                    <p class="text-[11px] tabular-nums text-slate-400">{{ progress }}</p>
                </div>
                <h2 class="mt-1 text-sm font-semibold text-slate-900">{{ step.label }}</h2>
                <p class="mt-1.5 text-sm leading-snug text-slate-600">{{ step.action }}</p>
                <p v-if="missingTarget && step.group === 'Inside a project'" class="mt-1 text-xs text-amber-700">
                    Open a project first — then this click appears in the left menu.
                </p>
                <p class="mt-1 text-xs text-slate-500">{{ step.hint }}</p>

                <div class="mt-4 flex items-center justify-between gap-2">
                    <button type="button" class="text-sm font-medium text-slate-500 hover:text-slate-800" @click="skip">
                        Skip
                    </button>
                    <div class="flex items-center gap-2">
                        <button
                            v-if="stepIndex > 0"
                            type="button"
                            class="ui-btn-secondary"
                            @click="back"
                        >
                            Back
                        </button>
                        <button type="button" class="ui-btn-primary" @click="next">
                            {{ isLast ? 'Done' : 'Next' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.tour-ring {
    position: absolute;
    inset: 0;
    border-radius: 0.75rem;
    box-shadow: 0 0 0 3px #38bdf8, 0 0 0 6px rgba(56, 189, 248, 0.28);
}
.tour-pulse {
    position: absolute;
    left: 1.15rem;
    top: 50%;
    width: 1.15rem;
    height: 1.15rem;
    margin-top: -0.575rem;
    border-radius: 9999px;
    background: rgba(14, 165, 233, 0.35);
    animation: tour-pulse 1.35s ease-out infinite;
}
.tour-cursor {
    position: absolute;
    left: 1.35rem;
    top: 50%;
    width: 0.7rem;
    height: 0.7rem;
    margin-top: -0.2rem;
    border-radius: 9999px 9999px 9999px 0.15rem;
    background: #0ea5e9;
    border: 2px solid #fff;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.35);
    transform: rotate(-20deg);
    animation: tour-click 1.35s ease-out infinite;
}
@keyframes tour-pulse {
    0% {
        transform: scale(1);
        opacity: 0.85;
    }
    100% {
        transform: scale(2.6);
        opacity: 0;
    }
}
@keyframes tour-click {
    0%,
    35% {
        transform: rotate(-20deg) scale(1);
    }
    48% {
        transform: rotate(-20deg) scale(0.72);
    }
    70%,
    100% {
        transform: rotate(-20deg) scale(1);
    }
}
</style>
