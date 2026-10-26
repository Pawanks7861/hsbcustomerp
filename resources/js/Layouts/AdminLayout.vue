<script setup>
import { computed, onBeforeUnmount, onMounted, provide, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import AppHeader from '../Components/AppHeader.vue';
import AppSidebar from '../Components/AppSidebar.vue';
import SecurityNotice from '../Components/SecurityNotice.vue';
import { lazyComponent, whenIdle } from '../lib/lazyComponent';

const ErpAssistant = lazyComponent(() => import('../Components/ErpAssistant.vue'), { skeleton: false, silent: true });
const MenuTutorial = lazyComponent(() => import('../Components/MenuTutorial.vue'), { skeleton: false, silent: true });

defineProps({
    project: {
        type: Object,
        default: null,
    },
});

const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const success = computed(() => page.props.flash?.success ?? null);
const error = computed(() => page.props.flash?.error ?? null);
const user = computed(() => page.props.auth?.user ?? null);
const company = computed(() => page.props.company ?? null);
const sidebarOpen = ref(false);
provide('sidebarOpen', sidebarOpen);

const tutorialPending = computed(() => Boolean(page.props.menu_tutorial));
const assistantReady = ref(false);
let cancelIdle = () => {};

onMounted(() => {
    cancelIdle = whenIdle(() => {
        assistantReady.value = true;
    });
});

onBeforeUnmount(() => cancelIdle());
</script>

<template>
    <div class="min-h-screen bg-canvas text-slate-800">
        <Head>
            <link v-if="company?.favicon_url" rel="icon" :href="company.favicon_url" head-key="favicon">
        </Head>
        <AppSidebar
            :project="project"
            :company="company"
            :user="user"
            :path="path"
            :open="sidebarOpen"
            @close="sidebarOpen = false"
        />
        <div class="min-h-screen p-3 lg:pl-[17.25rem] lg:pr-4">
            <div class="flex min-h-[calc(100vh-1.5rem)] flex-col rounded-[24px] border border-white/70 bg-white/80 lg:min-h-[calc(100vh-1.5rem)]">
                <AppHeader :user="user" @open-sidebar="sidebarOpen = true">
                    <slot name="header" />
                </AppHeader>
                <main class="flex-1 px-4 py-5 sm:px-6 lg:px-8">
                    <SecurityNotice />
                    <div v-if="success" class="mb-4 rounded-xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 print:hidden">
                        {{ success }}
                    </div>
                    <div v-if="error" class="mb-4 rounded-xl border border-red-100 bg-red-50 px-4 py-3 text-sm text-red-700 print:hidden">
                        {{ error }}
                    </div>
                    <slot />
                </main>
                <footer class="border-t border-slate-100 px-4 py-3 text-center text-xs text-slate-400 sm:px-6 print:hidden">
                    Powered by
                    <a
                        href="https://buildify360.com/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="font-semibold text-slate-600 hover:text-slate-900 hover:underline"
                        aria-label="Buildify360 (opens in a new tab)"
                    >Buildify360</a>
                </footer>
            </div>
        </div>
        <MenuTutorial v-if="tutorialPending" />
        <ErpAssistant v-if="assistantReady" />
    </div>
</template>
