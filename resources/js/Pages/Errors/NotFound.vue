<script setup>
import { Head, Link } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';

defineProps({
    homeUrl: { type: String, default: '/' },
});

const space = ref(null);
const stars = ref([]);
let moveHandler = null;

onMounted(() => {
    const count = window.innerWidth < 700 ? 36 : 70;
    stars.value = Array.from({ length: count }, (_, i) => ({
        id: i,
        left: `${(Math.random() * 100).toFixed(2)}%`,
        top: `${(Math.random() * 100).toFixed(2)}%`,
        delay: `${(Math.random() * 2.8).toFixed(2)}s`,
        duration: `${(2 + Math.random() * 2.5).toFixed(2)}s`,
        spark: i % 9 === 0,
    }));

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !space.value) {
        return;
    }

    const el = space.value;
    const layers = el.querySelectorAll('[data-depth]');
    let targetX = 0;
    let targetY = 0;
    let currentX = 0;
    let currentY = 0;
    let ticking = false;

    function render() {
        currentX += (targetX - currentX) * 0.08;
        currentY += (targetY - currentY) * 0.08;
        layers.forEach((layer) => {
            const depth = parseFloat(layer.getAttribute('data-depth') || '0');
            const x = currentX * depth * -120;
            const y = currentY * depth * -90;
            layer.style.transform = `translate3d(${x.toFixed(2)}px, ${y.toFixed(2)}px, 0)`;
        });
        if (Math.abs(targetX - currentX) > 0.001 || Math.abs(targetY - currentY) > 0.001) {
            requestAnimationFrame(render);
        } else {
            ticking = false;
        }
    }

    moveHandler = (event) => {
        const rect = el.getBoundingClientRect();
        targetX = (event.clientX - rect.left) / rect.width - 0.5;
        targetY = (event.clientY - rect.top) / rect.height - 0.5;
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(render);
        }
    };

    el.addEventListener('pointermove', moveHandler, { passive: true });
});

onUnmounted(() => {
    if (space.value && moveHandler) {
        space.value.removeEventListener('pointermove', moveHandler);
    }
});
</script>

<template>
    <Head title="404 — Page Not Found" />

    <div ref="space" class="space">
        <div class="bg-layer" data-depth="0.015" aria-hidden="true">
            <div class="nebula nebula-1" />
            <div class="nebula nebula-2" />
            <div class="nebula nebula-3" />
            <div class="orbit orbit-a" />
            <div class="orbit orbit-b" />
            <div class="orbit orbit-c" />
            <div class="stars">
                <span
                    v-for="star in stars"
                    :key="star.id"
                    class="star"
                    :class="{ spark: star.spark }"
                    :style="{ left: star.left, top: star.top, animationDelay: star.delay, animationDuration: star.duration }"
                />
            </div>
        </div>

        <div class="scene">
            <div class="astronaut-layer" data-depth="0.06" aria-hidden="true">
                <div class="astronaut">
                    <svg class="tether" viewBox="0 0 120 160" fill="none">
                        <path d="M110 20 C70 40, 40 70, 28 110 C20 140, 18 155, 12 158" stroke="rgba(255,255,255,0.75)" stroke-width="4" stroke-linecap="round" />
                    </svg>
                    <svg viewBox="0 0 240 300" xmlns="http://www.w3.org/2000/svg">
                        <defs>
                            <radialGradient id="visorGlow" cx="45%" cy="40%" r="60%">
                                <stop offset="0%" stop-color="#e0f2fe" />
                                <stop offset="55%" stop-color="#7dd3fc" />
                                <stop offset="100%" stop-color="#38bdf8" />
                            </radialGradient>
                            <linearGradient id="suit" x1="0" y1="0" x2="1" y2="1">
                                <stop offset="0%" stop-color="#f8fafc" />
                                <stop offset="100%" stop-color="#e2e8f0" />
                            </linearGradient>
                        </defs>
                        <ellipse cx="92" cy="262" rx="22" ry="12" fill="#94a3b8" />
                        <ellipse cx="148" cy="268" rx="22" ry="12" fill="#94a3b8" />
                        <rect x="78" y="188" width="30" height="72" rx="14" fill="url(#suit)" transform="rotate(8 93 224)" />
                        <rect x="132" y="192" width="30" height="74" rx="14" fill="url(#suit)" transform="rotate(-6 147 229)" />
                        <rect x="82" y="230" width="24" height="22" rx="8" fill="#c4b5fd" transform="rotate(8 94 241)" />
                        <rect x="136" y="236" width="24" height="22" rx="8" fill="#c4b5fd" transform="rotate(-6 148 247)" />
                        <rect x="78" y="118" width="84" height="90" rx="28" fill="url(#suit)" />
                        <rect x="96" y="132" width="48" height="42" rx="12" fill="#c4b5fd" />
                        <circle cx="120" cy="148" r="6" fill="#a78bfa" />
                        <circle cx="120" cy="162" r="4" fill="#ddd6fe" />
                        <rect x="42" y="128" width="42" height="24" rx="12" fill="url(#suit)" transform="rotate(-28 63 140)" />
                        <rect x="156" y="132" width="44" height="24" rx="12" fill="url(#suit)" transform="rotate(34 178 144)" />
                        <circle cx="48" cy="148" r="12" fill="#e2e8f0" />
                        <circle cx="196" cy="154" r="12" fill="#e2e8f0" />
                        <circle cx="120" cy="78" r="48" fill="#f8fafc" stroke="#e2e8f0" stroke-width="4" />
                        <circle cx="120" cy="78" r="36" fill="url(#visorGlow)" opacity="0.95" />
                        <ellipse cx="108" cy="70" rx="10" ry="6" fill="rgba(255,255,255,0.55)" />
                        <circle cx="110" cy="78" r="2.2" fill="#0f172a" opacity="0.55" />
                        <circle cx="130" cy="78" r="2.2" fill="#0f172a" opacity="0.55" />
                        <path d="M112 90 Q120 96 128 90" stroke="#0f172a" stroke-width="2" fill="none" opacity="0.4" stroke-linecap="round" />
                    </svg>
                </div>
            </div>

            <section class="message">
                <div class="code" aria-hidden="true">
                    <span class="digit">4</span>
                    <span class="moon" title="0">
                        <span class="crater c1" />
                        <span class="crater c2" />
                        <span class="crater c3" />
                        <span class="crater c4" />
                    </span>
                    <span class="digit">4</span>
                </div>
                <p>It seems like the page that you are looking for is no longer here.</p>
                <Link :href="homeUrl" class="btn">Take Me Home</Link>
            </section>
        </div>
    </div>
</template>

<style scoped>
.space {
    position: relative;
    width: 100%;
    min-height: 100vh;
    min-height: 100dvh;
    overflow: hidden;
    display: grid;
    place-items: center;
    padding: clamp(1rem, 4vw, 2.5rem);
    background: #070b14;
    color: #fff;
    font-family: Inter, system-ui, -apple-system, sans-serif;
}

.bg-layer {
    position: absolute;
    inset: -8%;
    pointer-events: none;
    z-index: 0;
}

.nebula {
    position: absolute;
    border-radius: 50%;
    filter: blur(50px);
    opacity: 0.7;
    animation: nebulaDrift 18s ease-in-out infinite;
}
.nebula-1 {
    width: 48vmax;
    height: 48vmax;
    top: -18%;
    right: -12%;
    background: radial-gradient(circle, rgba(139, 92, 246, 0.65) 0%, rgba(76, 29, 149, 0.25) 45%, transparent 70%);
}
.nebula-2 {
    width: 42vmax;
    height: 42vmax;
    bottom: -20%;
    left: -14%;
    background: radial-gradient(circle, rgba(45, 212, 191, 0.45) 0%, rgba(15, 118, 110, 0.2) 50%, transparent 72%);
    animation-delay: -6s;
}
.nebula-3 {
    width: 28vmax;
    height: 28vmax;
    top: 35%;
    left: 40%;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.35) 0%, transparent 70%);
    animation-delay: -11s;
}

.orbit {
    position: absolute;
    border: 1px solid rgba(167, 139, 250, 0.35);
    border-radius: 50%;
    animation: orbitSpin 40s linear infinite;
}
.orbit::before {
    content: '';
    position: absolute;
    inset: 12px;
    border: 1px solid rgba(94, 234, 212, 0.28);
    border-radius: 50%;
}
.orbit-a { width: 160px; height: 100px; top: 8%; left: 6%; }
.orbit-b { width: 140px; height: 90px; top: 10%; right: 7%; animation-direction: reverse; animation-duration: 48s; }
.orbit-c { width: 120px; height: 78px; bottom: 12%; right: 14%; animation-duration: 55s; }

.stars { position: absolute; inset: 0; overflow: hidden; }
.star {
    position: absolute;
    width: 2px;
    height: 2px;
    border-radius: 50%;
    background: #fff;
    animation: twinkle 2.8s ease-in-out infinite;
}
.star.spark {
    width: 10px;
    height: 10px;
    border-radius: 0;
    clip-path: polygon(50% 0%, 61% 35%, 98% 35%, 68% 57%, 79% 91%, 50% 70%, 21% 91%, 32% 57%, 2% 35%, 39% 35%);
}

.scene {
    position: relative;
    z-index: 2;
    width: min(100%, 980px);
    display: grid;
    grid-template-columns: minmax(0, 0.95fr) minmax(0, 1.05fr);
    align-items: center;
    gap: clamp(1rem, 4vw, 2.5rem);
}

.astronaut-layer { display: flex; justify-content: center; }
.astronaut {
    width: min(100%, 320px);
    position: relative;
    animation: floatAstronaut 6.5s ease-in-out infinite;
    filter: drop-shadow(0 0 28px rgba(125, 211, 252, 0.35));
}
.astronaut svg { display: block; width: 100%; height: auto; }
.tether {
    position: absolute;
    left: -18%;
    bottom: 8%;
    width: 42%;
    height: 55%;
    pointer-events: none;
}

.message { text-align: center; position: relative; z-index: 5; }
.code {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.2rem;
    font-size: clamp(4.75rem, 13vw, 8rem);
    font-weight: 800;
    letter-spacing: -0.045em;
    line-height: 1;
}
.moon {
    position: relative;
    width: clamp(4.75rem, 13vw, 7.75rem);
    height: clamp(4.75rem, 13vw, 7.75rem);
    border-radius: 50%;
    background: radial-gradient(circle at 30% 28%, #ffffff 0%, #f1f5f9 32%, #cbd5e1 68%, #94a3b8 100%);
    box-shadow: inset -14px -10px 22px rgba(15, 23, 42, 0.22), 0 0 48px rgba(255, 255, 255, 0.28);
    animation: moonPulse 5s ease-in-out infinite;
    flex-shrink: 0;
}
.crater {
    position: absolute;
    border-radius: 50%;
    background: rgba(100, 116, 139, 0.28);
}
.c1 { width: 18%; height: 18%; top: 26%; left: 22%; }
.c2 { width: 11%; height: 11%; top: 48%; left: 52%; }
.c3 { width: 14%; height: 14%; top: 58%; left: 28%; }
.c4 { width: 8%; height: 8%; top: 34%; left: 58%; }

.message p {
    margin: 1.35rem auto 0;
    max-width: 22rem;
    font-size: clamp(0.98rem, 2.3vw, 1.15rem);
    line-height: 1.55;
    color: rgba(255, 255, 255, 0.92);
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-top: 1.85rem;
    min-width: 12.5rem;
    height: 3.1rem;
    padding: 0 1.85rem;
    border-radius: 999px;
    background: linear-gradient(90deg, #2dd4bf, #5eead4);
    color: #0f172a;
    font-size: 0.92rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-decoration: none;
    text-transform: uppercase;
    box-shadow: 0 12px 32px rgba(45, 212, 191, 0.4);
    transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
}
.btn:hover {
    transform: translateY(-3px) scale(1.05);
    box-shadow: 0 18px 40px rgba(94, 234, 212, 0.55);
    filter: brightness(1.08);
}

@keyframes floatAstronaut {
    0%, 100% { transform: translate3d(0, 0, 0) rotate(-6deg); }
    50% { transform: translate3d(10px, -22px, 0) rotate(4deg); }
}
@keyframes moonPulse {
    0%, 100% { transform: scale(1); }
    50% { transform: scale(1.05); }
}
@keyframes twinkle {
    0%, 100% { opacity: 0.15; transform: scale(0.8); }
    50% { opacity: 1; transform: scale(1.2); }
}
@keyframes nebulaDrift {
    0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
    50% { transform: translate3d(-24px, 18px, 0) scale(1.08); }
}
@keyframes orbitSpin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

@media (max-width: 800px) {
    .scene { grid-template-columns: 1fr; justify-items: center; }
    .astronaut { width: min(70%, 240px); }
}

@media (prefers-reduced-motion: reduce) {
    .astronaut, .moon, .nebula, .orbit, .star { animation: none !important; }
}
</style>
