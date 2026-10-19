<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    homeUrl: { type: String, default: '/' },
});

function goBack(homeUrl) {
    const referrer = document.referrer;
    try {
        if (
            referrer
            && new URL(referrer).origin === window.location.origin
            && referrer !== window.location.href
            && !referrer.includes('/error/')
        ) {
            window.location.href = referrer;
            return;
        }
    } catch (e) {
        // fall through
    }

    if (window.history.length > 1) {
        window.history.back();
        return;
    }

    window.location.href = homeUrl || '/';
}
</script>

<template>
    <Head title="403 — Access Denied" />

    <div class="error-403-page">
        <div class="art" aria-hidden="true">
            <div class="art-inner">
                <img src="/images/errors/403-illustration.png?v=3" alt="" width="1024" height="253" />
            </div>
        </div>

        <section class="copy">
            <h1>We are Sorry...</h1>
            <p>
                The page you’re trying to access has restricted access.<br />
                Please refer to your system administrator.
            </p>
        </section>

        <div class="actions">
            <button type="button" class="btn" @click="goBack(homeUrl)">Go Back</button>
        </div>
    </div>
</template>

<style scoped>
.error-403-page {
    min-height: 100vh;
    min-height: 100dvh;
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: clamp(0.75rem, 2.5vh, 1.5rem);
    padding: clamp(1rem, 4vh, 2.5rem) clamp(1rem, 4vw, 2rem);
    text-align: center;
    background: #fff;
    font-family: Inter, system-ui, -apple-system, sans-serif;
    color: #1e1b4b;
}

.art {
    width: min(92vw, 640px);
    animation: fadeUp 0.7s ease-out both;
}

.art-inner {
    width: 100%;
    animation: floatSoft 5.5s ease-in-out infinite;
}

.art img {
    display: block;
    width: 100%;
    height: auto;
    user-select: none;
    pointer-events: none;
}

.copy {
    max-width: 28rem;
    animation: fadeUp 0.7s ease-out 0.12s both;
}

.copy h1 {
    font-size: clamp(1.7rem, 4.2vw, 2.35rem);
    font-weight: 800;
    letter-spacing: -0.02em;
}

.copy p {
    margin-top: 0.75rem;
    color: #64748b;
    font-size: clamp(0.95rem, 2.2vw, 1.05rem);
    line-height: 1.55;
}

.actions {
    position: relative;
    z-index: 20;
    animation: fadeUp 0.7s ease-out 0.24s both;
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 11rem;
    height: 3.1rem;
    padding: 0 1.85rem;
    border: 0;
    border-radius: 0.9rem;
    background: linear-gradient(180deg, #38bdf8, #0ea5e9);
    color: #fff;
    font: inherit;
    font-size: 1.05rem;
    font-weight: 700;
    cursor: pointer;
    box-shadow: 0 10px 28px rgba(14, 165, 233, 0.35);
    transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
}

.btn:hover {
    transform: translateY(-2px) scale(1.03);
    box-shadow: 0 14px 32px rgba(14, 165, 233, 0.48);
    filter: brightness(1.05);
}

.btn:active {
    transform: translateY(0) scale(0.98);
}

@keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes floatSoft {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-8px); }
}

@media (prefers-reduced-motion: reduce) {
    .art,
    .art-inner,
    .copy,
    .actions {
        animation: none !important;
    }
}
</style>
