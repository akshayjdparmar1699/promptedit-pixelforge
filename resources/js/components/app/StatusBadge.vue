<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ status: 'queued' | 'processing' | 'succeeded' | 'failed' }>();

const styles = computed(() => {
    switch (props.status) {
        case 'queued':
            return { label: 'Queued', classes: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200', pulse: true };
        case 'processing':
            return { label: 'Processing', classes: 'bg-sky-100 text-sky-900 dark:bg-sky-950 dark:text-sky-200', pulse: true };
        case 'succeeded':
            return { label: 'Ready', classes: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200', pulse: false };
        default:
            return { label: 'Failed', classes: 'bg-rose-100 text-rose-900 dark:bg-rose-950 dark:text-rose-200', pulse: false };
    }
});
</script>

<template>
    <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="styles.classes">
        <span class="relative flex size-1.5">
            <span v-if="styles.pulse" class="absolute inline-flex size-full animate-ping rounded-full bg-current opacity-60 motion-reduce:animate-none" />
            <span class="relative inline-flex size-1.5 rounded-full bg-current" />
        </span>
        {{ styles.label }}
    </span>
</template>
