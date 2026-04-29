<script setup lang="ts">
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { Compartment, EditorState, Transaction } from '@codemirror/state';
import { EditorView, keymap, placeholder as placeholderExt } from '@codemirror/view';
import { onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{
    modelValue: string;
    placeholder?: string;
    disabled?: boolean;
    // When true the editor fills its container height (used in full-screen overlay).
    // When false (default) the scroller is capped at 60vh and grows from 220px.
    expanded?: boolean;
    // When true, Ctrl+Z / Ctrl+Y are blocked so streamed content cannot be undone.
    streaming?: boolean;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const container = ref<HTMLElement | null>(null);
let view: EditorView | null = null;

// Prevents watch → dispatch → emit → watch infinite loop
// when the editor content is updated from outside (file upload, streaming).
let isExternalUpdate = false;

// Compartment allows reconfiguring editability at runtime (disabled prop).
const editableConf = new Compartment();

// Compartment for the history extension — reconfiguring it with a fresh history()
// instance effectively clears the undo stack (used when OCR streaming begins).
const historyConf = new Compartment();

// Matches the app's CSS custom properties (shadcn/Tailwind theme).
const appTheme = EditorView.theme({
    // expanded=true: editor must be height:100% so CodeMirror knows the viewport
    // size and mouse-wheel scrolling works correctly inside the fixed overlay.
    '&': {
        fontFamily: 'inherit',
        fontSize: '0.875rem', // text-sm
        ...(props.expanded ? { height: '100%' } : {}),
    },
    // Scrollable viewport — required for virtual rendering to kick in.
    // Without a bounded height CodeMirror renders all lines at once.
    // expanded=true: fills the parent container (used in full-screen overlay).
    // expanded=false: grows from 220px up to 60vh, then scrolls.
    '.cm-scroller': {
        fontFamily: 'inherit',
        overflowY: 'auto',
        ...(props.expanded
            ? { height: '100%', maxHeight: '100%' }
            : { maxHeight: '60vh' }),
    },
    '.cm-content': {
        padding: '1rem', // p-4
        caretColor: 'hsl(var(--foreground))',
        // Prevent browser spell-check decorations from interfering with OCR text
        whiteSpace: 'pre-wrap',
        wordBreak: 'break-word',
        // minHeight only in normal mode — in expanded the container defines the size
        ...(props.expanded ? {} : { minHeight: '220px' }),
    },
    // Remove the default blue outline — we handle focus ring via Tailwind on the wrapper
    '&.cm-focused': {
        outline: 'none',
    },
    '&.cm-focused .cm-cursor': {
        borderLeftColor: 'hsl(var(--foreground))',
    },
    '.cm-selectionBackground, &.cm-focused .cm-selectionBackground': {
        backgroundColor: 'hsl(var(--primary) / 0.25) !important',
    },
    '.cm-placeholder': {
        color: 'hsl(var(--muted-foreground))',
    },
    // Hide the active-line highlight — looks odd in plain text mode
    '.cm-activeLine': {
        backgroundColor: 'transparent',
    },
});

onMounted(() => {
    const extensions = [
        historyConf.of(history()),
        keymap.of([...defaultKeymap, ...historyKeymap]),
        EditorView.lineWrapping,
        editableConf.of(EditorView.editable.of(!(props.disabled ?? false))),
        appTheme,
        EditorView.updateListener.of((update) => {
            if (update.docChanged && !isExternalUpdate) {
                emit('update:modelValue', update.state.doc.toString());
            }
        }),
    ];

    if (props.placeholder) {
        extensions.push(placeholderExt(props.placeholder));
    }

    view = new EditorView({
        state: EditorState.create({
            doc: props.modelValue,
            extensions,
        }),
        parent: container.value!,
    });
});

onUnmounted(() => {
    view?.destroy();
    view = null;
});

// Sync external value changes into the editor (file upload, PDF streaming, URL extract).
watch(
    () => props.modelValue,
    (newValue) => {
        if (!view || isExternalUpdate) {
return;
}

        const currentValue = view.state.doc.toString();

        // Skip if editor already has this value (avoids redundant dispatch)
        if (currentValue === newValue) {
return;
}

        isExternalUpdate = true;

        // During OCR streaming, each page is appended to the end of the existing text.
        // Detect this case and only insert the new suffix — this preserves the user's
        // scroll position and cursor so they can read/edit while streaming continues.
        if (newValue.startsWith(currentValue)) {
            view.dispatch({
                changes: {
                    from: view.state.doc.length,
                    to: view.state.doc.length,
                    insert: newValue.slice(currentValue.length),
                },
                annotations: Transaction.addToHistory.of(false),
            });
        } else {
            // Full replace: file upload, URL extract, or manual clear
            view.dispatch({
                changes: { from: 0, to: view.state.doc.length, insert: newValue },
                annotations: Transaction.addToHistory.of(false),
            });
        }

        isExternalUpdate = false;
    },
);

// When OCR streaming begins, clear the undo history so pre-existing edits cannot
// be undone into OCR content. OCR dispatches already use addToHistory: false,
// so after the clear the stack stays empty until the user types something new.
watch(
    () => props.streaming,
    (streaming) => {
        if (streaming) {
            view?.dispatch({
                effects: historyConf.reconfigure(history()),
            });
        }
    },
);

// Toggle read-only when parent disables the editor (e.g. during synthesis)
watch(
    () => props.disabled,
    (disabled) => {
        view?.dispatch({
            effects: editableConf.reconfigure(
                EditorView.editable.of(!(disabled ?? false)),
            ),
        });
    },
);
</script>

<template>
    <!--
        Outer div carries the visual border/background that the textarea had.
        focus-within applies the ring when CodeMirror's inner contenteditable is focused.
        In expanded mode it fills the parent container height.
    -->
    <div
        ref="container"
        class="w-full overflow-hidden rounded-md border border-input bg-background text-foreground focus-within:ring-2 focus-within:ring-ring focus-within:outline-none"
        :class="[
            { 'cursor-not-allowed opacity-50': disabled },
            expanded ? 'h-full' : '',
        ]"
    />
</template>
