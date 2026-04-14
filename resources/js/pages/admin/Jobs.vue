<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

type Job = {
    id: number;
    job_id: string | null;
    status: 'pending' | 'processing';
    speaker: string;
    text_preview: string | null;
    created_at: string;
    progress: number;
    total: number;
    queue_position: number | null;
    session_id: string;
};

defineProps<{ jobs: Job[] }>();

const cancelling = ref<Set<number>>(new Set());

const cancel = (id: number) => {
    if (cancelling.value.has(id)) {
        return;
    }

    if (!confirm('Cancel this job?')) {
        return;
    }

    cancelling.value.add(id);
    router.delete(`/admin/jobs/${id}`, {
        preserveScroll: true,
        onFinish: () => cancelling.value.delete(id),
    });
};

const formatDate = (iso: string) =>
    new Date(iso).toLocaleString('et-EE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' });

const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);
</script>

<template>
    <Head title="Admin — Jobs" />

    <div class="p-6">
        <!-- Header -->
        <div class="mb-6 flex items-center gap-3">
            <Link href="/admin" class="text-muted-foreground hover:text-foreground transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </Link>
            <h1 class="text-2xl font-bold text-foreground">Jobs queue</h1>
            <span class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ jobs.length }} active</span>
        </div>

        <!-- Empty -->
        <div v-if="jobs.length === 0" class="rounded-lg border border-border bg-card p-12 text-center text-muted-foreground">
            No active jobs at the moment.
        </div>

        <!-- Jobs table -->
        <div v-else class="overflow-hidden rounded-lg border border-border bg-card">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border bg-muted/50">
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">ID</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Speaker</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Preview</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Progress</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Created</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="job in jobs" :key="job.id" class="border-b border-border last:border-0 hover:bg-muted/30 transition-colors">
                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">#{{ job.id }}</td>

                        <!-- Status badge -->
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="job.status === 'processing'
                                    ? 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400'
                                    : 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400'"
                            >
                                <svg class="h-2.5 w-2.5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                                </svg>
                                {{ job.status === 'processing' ? 'Processing' : `Queue #${job.queue_position ?? '?'}` }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-foreground">{{ capitalize(job.speaker) }}</td>

                        <td class="max-w-xs px-4 py-3 text-muted-foreground">
                            <span class="line-clamp-1">{{ job.text_preview ?? '—' }}</span>
                        </td>

                        <!-- Progress bar -->
                        <td class="px-4 py-3">
                            <div v-if="job.status === 'processing' && job.total > 0" class="flex items-center gap-2">
                                <div class="h-1.5 w-20 overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-primary transition-all duration-500"
                                        :style="{ width: `${(job.progress / job.total) * 100}%` }"
                                    />
                                </div>
                                <span class="text-xs text-muted-foreground">{{ job.progress }}/{{ job.total }}</span>
                            </div>
                            <span v-else class="text-xs text-muted-foreground">—</span>
                        </td>

                        <td class="px-4 py-3 text-xs text-muted-foreground">{{ formatDate(job.created_at) }}</td>

                        <td class="px-4 py-3">
                            <button
                                type="button"
                                :disabled="cancelling.has(job.id)"
                                class="rounded-md border border-destructive/50 px-2.5 py-1 text-xs text-destructive transition-colors hover:bg-destructive hover:text-white disabled:opacity-50"
                                @click="cancel(job.id)"
                            >
                                Cancel
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
