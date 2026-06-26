<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';

const props = defineProps({
    initialState: { type: Object, required: true },
});

const state = reactive({
    batchId: props.initialState.batchId ?? null,
    stats: props.initialState.stats,
    jobs: props.initialState.jobs ?? [],
    recentBatches: props.initialState.recentBatches ?? [],
});

// Expose the same names the original Inertia template bound to.
const batchId = computed(() => state.batchId);
const stats = computed(() => state.stats);
const jobs = computed(() => state.jobs);
const recentBatches = computed(() => state.recentBatches);

const form = reactive({
    count: 100,
    min_duration: 200,
    max_duration: 2000,
    queue: 'default',
    fail_chance: 0,
});

const queueMeta = [
    { name: 'default', dot: 'bg-blue-400', text: 'text-blue-400' },
    { name: 'processing', dot: 'bg-violet-400', text: 'text-violet-400' },
    { name: 'critical', dot: 'bg-rose-400', text: 'text-rose-400' },
];

const dispatching = ref(false);
const settingsOpen = ref(false);
let pollTimeout = null;
let pollActive = false;

// A job is "settled" once it has either completed or failed; both count toward
// the batch being done so failures don't leave the dashboard polling forever.
const settled = computed(() => (state.stats.completed ?? 0) + (state.stats.failed ?? 0));
const isActive = computed(() => state.batchId && state.stats.total > 0 && settled.value < state.stats.total);
const progress = computed(() => (state.stats.total > 0 ? (settled.value / state.stats.total) * 100 : 0));

const timelineMax = computed(() => {
    if (state.jobs.length === 0) return 1000;
    const maxEnd = Math.max(...state.jobs.filter((j) => j.endMs).map((j) => j.endMs), 0);
    const maxWait = Math.max(...state.jobs.filter((j) => j.waitMs).map((j) => j.waitMs), 0);
    return Math.max(maxEnd, maxWait, 1000);
});

const workerColors = computed(() => {
    const workers = [...new Set(state.jobs.map((j) => j.worker).filter(Boolean))];
    const palette = [
        '#3b82f6', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981',
        '#06b6d4', '#f97316', '#6366f1', '#14b8a6', '#e11d48',
        '#84cc16', '#a855f7', '#0ea5e9', '#d946ef', '#22c55e',
    ];
    const map = {};
    workers.forEach((w, i) => (map[w] = palette[i % palette.length]));
    return map;
});

async function fetchState() {
    const url = state.batchId ? `/api/state?batch=${encodeURIComponent(state.batchId)}` : '/api/state';
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    const data = await response.json();
    state.stats = data.stats;
    state.jobs = data.jobs;
    state.recentBatches = data.recentBatches;
}

async function submit() {
    dispatching.value = true;
    stopPolling();
    try {
        const response = await fetch('/api/dispatch', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(form),
        });
        const data = await response.json();
        state.batchId = data.batchId;
        syncUrl();
        await fetchState();
    } finally {
        dispatching.value = false;
        startPolling();
    }
}

function poll() {
    if (!pollActive) return;
    pollTimeout = setTimeout(async () => {
        if (!pollActive) return;
        await fetchState();
        if (!pollActive) return;
        if (state.stats.total > 0 && settled.value >= state.stats.total && state.stats.activeWorkers === 0) {
            stopPolling();
            return;
        }
        poll();
    }, 600);
}

function startPolling() {
    stopPolling();
    pollActive = true;
    poll();
}

function stopPolling() {
    pollActive = false;
    if (pollTimeout) {
        clearTimeout(pollTimeout);
        pollTimeout = null;
    }
}

function syncUrl() {
    const url = state.batchId ? `/?batch=${encodeURIComponent(state.batchId)}` : '/';
    window.history.replaceState({}, '', url);
}

async function selectBatch(id) {
    state.batchId = id;
    syncUrl();
    await fetchState();
    startPolling();
}

async function resetWorkers() {
    settingsOpen.value = false;
    if (!confirm('Reset worker counts? This clears the active workers table — useful when workers get stuck.')) {
        return;
    }
    await fetch('/api/workers/reset', { method: 'POST' });
    await fetchState();
}

async function resetApp() {
    settingsOpen.value = false;
    if (!confirm('Delete all batches and reset the app? This permanently wipes every batch, job, and metric. Worker counts are left untouched.')) {
        return;
    }
    stopPolling();
    await fetch('/api/reset', { method: 'POST' });
    state.batchId = null;
    syncUrl();
    await fetchState();
}

function formatMs(ms) {
    if (ms === null) return '-';
    if (ms < 1000) return `${Math.round(ms)}ms`;
    return `${(ms / 1000).toFixed(2)}s`;
}

onMounted(() => startPolling());
onUnmounted(() => stopPolling());
</script>

<template>
    <div class="min-h-screen bg-zinc-950 p-4 md:p-8">
        <div class="mx-auto max-w-7xl space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="font-mono text-2xl font-bold tracking-tight text-white">
                        Managed Queues
                        <span class="text-zinc-500">Demo</span>
                    </h1>
                    <p class="mt-1 text-sm text-zinc-500">
                        Dispatch jobs and watch Laravel Cloud scale workers to meet demand
                    </p>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-3 rounded-lg border border-zinc-800 bg-zinc-900/50 px-3 py-2">
                        <span class="text-xs font-medium text-zinc-500">Workers</span>
                        <div class="flex items-center gap-3">
                            <span
                                v-for="queue in queueMeta"
                                :key="queue.name"
                                class="flex items-center gap-1.5"
                                :title="`${queue.name} queue`"
                            >
                                <span class="inline-block h-2 w-2 rounded-full" :class="queue.dot" />
                                <span class="font-mono text-[10px] text-zinc-500">{{ queue.name }}</span>
                                <span class="font-mono text-sm font-bold" :class="queue.text">
                                    {{ stats.workersByQueue?.[queue.name] ?? 0 }}
                                </span>
                            </span>
                        </div>
                        <span class="border-l border-zinc-800 pl-3 font-mono text-sm font-bold text-white">
                            {{ stats.activeWorkers }}
                            <span class="text-[10px] font-medium text-zinc-500">total</span>
                        </span>
                    </div>
                    <div v-if="isActive" class="flex items-center gap-2">
                        <span class="relative flex h-3 w-3">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
                            <span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-500" />
                        </span>
                        <span class="text-sm font-medium text-emerald-400">Processing</span>
                    </div>
                    <div class="relative">
                        <button
                            type="button"
                            class="flex h-9 w-9 items-center justify-center rounded-lg border border-zinc-800 bg-zinc-900/50 text-zinc-400 transition hover:bg-zinc-800 hover:text-white"
                            :class="{ 'bg-zinc-800 text-white': settingsOpen }"
                            title="Settings"
                            @click="settingsOpen = !settingsOpen"
                        >
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                            </svg>
                        </button>
                        <div v-if="settingsOpen">
                            <div class="fixed inset-0 z-10" @click="settingsOpen = false" />
                            <div class="absolute right-0 z-20 mt-2 w-72 overflow-hidden rounded-lg border border-zinc-800 bg-zinc-900 p-1 shadow-xl">
                                <button
                                    type="button"
                                    class="flex w-full flex-col gap-0.5 rounded-md px-3 py-2 text-left transition hover:bg-zinc-800"
                                    @click="resetWorkers"
                                >
                                    <span class="text-sm font-medium text-zinc-200">Reset worker counts</span>
                                    <span class="text-xs text-zinc-500">Clears the workers table when counts get stuck</span>
                                </button>
                                <button
                                    type="button"
                                    class="flex w-full flex-col gap-0.5 rounded-md px-3 py-2 text-left transition hover:bg-rose-500/10"
                                    @click="resetApp"
                                >
                                    <span class="text-sm font-medium text-rose-400">Reset app</span>
                                    <span class="text-xs text-zinc-500">Deletes all batches, jobs &amp; metrics (keeps workers)</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
                <form class="flex flex-wrap items-end gap-4" @submit.prevent="submit">
                    <div class="min-w-[120px] flex-1">
                        <label class="mb-1.5 block text-xs font-medium text-zinc-400">Jobs</label>
                        <input
                            v-model.number="form.count"
                            type="number"
                            min="1"
                            max="10000"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 font-mono text-sm text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
                        />
                    </div>
                    <div class="min-w-[120px] flex-1">
                        <label class="mb-1.5 block text-xs font-medium text-zinc-400">Min Duration (ms)</label>
                        <input
                            v-model.number="form.min_duration"
                            type="number"
                            min="0"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 font-mono text-sm text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
                        />
                    </div>
                    <div class="min-w-[120px] flex-1">
                        <label class="mb-1.5 block text-xs font-medium text-zinc-400">Max Duration (ms)</label>
                        <input
                            v-model.number="form.max_duration"
                            type="number"
                            min="0"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 font-mono text-sm text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
                        />
                    </div>
                    <div class="min-w-[120px] flex-1">
                        <label class="mb-1.5 block text-xs font-medium text-zinc-400">Queue</label>
                        <select
                            v-model="form.queue"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 font-mono text-sm text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 focus:outline-none"
                        >
                            <option value="default">default</option>
                            <option value="processing">processing</option>
                            <option value="critical">critical</option>
                        </select>
                    </div>
                    <div class="min-w-[120px] flex-1">
                        <label class="mb-1.5 block text-xs font-medium text-zinc-400">Fail Chance (%)</label>
                        <input
                            v-model.number="form.fail_chance"
                            type="number"
                            min="0"
                            max="100"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 font-mono text-sm text-white focus:border-rose-500 focus:ring-1 focus:ring-rose-500 focus:outline-none"
                        />
                    </div>
                    <button
                        type="submit"
                        :disabled="dispatching"
                        class="rounded-lg bg-blue-600 px-6 py-2 font-mono text-sm font-semibold text-white transition hover:bg-blue-500 disabled:opacity-50"
                    >
                        {{ dispatching ? 'Dispatching...' : 'Dispatch' }}
                    </button>
                </form>
            </div>

            <!-- Stats Grid -->
            <div v-if="stats.total > 0" class="grid grid-cols-2 gap-3 md:grid-cols-5">
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
                    <div class="text-xs font-medium text-zinc-500">Total Jobs</div>
                    <div class="mt-1 font-mono text-2xl font-bold text-white">{{ stats.total }}</div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
                    <div class="text-xs font-medium text-zinc-500">Completed</div>
                    <div class="mt-1 font-mono text-2xl font-bold text-emerald-400">{{ stats.completed }}</div>
                    <div class="mt-1 h-1.5 rounded-full bg-zinc-800">
                        <div
                            class="h-full rounded-full bg-emerald-500 transition-all duration-300"
                            :style="{ width: `${progress}%` }"
                        />
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
                    <div class="text-xs font-medium text-zinc-500">Workers</div>
                    <div class="mt-1 font-mono text-2xl font-bold text-violet-400">{{ stats.peakWorkers }}</div>
                    <div class="mt-1 flex items-center gap-2 text-[10px] text-zinc-500">
                        <span v-if="stats.activeWorkers > 0" class="text-emerald-400">{{ stats.activeWorkers }} active</span>
                        <span>{{ stats.uniqueWorkers }} total</span>
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
                    <div class="text-xs font-medium text-zinc-500">Avg Wait</div>
                    <div class="mt-1 font-mono text-2xl font-bold text-amber-400">{{ formatMs(stats.avgWaitMs) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4">
                    <div class="text-xs font-medium text-zinc-500">Throughput</div>
                    <div class="mt-1 font-mono text-2xl font-bold text-cyan-400">
                        {{ stats.jobsPerSecond ? `${stats.jobsPerSecond}/s` : '-' }}
                    </div>
                </div>
            </div>

            <!-- Secondary Stats -->
            <div v-if="stats.total > 0" class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4 text-center">
                    <div class="text-xs font-medium text-zinc-500">Avg Process Time</div>
                    <div class="mt-1 font-mono text-lg font-bold text-white">{{ formatMs(stats.avgProcessMs) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4 text-center">
                    <div class="text-xs font-medium text-zinc-500">Total Duration</div>
                    <div class="mt-1 font-mono text-lg font-bold text-white">{{ formatMs(stats.totalDurationMs) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4 text-center">
                    <div class="text-xs font-medium text-zinc-500">Still Pending</div>
                    <div class="mt-1 font-mono text-lg font-bold" :class="stats.pending > 0 ? 'text-amber-400' : 'text-zinc-600'">
                        {{ stats.pending }}
                    </div>
                </div>
                <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-4 text-center">
                    <div class="text-xs font-medium text-zinc-500">Failed</div>
                    <div class="mt-1 font-mono text-lg font-bold" :class="stats.failed > 0 ? 'text-rose-500' : 'text-zinc-600'">
                        {{ stats.failed ?? 0 }}
                    </div>
                </div>
            </div>

            <!-- Waterfall Timeline -->
            <div v-if="jobs.length > 0" class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="font-mono text-sm font-semibold text-zinc-300">Job Waterfall</h2>
                    <div class="flex items-center gap-4 text-xs text-zinc-500">
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-2.5 w-2.5 rounded-sm bg-zinc-700" />
                            Waiting
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-2.5 w-2.5 rounded-sm bg-blue-500" />
                            Processing
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-2.5 w-2.5 animate-pulse rounded-sm bg-amber-500" />
                            Active
                        </span>
                        <span class="flex items-center gap-1.5">
                            <span class="inline-block h-2.5 w-2.5 rounded-sm bg-rose-500" />
                            Failed
                        </span>
                    </div>
                </div>

                <!-- Time axis -->
                <div class="relative mb-2 flex justify-between px-14 text-[10px] font-mono text-zinc-600">
                    <span>0ms</span>
                    <span>{{ formatMs(timelineMax * 0.25) }}</span>
                    <span>{{ formatMs(timelineMax * 0.5) }}</span>
                    <span>{{ formatMs(timelineMax * 0.75) }}</span>
                    <span>{{ formatMs(timelineMax) }}</span>
                </div>

                <div class="max-h-[600px] space-y-px overflow-y-auto">
                    <div
                        v-for="job in jobs"
                        :key="job.id"
                        class="group flex items-center gap-2"
                    >
                        <span class="w-12 shrink-0 text-right font-mono text-[10px] text-zinc-600">
                            #{{ job.number }}
                        </span>
                        <div class="relative h-4 flex-1 rounded-sm bg-zinc-800/50">
                            <!-- Waiting bar (from 0 to pickup) -->
                            <div
                                v-if="job.startMs !== null"
                                class="absolute inset-y-0 left-0 rounded-l-sm bg-zinc-700/60"
                                :style="{ width: `${(job.startMs / timelineMax) * 100}%` }"
                            />
                            <!-- Processing bar -->
                            <div
                                v-if="job.startMs !== null && job.endMs !== null"
                                class="absolute inset-y-0 rounded-sm transition-all duration-200"
                                :style="{
                                    left: `${(job.startMs / timelineMax) * 100}%`,
                                    width: `${((job.endMs - job.startMs) / timelineMax) * 100}%`,
                                    backgroundColor: job.status === 'failed' ? '#f43f5e' : (job.worker ? workerColors[job.worker] : '#3b82f6'),
                                }"
                            />
                            <!-- Active processing (no end yet) -->
                            <div
                                v-else-if="job.startMs !== null && job.endMs === null"
                                class="absolute inset-y-0 animate-pulse rounded-sm bg-amber-500"
                                :style="{
                                    left: `${(job.startMs / timelineMax) * 100}%`,
                                    width: '3%',
                                    minWidth: '8px',
                                }"
                            />
                            <!-- Pending shimmer -->
                            <div
                                v-if="job.status === 'pending'"
                                class="absolute inset-0 animate-pulse rounded-sm bg-zinc-700/30"
                            />
                        </div>
                    </div>
                </div>

                <!-- Worker Legend -->
                <div v-if="Object.keys(workerColors).length > 1" class="mt-4 flex flex-wrap gap-3 border-t border-zinc-800 pt-4">
                    <span class="text-[10px] font-medium text-zinc-500">Workers:</span>
                    <span
                        v-for="(color, worker) in workerColors"
                        :key="worker"
                        class="flex items-center gap-1.5 text-[10px] text-zinc-400"
                    >
                        <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: color }" />
                        {{ worker }}
                    </span>
                </div>
            </div>

            <!-- Empty State -->
            <div v-else-if="!batchId" class="rounded-xl border border-dashed border-zinc-800 p-16 text-center">
                <div class="font-mono text-sm text-zinc-600">
                    Dispatch some jobs to see the waterfall visualization
                </div>
            </div>

            <!-- Recent Batches -->
            <div v-if="recentBatches.length > 0" class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-6">
                <h2 class="mb-3 font-mono text-sm font-semibold text-zinc-300">Recent Batches</h2>
                <div class="space-y-1">
                    <button
                        v-for="batch in recentBatches"
                        :key="batch.id"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left transition hover:bg-zinc-800"
                        :class="batchId === batch.id ? 'bg-zinc-800 text-white' : 'text-zinc-400'"
                        @click="selectBatch(batch.id)"
                    >
                        <span class="font-mono text-xs">{{ batch.id }}</span>
                        <span class="flex items-center gap-3 text-xs">
                            <span class="text-zinc-500">{{ batch.jobCount }} jobs</span>
                            <span class="text-violet-400/70">{{ batch.uniqueWorkers }} {{ batch.uniqueWorkers === 1 ? 'worker' : 'workers' }}</span>
                            <span class="text-zinc-600">{{ batch.dispatchedAt }}</span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
