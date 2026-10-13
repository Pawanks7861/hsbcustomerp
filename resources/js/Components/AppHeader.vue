<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { LogOut, Menu } from '@lucide/vue';

defineProps({
    user: { type: Object, default: null },
});

const emit = defineEmits(['open-sidebar']);
const open = ref(false);
const root = ref(null);

function initials(name) {
    return String(name || '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function onDocumentClick(event) {
    if (root.value && !root.value.contains(event.target)) {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));
</script>

<template>
    <header class="flex items-center gap-4 border-b border-slate-100 px-4 py-4 sm:px-6 print:hidden">
        <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-50 lg:hidden" @click="emit('open-sidebar')">
            <Menu class="h-5 w-5" />
        </button>
        <div class="min-w-0 flex-1">
            <slot />
        </div>
        <div v-if="user" ref="root" class="relative shrink-0">
            <button type="button" class="flex items-center gap-2 rounded-full py-1 pl-1 pr-2 hover:bg-slate-50" @click="open = !open">
                <span class="flex h-8 w-8 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold text-slate-700">
                    {{ initials(user.name) }}
                </span>
                <span class="hidden text-sm font-medium text-slate-700 sm:inline">{{ user.name }}</span>
            </button>
            <div v-if="open" class="absolute right-0 z-20 mt-2 w-44 rounded-xl border border-slate-200 bg-white p-1 shadow-lg">
                <Link href="/profile" class="menu-item" @click="open = false">Profile</Link>
                <Link href="/logout" method="post" as="button" class="menu-item">
                    <LogOut class="mr-2 h-4 w-4" />
                    Logout
                </Link>
            </div>
        </div>
    </header>
</template>
