<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import axios from 'axios';
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useAppearance } from '@/composables/useAppearance';
import PlainTextEditor from '@/components/PlainTextEditor.vue';

const props = defineProps<{
    speakers:
        | { name: string; languages: string[]; sample_url: string | null }[]
        | null;
}>();

const { resolvedAppearance, updateAppearance } = useAppearance();

const toggleTheme = () => {
    updateAppearance(resolvedAppearance.value === 'dark' ? 'light' : 'dark');
};

const inputText = ref('');
const isLoading = ref(false);
const errorMessage = ref('');
const selectedSpeaker = ref(props.speakers?.[0]?.name ?? 'mari');
const speed = ref(1.0);

// Active input panel
const activePanel = ref<null | 'url' | 'file'>(null);

const togglePanel = (panel: 'url' | 'file') => {
    activePanel.value = activePanel.value === panel ? null : panel;
    errorMessage.value = '';
};

// --- URL ---
const urlInput = ref('');
const isUrlLoading = ref(false);

const extractFromUrl = async () => {
    if (!urlInput.value.trim()) {
        return;
    }

    isUrlLoading.value = true;
    errorMessage.value = '';

    try {
        const response = await axios.post('/api/extract-url', {
            url: urlInput.value.trim(),
        });
        inputText.value = response.data.text;
        urlInput.value = '';
        activePanel.value = null;
    } catch (error: any) {
        errorMessage.value =
            error.response?.data?.error ?? 'Lehe laadimine ebaõnnestus.';
    } finally {
        isUrlLoading.value = false;
    }
};

// --- File ---
const fileInputRef = ref<HTMLInputElement | null>(null);
const uploadedFileName = ref<string | null>(null);
const isFileLoading = ref(false);
const isDraggingOver = ref(false);

const pdfAbortController = ref<AbortController | null>(null);
const pdfStreamProgress = ref<{ page: number; total: number } | null>(null);
const isEditorExpanded = ref(false);

const openFilePicker = () => fileInputRef.value?.click();

const stopPdfStream = () => {
    pdfAbortController.value?.abort();
    pdfAbortController.value = null;
    pdfStreamProgress.value = null;
    isFileLoading.value = false;
};

const uploadPdfStreaming = async (file: File) => {
    const formData = new FormData();
    formData.append('file', file);

    const controller = new AbortController();
    pdfAbortController.value = controller;

    const csrfToken =
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

    let response: Response;

    try {
        response = await fetch('/api/extract-pdf', {
            method: 'POST',
            body: formData,
            signal: controller.signal,
            headers: { 'X-CSRF-TOKEN': csrfToken },
        });
    } catch (err: any) {
        if (err.name !== 'AbortError') {
            errorMessage.value = 'Faili lugemine ebaõnnestus.';
        }

        isFileLoading.value = false;
        pdfAbortController.value = null;

        return;
    }

    // Text-based PDF — regular JSON response
    if (response.headers.get('content-type')?.includes('application/json')) {
        try {
            const data = await response.json();

            if (!response.ok) {
                errorMessage.value = data.error ?? 'Faili lugemine ebaõnnestus.';
                uploadedFileName.value = null;
            } else {
                inputText.value = data.text;
                activePanel.value = null;
            }
        } catch {
            errorMessage.value = 'Faili lugemine ebaõnnestus.';
            uploadedFileName.value = null;
        }

        isFileLoading.value = false;
        pdfAbortController.value = null;

        return;
    }

    // Scanned PDF — SSE stream, text appears page by page
    if (!response.body) {
        errorMessage.value = 'Voogedastus ei ole selles brauseris toetatud.';
        isFileLoading.value = false;
        pdfAbortController.value = null;

        return;
    }

    inputText.value = '';
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    try {
        while (true) {
            const { done, value } = await reader.read();

            if (done) {
                break;
            }

            buffer += decoder.decode(value, { stream: true });

            // SSE events are separated by \n\n
            const parts = buffer.split('\n\n');
            buffer = parts.pop() ?? '';

            for (const part of parts) {
                const line = part.trim();

                if (!line.startsWith('data: ')) {
                    continue;
                }

                try {
                    const event = JSON.parse(line.slice(6));

                    if (event.type === 'page') {
                        pdfStreamProgress.value = {
                            page: event.page,
                            total: event.total,
                        };

                        if (event.text) {
                            inputText.value +=
                                (inputText.value ? '\n' : '') + event.text;
                        }
                    } else if (event.type === 'done') {
                        activePanel.value = null;
                    } else if (event.type === 'error') {
                        errorMessage.value =
                            event.message ?? 'Faili lugemine ebaõnnestus.';
                        uploadedFileName.value = null;
                    }
                } catch {
                    // Ignore malformed SSE lines
                }
            }
        }
    } catch (err: any) {
        if (err.name !== 'AbortError') {
            errorMessage.value = 'Faili lugemine ebaõnnestus.';
            uploadedFileName.value = null;
        }
    } finally {
        reader.cancel();
        isFileLoading.value = false;
        pdfAbortController.value = null;
        pdfStreamProgress.value = null;
    }
};

const uploadFile = async (file: File) => {
    const ext = '.' + file.name.split('.').pop()?.toLowerCase();

    if (!['.txt', '.docx', '.pdf'].includes(ext)) {
        errorMessage.value = 'Lubatud failid: .txt, .docx, .pdf';

        return;
    }

    uploadedFileName.value = file.name;
    isFileLoading.value = true;
    errorMessage.value = '';

    if (ext === '.pdf') {
        await uploadPdfStreaming(file);

        return;
    }

    const formData = new FormData();
    formData.append('file', file);

    try {
        const response = await axios.post('/api/extract-text', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        inputText.value = response.data.text;
        activePanel.value = null;
    } catch (error: any) {
        errorMessage.value =
            error.response?.data?.error ?? 'Faili lugemine ebaõnnestus.';
        uploadedFileName.value = null;
    } finally {
        isFileLoading.value = false;
    }
};

const onFileSelected = async (event: Event) => {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    if (!file) {
        return;
    }

    await uploadFile(file);
    input.value = '';
};

const onDragOver = (event: DragEvent) => {
    event.preventDefault();
    isDraggingOver.value = true;
};
const onDragLeave = () => {
    isDraggingOver.value = false;
};
const onDrop = async (event: DragEvent) => {
    event.preventDefault();
    isDraggingOver.value = false;
    const file = event.dataTransfer?.files?.[0];

    if (file) {
        await uploadFile(file);
    }
};

// --- Synthesis (main form) ---
type JobStatus = 'idle' | 'pending' | 'processing' | 'done' | 'failed';
const jobStatus = ref<JobStatus>('idle');
const jobProgress = ref(0);
const jobTotal = ref(0);
const jobQueuePosition = ref<number | null>(null);
const audioUrl = ref<string | null>(null);

let pollingTimeout: ReturnType<typeof setTimeout> | null = null;

const stopPolling = () => {
    if (pollingTimeout !== null) {
        clearTimeout(pollingTimeout);
        pollingTimeout = null;
    }
};

const pollStatus = async (jobId: string) => {
    try {
        const { data } = await axios.get(`/api/synthesis/status/${jobId}`);
        jobStatus.value = data.status;
        jobProgress.value = data.progress ?? 0;
        jobTotal.value = data.total ?? 0;
        jobQueuePosition.value = data.queue_position ?? null;

        if (data.status === 'done') {
            audioUrl.value = data.audio_url;
            isLoading.value = false;
            stopPolling();
            await loadHistory();

            return;
        }

        if (data.status === 'failed') {
            errorMessage.value = data.error ?? 'Sünteesimisel tekkis viga.';
            isLoading.value = false;
            stopPolling();
            await loadHistory();

            return;
        }

        const interval = data.status === 'pending' ? 3000 : 2000;
        pollingTimeout = setTimeout(() => pollStatus(jobId), interval);
    } catch {
        errorMessage.value = 'Ülesande staatuse päring ebaõnnestus.';
        isLoading.value = false;
        stopPolling();
    }
};

const sendText = async () => {
    if (!inputText.value.trim()) {
        return;
    }

    isLoading.value = true;
    errorMessage.value = '';
    audioUrl.value = null;
    jobStatus.value = 'pending';
    jobProgress.value = 0;
    jobTotal.value = 0;
    jobQueuePosition.value = null;
    stopPolling();

    try {
        const { data } = await axios.post('/api/synthesize', {
            text: inputText.value,
            speaker: selectedSpeaker.value,
            speed: speed.value,
        });
        await loadHistory();
        pollingTimeout = setTimeout(() => pollStatus(data.job_id), 2000);
    } catch {
        errorMessage.value = 'Serveri ühendus ebaõnnestus.';
        isLoading.value = false;
        jobStatus.value = 'idle';
    }
};

// --- History ---
type HistoryItem = {
    id: number;
    job_id: string | null;
    status: 'pending' | 'processing' | 'done' | 'failed';
    audio_url: string | null;
    speaker: string;
    text_preview: string | null;
    created_at: string;
    expires_in_days: number | null;
    progress: number;
    total: number;
    queue_position: number | null;
    error: string | null;
};

const historyOpen = ref(false);
const historyItems = ref<HistoryItem[]>([]);
const historyLoading = ref(false);
let historyPollingTimeout: ReturnType<typeof setTimeout> | null = null;

const stopHistoryPolling = () => {
    if (historyPollingTimeout !== null) {
        clearTimeout(historyPollingTimeout);
        historyPollingTimeout = null;
    }
};

const scheduleHistoryRefresh = () => {
    stopHistoryPolling();
    const hasActive = historyItems.value.some((i) =>
        ['pending', 'processing'].includes(i.status),
    );

    if (hasActive) {
        const interval = historyItems.value.some(
            (i) => i.status === 'processing',
        )
            ? 2000
            : 3000;
        historyPollingTimeout = setTimeout(async () => {
            await loadHistory();
            scheduleHistoryRefresh();
        }, interval);
    }
};

const capitalize = (s: string) => s.charAt(0).toUpperCase() + s.slice(1);

const formatDate = (iso: string) => {
    const d = new Date(iso);

    return d.toLocaleDateString('et-EE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const loadHistory = async () => {
    historyLoading.value = true;

    try {
        const { data } = await axios.get('/api/my-files');
        historyItems.value = data;
        scheduleHistoryRefresh();
    } catch {
        // Silently ignore history is non-critical
    } finally {
        historyLoading.value = false;
    }
};

const toggleHistory = () => {
    historyOpen.value = !historyOpen.value;

    if (historyOpen.value && historyItems.value.length === 0) {
        loadHistory();
    }
};

const deleteHistoryItem = async (id: number) => {
    historyItems.value = historyItems.value.filter((i) => i.id !== id);

    try {
        await axios.delete(`/api/my-files/${id}`);
    } catch {
        // Silently ignore the record is already removed from the UI
    }
};

const activeHistoryCount = () =>
    historyItems.value.filter((i) =>
        ['pending', 'processing'].includes(i.status),
    ).length;

// --- Voice preview ---
let previewAudio: HTMLAudioElement | null = null;
const isPreviewPlaying = ref(false);

const selectedSpeakerData = () =>
    props.speakers?.find((s) => s.name === selectedSpeaker.value) ?? null;

const stopPreview = () => {
    if (previewAudio) {
        previewAudio.pause();
        previewAudio.src = '';
        previewAudio = null;
    }

    isPreviewPlaying.value = false;
};

const togglePreview = () => {
    if (isPreviewPlaying.value) {
        stopPreview();

        return;
    }

    const url = selectedSpeakerData()?.sample_url;

    if (!url) {
        return;
    }

    previewAudio = new Audio(url);
    isPreviewPlaying.value = true;
    previewAudio.addEventListener('ended', stopPreview);
    previewAudio.addEventListener('error', stopPreview);
    previewAudio.play();
};

// Stop the preview when the selected voice changes
watch(selectedSpeaker, stopPreview);

const onKeyDown = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && isEditorExpanded.value) {
        isEditorExpanded.value = false;
    }
};

const onBeforeUnload = (e: BeforeUnloadEvent) => {
    if (pdfAbortController.value !== null) {
        e.preventDefault();
    }
};

onMounted(() => {
    window.addEventListener('keydown', onKeyDown);
    window.addEventListener('beforeunload', onBeforeUnload);
    loadHistory();
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeyDown);
    window.removeEventListener('beforeunload', onBeforeUnload);
    stopPolling();
    stopHistoryPolling();
    stopPreview();
    stopPdfStream();
});
</script>

<template>
    <Head title="Tekstist kõneks" />

    <div
        class="flex min-h-screen flex-col items-center justify-center bg-background p-4"
    >
        <!-- Theme toggle -->
        <button
            type="button"
            :title="
                resolvedAppearance === 'dark'
                    ? 'Switch to light mode'
                    : 'Switch to dark mode'
            "
            class="fixed top-4 left-4 z-30 flex h-9 w-9 items-center justify-center rounded-md border border-border bg-card text-muted-foreground shadow-sm transition-colors hover:border-primary hover:text-foreground"
            @click="toggleTheme"
        >
            <svg
                v-if="resolvedAppearance === 'dark'"
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
            >
                <circle cx="12" cy="12" r="5" />
                <path
                    stroke-linecap="round"
                    d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"
                />
            </svg>
            <svg
                v-else
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"
                />
            </svg>
        </button>

        <!-- History button -->
        <button
            type="button"
            class="fixed top-4 right-4 z-30 flex items-center gap-1.5 rounded-md border border-border bg-card px-3 py-2 text-sm text-muted-foreground shadow-sm transition-colors hover:border-primary hover:text-foreground"
            @click="toggleHistory"
        >
            <svg
                xmlns="http://www.w3.org/2000/svg"
                class="h-4 w-4"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                stroke-width="2"
            >
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                />
            </svg>
            <span>Ajalugu</span>
            <span
                v-if="historyItems.length > 0"
                class="ml-0.5 rounded-full px-1.5 py-0.5 text-xs leading-none"
                :class="
                    activeHistoryCount() > 0
                        ? 'bg-amber-500 text-white'
                        : 'bg-primary text-primary-foreground'
                "
            >
                {{ historyItems.length }}
            </span>
        </button>

        <!-- History sidebar -->
        <transition name="slide">
            <div
                v-if="historyOpen"
                class="fixed top-0 right-0 z-20 flex h-full w-full max-w-sm flex-col border-l border-border bg-card shadow-xl"
            >
                <div
                    class="flex items-center justify-between border-b border-border px-4 py-3"
                >
                    <h2 class="text-sm font-semibold text-foreground">
                        Minu heli
                    </h2>
                    <button
                        type="button"
                        class="rounded p-1 text-muted-foreground hover:text-foreground"
                        @click="historyOpen = false"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-4 w-4"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>

                <!-- List -->
                <div class="flex-1 overflow-y-auto px-4 py-3">
                    <div
                        v-if="historyLoading && historyItems.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Laadimine...
                    </div>
                    <div
                        v-else-if="historyItems.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Ajalugu on tühi
                    </div>
                    <div v-else class="flex flex-col gap-3">
                        <div
                            v-for="item in historyItems"
                            :key="item.id"
                            class="rounded-lg border bg-background p-3"
                            :class="
                                item.status === 'failed'
                                    ? 'border-destructive/40'
                                    : 'border-border'
                            "
                        >
                            <!-- Meta -->
                            <div
                                class="mb-2 flex items-center justify-between gap-2"
                            >
                                <span
                                    class="text-xs font-medium text-foreground"
                                    >{{ capitalize(item.speaker) }}</span
                                >
                                <div class="flex items-center gap-2">
                                    <span
                                        class="text-xs text-muted-foreground"
                                        >{{ formatDate(item.created_at) }}</span
                                    >
                                    <button
                                        type="button"
                                        title="Eemalda ajaloost"
                                        class="rounded p-0.5 text-muted-foreground opacity-50 transition-opacity hover:text-destructive hover:opacity-100"
                                        @click="deleteHistoryItem(item.id)"
                                    >
                                        <svg
                                            xmlns="http://www.w3.org/2000/svg"
                                            class="h-3.5 w-3.5"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                            stroke-width="2.5"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                d="M6 18L18 6M6 6l12 12"
                                            />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <!-- Text preview -->
                            <p
                                v-if="item.text_preview"
                                class="mb-2 line-clamp-2 text-xs text-muted-foreground"
                            >
                                {{ item.text_preview }}
                            </p>

                            <!-- Status: queued -->
                            <div
                                v-if="item.status === 'pending'"
                                class="flex items-center gap-2 py-1"
                            >
                                <svg
                                    class="h-4 w-4 animate-spin text-amber-500"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    />
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                    />
                                </svg>
                                <span
                                    class="text-xs text-amber-600 dark:text-amber-400"
                                >
                                    <template
                                        v-if="
                                            item.queue_position &&
                                            item.queue_position > 1
                                        "
                                    >
                                        Järjekorras {{ item.queue_position }}.
                                        kohal
                                    </template>
                                    <template v-else>
                                        Alustab peagi...
                                    </template>
                                </span>
                            </div>

                            <!-- Status: processing -->
                            <div
                                v-else-if="item.status === 'processing'"
                                class="py-1"
                            >
                                <div
                                    class="mb-1 flex justify-between text-xs text-muted-foreground"
                                >
                                    <span class="flex items-center gap-1.5">
                                        <svg
                                            class="h-3 w-3 animate-spin text-primary"
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                        >
                                            <circle
                                                class="opacity-25"
                                                cx="12"
                                                cy="12"
                                                r="10"
                                                stroke="currentColor"
                                                stroke-width="4"
                                            />
                                            <path
                                                class="opacity-75"
                                                fill="currentColor"
                                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                            />
                                        </svg>
                                        Töötleb osa {{ item.progress }} /
                                        {{ item.total }}
                                    </span>
                                    <span v-if="item.total > 0"
                                        >{{
                                            Math.round(
                                                (item.progress / item.total) *
                                                    100,
                                            )
                                        }}%</span
                                    >
                                </div>
                                <div
                                    class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full bg-primary transition-all duration-500"
                                        :style="{
                                            width:
                                                item.total > 0
                                                    ? `${(item.progress / item.total) * 100}%`
                                                    : '8%',
                                        }"
                                    />
                                </div>
                            </div>

                            <div
                                v-else-if="item.status === 'failed'"
                                class="rounded bg-destructive/10 px-2 py-1 text-xs text-destructive"
                            >
                                Viga: {{ item.error ?? 'Tundmatu viga' }}
                            </div>

                            <template
                                v-else-if="
                                    item.status === 'done' && item.audio_url
                                "
                            >
                                <audio
                                    :src="item.audio_url"
                                    controls
                                    class="w-full"
                                    style="height: 32px"
                                />
                                <div class="mt-1.5 flex items-center justify-between">
                                    <span
                                        v-if="item.expires_in_days !== null"
                                        class="flex items-center gap-1 text-xs"
                                        :class="
                                            item.expires_in_days <= 3
                                                ? 'text-destructive'
                                                : item.expires_in_days <= 7
                                                  ? 'text-amber-500 dark:text-amber-400'
                                                  : 'text-muted-foreground'
                                        "
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                        <span v-if="item.expires_in_days === 0">Aegub täna</span>
                                        <span v-else>{{ item.expires_in_days }} p. kustutamiseni</span>
                                    </span>
                                    <a
                                        :href="item.audio_url"
                                        download="audio.wav"
                                        class="ml-auto text-xs text-primary underline hover:opacity-80"
                                    >
                                        Laadi alla
                                    </a>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </transition>

        <div
            v-if="historyOpen"
            class="fixed inset-0 z-10 bg-black/30"
            @click="historyOpen = false"
        />

        <div
            class="w-full max-w-3xl rounded-lg border border-border bg-card p-8 shadow-sm"
        >
            <h1 class="mb-1 text-3xl font-bold text-foreground">
                Tekstist kõneks
            </h1>
            <p class="mb-6 text-sm text-muted-foreground">
                Sisesta tekst, lae fail või veebileht
            </p>

            <div class="mb-4 flex gap-2">
                <button
                    type="button"
                    class="flex flex-1 items-center justify-center gap-1.5 rounded-md border px-3 py-1.5 text-sm transition-colors"
                    :class="
                        activePanel === 'url'
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border bg-background text-muted-foreground hover:border-primary hover:text-foreground'
                    "
                    :disabled="isLoading || isFileLoading || isUrlLoading"
                    @click="togglePanel('url')"
                >
                    🔗 <span>Veebileht</span>
                </button>
                <button
                    type="button"
                    class="flex flex-1 items-center justify-center gap-1.5 rounded-md border px-3 py-1.5 text-sm transition-colors"
                    :class="
                        activePanel === 'file'
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border bg-background text-muted-foreground hover:border-primary hover:text-foreground'
                    "
                    :disabled="isLoading || isFileLoading || isUrlLoading"
                    @click="togglePanel('file')"
                >
                    📄 <span>Fail</span>
                </button>
            </div>

            <div v-if="activePanel === 'url'" class="mb-4">
                <div class="flex gap-2">
                    <input
                        v-model="urlInput"
                        type="url"
                        placeholder="https://..."
                        class="min-w-0 flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground placeholder:text-muted-foreground focus:ring-2 focus:ring-ring focus:outline-none disabled:opacity-50"
                        :disabled="isUrlLoading"
                        @keyup.enter="extractFromUrl"
                    />
                    <button
                        type="button"
                        class="cursor-pointer rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground transition-opacity hover:opacity-80 disabled:cursor-not-allowed disabled:opacity-50"
                        :disabled="isUrlLoading || !urlInput.trim()"
                        @click="extractFromUrl"
                    >
                        {{ isUrlLoading ? 'Laen...' : 'Laadi' }}
                    </button>
                </div>
            </div>

            <div v-if="activePanel === 'file'" class="mb-4">
                <input
                    ref="fileInputRef"
                    type="file"
                    accept=".txt,.docx,.pdf"
                    class="hidden"
                    @change="onFileSelected"
                />
                <button
                    type="button"
                    class="flex w-full cursor-pointer flex-col items-center justify-center gap-1 rounded-md border border-dashed py-6 text-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                    :class="
                        isDraggingOver
                            ? 'border-primary bg-primary/5 text-primary'
                            : 'border-border bg-background text-muted-foreground hover:border-primary hover:text-primary'
                    "
                    :disabled="isFileLoading"
                    @click="openFilePicker"
                    @dragover="onDragOver"
                    @dragleave="onDragLeave"
                    @drop="onDrop"
                >
                    <template v-if="pdfStreamProgress">
                        <svg
                            class="h-4 w-4 animate-spin text-primary"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            />
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                            />
                        </svg>
                        <span>
                            Loen lehte {{ pdfStreamProgress.page }} /
                            {{ pdfStreamProgress.total }}
                        </span>
                    </template>
                    <span v-else-if="isFileLoading">Laen faili...</span>
                    <template v-else-if="isDraggingOver">
                        <span class="text-2xl">📂</span>
                        <span>Lase lahti</span>
                    </template>
                    <template v-else>
                        <span class="text-2xl">📄</span>
                        <span>{{
                            uploadedFileName ??
                            'Klõpsa või lohista .txt / .docx / .pdf'
                        }}</span>
                    </template>
                </button>

                <div v-if="pdfStreamProgress" class="mt-2 space-y-1.5">
                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-primary transition-all duration-500"
                            :style="{
                                width: `${(pdfStreamProgress.page / pdfStreamProgress.total) * 100}%`,
                            }"
                        />
                    </div>
                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="text-xs text-muted-foreground underline hover:text-destructive"
                            @click.stop="stopPdfStream"
                        >
                            Peata lugemine
                        </button>
                    </div>
                </div>
            </div>

            <div
                v-if="speakers && speakers.length"
                class="mb-3 flex items-end gap-4"
            >
                <div class="min-w-0 flex-1">
                    <label
                        class="mb-1 block text-sm font-medium text-foreground"
                        >Vali hääl</label
                    >
                    <div class="flex items-center gap-2">
                        <select
                            v-model="selectedSpeaker"
                            class="min-w-0 flex-1 rounded-md border border-input bg-background px-3 py-2 text-sm text-foreground"
                            :disabled="isLoading"
                        >
                            <option
                                v-for="speaker in speakers"
                                :key="speaker.name"
                                :value="speaker.name"
                            >
                                {{ capitalize(speaker.name) }}
                            </option>
                        </select>

                        <button
                            type="button"
                            :title="
                                selectedSpeakerData()?.sample_url
                                    ? 'Kuula häält'
                                    : 'Näidis pole saadaval'
                            "
                            :disabled="!selectedSpeakerData()?.sample_url"
                            class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-md border transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                            :class="
                                isPreviewPlaying
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border bg-background text-muted-foreground hover:border-primary hover:text-primary'
                            "
                            @click="togglePreview"
                        >
                            <svg
                                v-if="isPreviewPlaying"
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                viewBox="0 0 24 24"
                                fill="currentColor"
                            >
                                <rect
                                    x="5"
                                    y="5"
                                    width="14"
                                    height="14"
                                    rx="2"
                                />
                            </svg>
                            <svg
                                v-else
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                viewBox="0 0 24 24"
                                fill="currentColor"
                            >
                                <path d="M11 5L6 9H2v6h4l5 4V5z" />
                                <path
                                    d="M19.07 4.93a10 10 0 010 14.14M15.54 8.46a5 5 0 010 7.07"
                                    stroke="currentColor"
                                    stroke-width="2"
                                    stroke-linecap="round"
                                    fill="none"
                                />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="flex-shrink-0">
                    <label
                        class="mb-1 block text-sm font-medium text-foreground"
                        >Kiirus</label
                    >
                    <div
                        class="flex h-9 overflow-hidden rounded-md border border-border"
                    >
                        <button
                            type="button"
                            :disabled="isLoading || speed <= 0.5"
                            class="flex w-9 items-center justify-center border-r border-border bg-background text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
                            @click="
                                speed = Math.round((speed - 0.25) * 100) / 100
                            "
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-3.5 w-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M20 12H4"
                                />
                            </svg>
                        </button>
                        <span
                            class="flex w-14 items-center justify-center bg-background text-sm font-medium text-foreground tabular-nums"
                        >
                            ×{{ speed }}
                        </span>
                        <button
                            type="button"
                            :disabled="isLoading || speed >= 2"
                            class="flex w-9 items-center justify-center border-l border-border bg-background text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:cursor-not-allowed disabled:opacity-40"
                            @click="
                                speed = Math.round((speed + 0.25) * 100) / 100
                            "
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-3.5 w-3.5"
                                fill="none"
                                viewBox="0 0 24 24"
                                stroke="currentColor"
                                stroke-width="2.5"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    d="M12 4v16m8-8H4"
                                />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <PlainTextEditor
                v-model="inputText"
                placeholder="Sisesta tekst siia..."
                :disabled="isLoading"
                :streaming="pdfAbortController !== null"
            />
            <div class="mt-1 flex items-center justify-between">
                <button
                    v-if="inputText.length > 0"
                    type="button"
                    title="Laienda redaktor (täisekraan)"
                    class="flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                    @click="isEditorExpanded = true"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5h-4m4 0v-4m0 4l-5-5" />
                    </svg>
                    <span>Laienda</span>
                </button>
                <span v-else />
                <span
                    class="text-xs"
                    :class="
                        inputText.length > 100000
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                >
                    {{ inputText.length.toLocaleString() }} / 100 000
                </span>
            </div>

            <!-- Full-screen editor overlay -->
            <Teleport to="body">
                <div
                    v-if="isEditorExpanded"
                    class="fixed inset-0 z-50 flex flex-col bg-background"
                >
                    <!-- Toolbar -->
                    <div class="flex flex-shrink-0 items-center justify-between border-b border-border px-4 py-2">
                        <span class="text-sm text-muted-foreground">
                            {{ inputText.length.toLocaleString() }} märki
                        </span>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                class="rounded-md bg-primary px-4 py-1.5 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-80 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="isLoading || !inputText.trim() || inputText.length > 100000"
                                @click="sendText"
                            >
                                {{ isLoading ? 'Töötleb...' : 'Häälülekanne' }}
                            </button>
                            <button
                                type="button"
                                title="Sulge (Esc)"
                                class="flex h-8 w-8 items-center justify-center rounded border border-border text-muted-foreground transition-colors hover:border-primary hover:text-foreground"
                                @click="isEditorExpanded = false"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 9l6 6m0-6l-6 6M3 8V4m0 0h4M3 4l5 5m13-1V4m0 0h-4m4 0l-5 5M3 16v4m0 0h4m-4 0l5-5m13 5v-4m0 4h-4m4 0l-5-5" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- Editor fills remaining height -->
                    <div class="min-h-0 flex-1 p-4">
                        <PlainTextEditor
                            v-model="inputText"
                            placeholder="Sisesta tekst siia..."
                            :disabled="isLoading"
                            :expanded="true"
                        />
                    </div>
                </div>
            </Teleport>

            <button
                class="mt-3 w-full cursor-pointer rounded-md bg-primary py-2.5 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-80 disabled:cursor-not-allowed disabled:opacity-50"
                :disabled="
                    isLoading || !inputText.trim() || inputText.length > 100000
                "
                @click="sendText"
            >
                {{ isLoading ? 'Töötleb...' : 'Häälülekanne' }}
            </button>

            <div v-if="isLoading" class="mt-4">
                <div
                    class="mb-1 flex justify-between text-xs text-muted-foreground"
                >
                    <span v-if="jobStatus === 'pending'">
                        <template
                            v-if="jobQueuePosition && jobQueuePosition > 1"
                        >
                            Järjekorras {{ jobQueuePosition }}. kohal
                        </template>
                        <template v-else> Ootel... </template>
                    </span>
                    <span v-else-if="jobStatus === 'processing'"
                        >Töötleb osa {{ jobProgress }} / {{ jobTotal }}</span
                    >
                    <span v-else>Töötleb...</span>
                    <span v-if="jobTotal > 0"
                        >{{ Math.round((jobProgress / jobTotal) * 100) }}%</span
                    >
                </div>
                <div class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-primary transition-all duration-500"
                        :style="{
                            width:
                                jobTotal > 0
                                    ? `${(jobProgress / jobTotal) * 100}%`
                                    : '8%',
                        }"
                    />
                </div>
            </div>

            <div
                v-if="errorMessage"
                class="mt-4 rounded-md bg-destructive/10 p-3 text-sm text-destructive"
            >
                {{ errorMessage }}
            </div>

            <div v-if="audioUrl" class="mt-6">
                <audio :src="audioUrl" controls autoplay class="w-full" />
                <a
                    :href="audioUrl"
                    download="audio.wav"
                    class="mt-2 block text-center text-sm text-primary underline hover:opacity-80"
                >
                    Laadi alla heli
                </a>
            </div>
        </div>

        <!-- Footer -->
        <p class="mt-6 text-center text-xs text-muted-foreground">
            Powered by
            <a
                href="https://tartunlp.ai"
                target="_blank"
                rel="noopener noreferrer"
                class="underline underline-offset-2 transition-colors hover:text-foreground"
                >TartuNLP</a
            >
            — speech synthesis technology by the University of Tartu
        </p>
    </div>
</template>
