<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    stats: {
        total: number;
        pending: number;
        processing: number;
        done: number;
        failed: number;
    };
}>();
</script>

<template>
    <Head title="Admin — Dashboard" />

    <div class="p-6">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-2xl font-bold text-foreground">Admin Panel</h1>
            <div class="flex gap-2">
                <Link href="/admin/jobs" class="rounded-md border border-border bg-card px-3 py-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors">
                    Jobs queue
                </Link>
                <Link href="/admin/files" class="rounded-md border border-border bg-card px-3 py-1.5 text-sm text-muted-foreground hover:text-foreground transition-colors">
                    Audio files
                </Link>
            </div>
        </div>

        <!-- Stats cards -->
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <div class="rounded-lg border border-border bg-card p-4">
                <div class="text-3xl font-bold text-foreground">{{ stats.total }}</div>
                <div class="mt-1 text-sm text-muted-foreground">Total</div>
            </div>
            <div class="rounded-lg border border-amber-400/50 bg-amber-50 p-4 dark:bg-amber-950/20">
                <div class="text-3xl font-bold text-amber-600 dark:text-amber-400">{{ stats.pending }}</div>
                <div class="mt-1 text-sm text-amber-600/80 dark:text-amber-400/80">Pending</div>
            </div>
            <div class="rounded-lg border border-blue-400/50 bg-blue-50 p-4 dark:bg-blue-950/20">
                <div class="text-3xl font-bold text-blue-600 dark:text-blue-400">{{ stats.processing }}</div>
                <div class="mt-1 text-sm text-blue-600/80 dark:text-blue-400/80">Processing</div>
            </div>
            <div class="rounded-lg border border-green-400/50 bg-green-50 p-4 dark:bg-green-950/20">
                <div class="text-3xl font-bold text-green-600 dark:text-green-400">{{ stats.done }}</div>
                <div class="mt-1 text-sm text-green-600/80 dark:text-green-400/80">Done</div>
            </div>
            <div class="rounded-lg border border-destructive/40 bg-destructive/5 p-4">
                <div class="text-3xl font-bold text-destructive">{{ stats.failed }}</div>
                <div class="mt-1 text-sm text-destructive/80">Failed</div>
            </div>
        </div>

        <!-- Quick links -->
        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            <Link
                href="/admin/jobs"
                class="group flex items-center justify-between rounded-lg border border-border bg-card p-5 transition-colors hover:border-primary"
            >
                <div>
                    <div class="font-semibold text-foreground">Jobs queue</div>
                    <div class="mt-0.5 text-sm text-muted-foreground">
                        {{ stats.pending + stats.processing }} active job(s) — view and cancel
                    </div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-muted-foreground group-hover:text-primary transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </Link>
            <Link
                href="/admin/files"
                class="group flex items-center justify-between rounded-lg border border-border bg-card p-5 transition-colors hover:border-primary"
            >
                <div>
                    <div class="font-semibold text-foreground">Audio files</div>
                    <div class="mt-0.5 text-sm text-muted-foreground">
                        {{ stats.total }} total file(s) — browse and delete
                    </div>
                </div>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-muted-foreground group-hover:text-primary transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </Link>
        </div>
    </div>
</template>
