<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecurityNotice from '@/Components/SecurityNotice.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const company = computed(() => usePage().props.company ?? null);
const companyName = computed(() => company.value?.company_name || 'HSB Projects');
const logoBroken = ref(false);
const showLogo = computed(() => Boolean(company.value?.logo_url) && !logoBroken.value);

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const reducedMotion = typeof window !== 'undefined'
    && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const hasErrors = Object.keys(form.errors).length > 0;

const phase = ref(reducedMotion || hasErrors ? 'ready' : 'start');
const motion = ref(reducedMotion ? 'reduced' : 'desktop');

let timers = [];
let reduceQuery;
let wideQuery;

watch(() => company.value?.logo_url, () => {
    logoBroken.value = false;
});

watch(() => form.errors, (errors) => {
    if (Object.keys(errors).length > 0) {
        phase.value = 'ready';
    }
}, { deep: true });

function clearTimers() {
    timers.forEach((timer) => clearTimeout(timer));
    timers = [];
}

function syncMotion() {
    const reduced = reduceQuery?.matches === true;
    const wide = wideQuery?.matches === true;

    motion.value = reduced ? 'reduced' : (wide ? 'desktop' : 'compact');

    if (reduced) {
        clearTimers();
        phase.value = 'ready';
    }
}

onMounted(() => {
    reduceQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    wideQuery = window.matchMedia('(min-width: 1024px)');
    reduceQuery.addEventListener('change', syncMotion);
    wideQuery.addEventListener('change', syncMotion);
    syncMotion();

    if (phase.value === 'ready') {
        return;
    }

    requestAnimationFrame(() => {
        if (phase.value === 'start') {
            phase.value = 'intro';
        }
    });

    if (motion.value !== 'desktop') {
        timers.push(window.setTimeout(() => {
            phase.value = 'ready';
        }, 800));

        return;
    }

    timers.push(window.setTimeout(() => {
        if (phase.value !== 'ready') {
            phase.value = 'moving';
        }
    }, 1300));

    timers.push(window.setTimeout(() => {
        phase.value = 'ready';
    }, 1700));
});

onBeforeUnmount(() => {
    clearTimers();
    reduceQuery?.removeEventListener('change', syncMotion);
    wideQuery?.removeEventListener('change', syncMotion);
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <div class="login-screen" :data-phase="phase" :data-motion="motion">
        <Head title="Log in" />

        <div class="login-bg" aria-hidden="true">
            <span class="login-orb login-orb-a"></span>
            <span class="login-orb login-orb-b"></span>
        </div>

        <div class="login-stage">
            <div class="brand-column">
                <div class="lockup" :class="{ 'is-fallback': !showLogo }">
                    <img
                        v-if="showLogo"
                        :src="company.logo_url"
                        alt=""
                        class="logo-img"
                        @error="logoBroken = true"
                    >
                    <p v-else class="fallback-name">{{ companyName }}</p>
                </div>
            </div>

            <div class="form-panel">
                <div class="login-card">
                    <h1>Welcome Back</h1>

                    <div v-if="status" class="mb-4 text-sm font-medium text-green-600">
                        {{ status }}
                    </div>

                    <SecurityNotice />

                    <form @submit.prevent="submit">
                        <div>
                            <InputLabel for="email" value="Email" />
                            <TextInput
                                id="email"
                                type="email"
                                class="mt-1 block w-full"
                                v-model="form.email"
                                required
                                autofocus
                                autocomplete="username"
                            />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <div class="mt-4">
                            <InputLabel for="password" value="Password" />
                            <TextInput
                                id="password"
                                type="password"
                                class="mt-1 block w-full"
                                v-model="form.password"
                                required
                                autocomplete="current-password"
                            />
                            <InputError class="mt-2" :message="form.errors.password" />
                        </div>

                        <div class="mt-4 block">
                            <label class="flex items-center">
                                <Checkbox name="remember" v-model:checked="form.remember" />
                                <span class="ms-2 text-sm text-slate-600">Remember me</span>
                            </label>
                        </div>

                        <PrimaryButton class="sign-in" :disabled="form.processing">
                            {{ form.processing ? 'Signing in…' : 'Sign in' }}
                        </PrimaryButton>
                    </form>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.login-screen {
    position: relative;
    min-height: 100vh;
    overflow: hidden;
    background: #F3F4F6;
    color: #1e293b;
}

.login-bg {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.login-orb {
    position: absolute;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.72);
}

.login-orb-a {
    top: -7rem;
    left: -6rem;
    width: 18rem;
    height: 18rem;
}

.login-orb-b {
    right: -4rem;
    bottom: -8rem;
    width: 20rem;
    height: 20rem;
}

.login-stage {
    position: relative;
    display: grid;
    min-height: 100vh;
    align-items: center;
    padding: 2.5rem 1.25rem;
}

.brand-column {
    display: flex;
    justify-content: center;
}

.lockup {
    position: relative;
    width: min(280px, 78vw);
    opacity: 0;
    text-align: center;
}

.logo-img {
    display: block;
    width: 100%;
    max-height: 150px;
    object-fit: contain;
}

.fallback-name {
    font-size: 1.25rem;
    font-weight: 600;
    line-height: 1.3;
    color: #0f172a;
}

.form-panel {
    display: flex;
    justify-content: center;
    opacity: 0;
    transform: translateY(12px);
    pointer-events: none;
}

.login-card {
    width: 100%;
    max-width: 460px;
    border: 1px solid rgba(226, 232, 240, 0.95);
    border-radius: 1.25rem;
    background: #fff;
    padding: 2rem 1.75rem;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04);
}

.login-card h1 {
    margin-bottom: 1.5rem;
    font-size: 1.5rem;
    font-weight: 600;
    color: #0f172a;
}

.sign-in {
    width: 100%;
    height: 2.75rem;
    margin-top: 1.25rem;
    justify-content: center;
}

.login-screen[data-phase="intro"] .lockup,
.login-screen[data-phase="moving"] .lockup,
.login-screen[data-phase="ready"] .lockup {
    opacity: 1;
    transition: opacity 600ms ease;
}

.login-screen[data-phase="ready"] .form-panel {
    opacity: 1;
    transform: none;
    pointer-events: auto;
    transition: opacity 600ms ease, transform 600ms cubic-bezier(0.22, 1, 0.36, 1);
}

@media (min-width: 1024px) {
    .login-stage {
        grid-template-columns: 45% 55%;
        padding: 0;
    }

    .login-screen[data-motion="desktop"] .lockup {
        position: fixed;
        top: 50%;
        left: 50%;
        width: 280px;
        opacity: 0;
        transform: translate(-50%, -46%) scale(0.85);
        transition: opacity 600ms ease, transform 600ms cubic-bezier(0.22, 1, 0.36, 1);
    }

    .login-screen[data-motion="desktop"][data-phase="intro"] .lockup {
        opacity: 1;
        transform: translate(-50%, -50%) scale(1);
    }

    .login-screen[data-motion="desktop"][data-phase="moving"] .lockup,
    .login-screen[data-motion="desktop"][data-phase="ready"] .lockup {
        opacity: 1;
        transform: translate(calc(-50% - 27.5vw), -50%) scale(1);
        transition: transform 800ms cubic-bezier(0.22, 0.8, 0.24, 1), opacity 600ms ease;
    }

    .login-screen[data-motion="desktop"] .form-panel {
        transform: translateX(30px) scale(0.98);
        justify-content: flex-start;
        padding-left: 6%;
    }

    .login-screen[data-motion="desktop"][data-phase="ready"] .form-panel {
        transform: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .lockup,
    .form-panel {
        opacity: 1 !important;
        transform: none !important;
        transition: none !important;
        position: relative !important;
        left: auto !important;
        top: auto !important;
        pointer-events: auto !important;
    }
}
</style>
