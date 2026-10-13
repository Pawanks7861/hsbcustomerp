<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { MoreHorizontal } from '@lucide/vue';

const open = ref(false);
const root = ref(null);
const menu = ref(null);
const menuStyle = ref({});

function place() {
    const button = root.value?.querySelector('button');

    if (!button) {
        return;
    }

    const rect = button.getBoundingClientRect();
    const width = 176;
    const height = menu.value?.offsetHeight || 140;
    const left = Math.min(Math.max(8, rect.right - width), window.innerWidth - width - 8);
    let top = rect.bottom + 4;

    if (top + height > window.innerHeight - 8) {
        top = Math.max(8, rect.top - height - 4);
    }

    menuStyle.value = {
        position: 'fixed',
        top: `${top}px`,
        left: `${left}px`,
        zIndex: '50',
    };
}

function toggle() {
    if (open.value) {
        open.value = false;

        return;
    }

    place();
    open.value = true;
    nextTick(place);
}

function onDocumentClick(event) {
    if (!open.value) {
        return;
    }

    const target = event.target;

    if (root.value?.contains(target) || menu.value?.contains(target)) {
        return;
    }

    open.value = false;
}

function onMove() {
    if (open.value) {
        place();
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    window.addEventListener('scroll', onMove, true);
    window.addEventListener('resize', onMove);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    window.removeEventListener('scroll', onMove, true);
    window.removeEventListener('resize', onMove);
});
</script>

<template>
    <div ref="root" class="inline-block text-left">
        <button
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100"
            aria-label="Actions"
            @click.stop="toggle"
        >
            <MoreHorizontal class="h-4 w-4" />
        </button>
        <Teleport to="body">
            <div
                v-if="open"
                ref="menu"
                class="w-44 rounded-xl border border-slate-200 bg-white p-1 shadow-lg"
                :style="menuStyle"
                @click="open = false"
            >
                <slot />
            </div>
        </Teleport>
    </div>
</template>
