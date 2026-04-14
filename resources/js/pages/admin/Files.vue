<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

type AudioFile = {
    id: number;
    status: string;
    speaker: string;
    text_preview: string | null;
    audio_url: string | null;
    filename: string | null;
    created_at: string;
    session_id: string;
    expires_in_days: number | null;
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page: number;
    links: { url: string | null; label: string; active: boolean }[];
};

const props = defineProps<{
    files: Paginator<AudioFile>;
    filters: { status?: string; search?: string };
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const deleting = ref<Set<number>>(new Set());

const applyFilters = () => {
    router.get('/admin/files', { search: search.value, status: status.value }, { preserveScroll: true });
};

const deleteFile = (id: number) => {
    if (deleting.value.has(id)) {
        return;
    }

    if (!confirm('Delete this file permanently from disk?')) {
        return;
    }

    deleting.value.add(id);
    router.delete(`/admin/files/${id}`, {
        preserveScroll: true,
        onFinish: () => deleting.value.delete(id),
    });
};

const formatDate = (iso: string) =>
    new Date(iso).toLocaleString('et-EE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

const statusClass = (s: string) => ({
    pending:    'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400',
    processing: 'bg-blue-100 text-blue-700 dark:bg-blue-950/40 dark:text-blue-400',
    done:       'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-400',
    failed:     'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400',
}[s] ?? 'bg-muted text-muted-foreground');
</script>

<template>
    <Head title="Admin — Files" />

    <div class="p-6">
        <!-- Header -->
        <div class="mb-6 flex items-center gap-3">
            <Link href="/admin" class="text-muted-foreground hover:text-foreground transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </Link>
            <h1 class="text-2xl font-bold text-foreground">Audio files</h1>
            <span class="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">{{ files.total }} total</span>
        </div>

        <!-- Filters -->
        <div class="mb-4 flex flex-wrap gap-2">
            <input
                v-model="search"
                type="text"
                placeholder="Search by text preview..."
                class="rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-2 focus:ring-ring"
                @keyup.enter="applyFilters"
            />
            <select
                v-model="status"
                class="rounded-md border border-input bg-background px-3 py-1.5 text-sm text-foreground"
                @change="applyFilters"
            >
                <option value="">All statuses</option>
                <option value="pending">Pending</option>
                <option value="processing">Processing</option>
                <option value="done">Done</option>
                <option value="failed">Failed</option>
            </select>
            <button
                type="button"
                class="rounded-md bg-primary px-3 py-1.5 text-sm text-primary-foreground hover:opacity-80 transition-opacity"
                @click="applyFilters"
            >
                Search
            </button>
        </div>

        <!-- Table -->
        <div class="overflow-hidden rounded-lg border border-border bg-card">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border bg-muted/50">
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">ID</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Speaker</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Preview</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Audio</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Created</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Expires</th>
                        <th class="px-4 py-3 text-left font-medium text-muted-foreground">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="file in files.data" :key="file.id" class="border-b border-border last:border-0 hover:bg-muted/30 transition-colors">
                        <td class="px-4 py-3 font-mono text-xs text-muted-foreground">#{{ file.id }}</td>

                        <td class="px-4 py-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(file.status)">
                                {{ file.status }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-foreground">{{ capitalize(file.speaker) }}</td>

                        <td class="max-w-xs px-4 py-3 text-muted-foreground">
                            <span class="line-clamp-1">{{ file.text_preview ?? '—' }}</span>
                        </td>

                        <!-- Compact audio player for done files -->
                        <td class="px-4 py-3">
                            <audio
                                v-if="file.status === 'done' && file.audio_url"
                                :src="file.audio_url"
                                controls
                                style="height: 28px; width: 180px;"
                            />
                            <span v-else class="text-xs text-muted-foreground">—</span>
                        </td>

                        <td class="px-4 py-3 text-xs text-muted-foreground">{{ formatDate(file.created_at) }}</td>

                        <td class="px-4 py-3 text-xs">
                            <span
                                v-if="file.expires_in_days !== null"
                                class="rounded-full px-2 py-0.5 font-medium"
                                :class="
                                    file.expires_in_days <= 3
                                        ? 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400'
                                        : file.expires_in_days <= 7
                                          ? 'bg-amber-100 text-amber-700 dark:bg-amber-950/40 dark:text-amber-400'
                                          : 'bg-muted text-muted-foreground'
                                "
                            >
                                <template v-if="file.expires_in_days === 0">Today</template>
                                <template v-else>{{ file.expires_in_days }}d</template>
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>

                        <td class="px-4 py-3">
                            <button
                                type="button"
                                :disabled="deleting.has(file.id)"
                                class="rounded-md border border-destructive/50 px-2.5 py-1 text-xs text-destructive transition-colors hover:bg-destructive hover:text-white disabled:opacity-50"
                                @click="deleteFile(file.id)"
                            >
                                Delete
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Empty state -->
            <div v-if="files.data.length === 0" class="p-12 text-center text-muted-foreground">
                No files found.
            </div>
        </div>

        <!-- Pagination -->
        <div v-if="files.last_page > 1" class="mt-4 flex flex-wrap gap-1">
            <template v-for="link in files.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    class="rounded-md border px-3 py-1.5 text-sm transition-colors"
                    :class="link.active
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border bg-card text-muted-foreground hover:border-primary hover:text-foreground'"
                    v-html="link.label"
                />
                <span
                    v-else
                    class="rounded-md border border-border px-3 py-1.5 text-sm opacity-40"
                    v-html="link.label"
                />
            </template>
        </div>
    </div>
</template>
