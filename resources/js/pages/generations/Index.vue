<script setup lang="ts">
import StatusBadge from '@/components/app/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd, formatTokens, formatUsd } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, reactive, watch } from 'vue';

type Status = 'queued' | 'processing' | 'succeeded' | 'failed';
type Generation = {
    id: number;
    provider_id: string | null;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    status: Status;
    status_detail: string | null;
    estimated_charge_nanos: number;
    reserved_nanos: number;
    charge_nanos: number | null;
    usage: Record<string, number> | null;
    image_url: string | null;
    image_url_expires_at: string | null;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
};

const props = defineProps<{
    generations: { data: Generation[]; prev_page_url: string | null; next_page_url: string | null; current_page: number; last_page: number };
    balanceNanos: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/generations' }];

const hasActive = computed(() => props.generations.data.some((g) => g.status === 'queued' || g.status === 'processing'));

let timer: ReturnType<typeof setInterval> | undefined;

watch(
    hasActive,
    (active) => {
        clearInterval(timer);
        if (active) {
            timer = setInterval(() => router.reload({ only: ['generations', 'balanceNanos'] }), 3000);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => clearInterval(timer));

const brokenImages = reactive(new Set<number>());

function aspect(size: string): string {
    const [w, h] = size.split('x').map(Number);
    return `${w} / ${h}`;
}

function timeAgo(iso: string): string {
    const seconds = Math.round((Date.now() - new Date(iso).getTime()) / 1000);
    if (seconds < 60) return 'just now';
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
    if (seconds < 86400) return `${Math.floor(seconds / 3600)} h ago`;
    return new Date(iso).toLocaleDateString();
}

function retry(generation: Generation) {
    router.post(`/generations/${generation.id}/retry`);
}

const statusText: Record<Status, string> = {
    queued: 'Waiting in line',
    processing: 'Drawing your image',
    succeeded: '',
    failed: '',
};
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Your images</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Balance <span class="font-medium text-foreground tabular-nums" :title="exactUsd(balanceNanos)">{{ formatUsd(balanceNanos) }}</span>
                    </p>
                </div>
                <Link href="/generate" class="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90">New image</Link>
            </div>

            <div v-if="generations.data.length === 0" class="rounded-xl border border-dashed p-10 text-center">
                <p class="font-medium">No images yet</p>
                <p class="mt-1 text-sm text-muted-foreground">Write a prompt to create your first one.</p>
                <Link href="/generate" class="mt-4 inline-block text-sm font-medium underline underline-offset-4">Create an image</Link>
            </div>

            <ul v-else class="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="g in generations.data" :key="g.id" class="flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground">
                    <div class="relative bg-muted" :style="{ aspectRatio: aspect(g.size) }">
                        <a v-if="g.status === 'succeeded' && g.image_url && !brokenImages.has(g.id)" :href="g.image_url" target="_blank" rel="noopener">
                            <img
                                :src="g.image_url"
                                :alt="g.prompt"
                                loading="lazy"
                                class="size-full object-cover"
                                @error="brokenImages.add(g.id)"
                            />
                        </a>
                        <div v-else class="flex size-full flex-col items-center justify-center gap-1 p-6 text-center text-sm text-muted-foreground">
                            <template v-if="g.status === 'queued' || g.status === 'processing'">
                                <span class="font-medium text-foreground">{{ statusText[g.status] }}</span>
                                <span>{{ g.status_detail ?? 'Usually 5 to 30 seconds' }}</span>
                            </template>
                            <template v-else-if="g.status === 'succeeded'">
                                <span class="font-medium text-foreground">Image link expired</span>
                                <span>Pixelforge only keeps image links for a limited time.</span>
                            </template>
                            <template v-else>
                                <span class="font-medium text-foreground">No image</span>
                            </template>
                        </div>
                    </div>

                    <div class="flex flex-1 flex-col gap-3 p-4">
                        <div class="flex items-center justify-between gap-2">
                            <StatusBadge :status="g.status" />
                            <span class="text-xs text-muted-foreground" :title="new Date(g.created_at).toLocaleString()">{{ timeAgo(g.created_at) }}</span>
                        </div>

                        <p class="line-clamp-2 text-sm" :title="g.prompt">{{ g.prompt }}</p>
                        <p class="text-xs text-muted-foreground capitalize">
                            {{ g.size }}, {{ g.quality }} quality<span v-if="g.reference_images.length">, {{ g.reference_images.length }} reference{{ g.reference_images.length > 1 ? 's' : '' }}</span>
                        </p>

                        <div v-if="g.status === 'failed'" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-900 dark:bg-rose-950/50 dark:text-rose-200">
                            <p>{{ g.error_message }}</p>
                            <button class="mt-2 font-medium underline underline-offset-2" @click="retry(g)">Try again</button>
                        </div>

                        <dl class="mt-auto grid grid-cols-2 gap-2 border-t pt-3 text-sm">
                            <div>
                                <dt class="text-xs text-muted-foreground">Estimate</dt>
                                <dd class="tabular-nums" :title="exactUsd(g.estimated_charge_nanos)">{{ formatUsd(g.estimated_charge_nanos) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">Charged</dt>
                                <dd v-if="g.status === 'succeeded'" class="font-medium tabular-nums" :title="exactUsd(g.charge_nanos)">{{ formatUsd(g.charge_nanos) }}</dd>
                                <dd v-else-if="g.status === 'failed'" class="font-medium">Free</dd>
                                <dd v-else class="text-muted-foreground" :title="`Up to ${formatUsd(g.reserved_nanos)} is on hold`">Pending</dd>
                            </div>
                        </dl>

                        <details v-if="g.usage" class="text-xs text-muted-foreground">
                            <summary class="cursor-pointer select-none">Token usage</summary>
                            <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 tabular-nums">
                                <dt>Prompt text</dt><dd class="text-right">{{ formatTokens(g.usage.input_text_tokens) }}</dd>
                                <dt>Reference images</dt><dd class="text-right">{{ formatTokens(g.usage.input_image_tokens) }}</dd>
                                <dt>Image output</dt><dd class="text-right">{{ formatTokens(g.usage.output_tokens) }}</dd>
                                <dt>Thinking</dt><dd class="text-right">{{ formatTokens(g.usage.reasoning_tokens) }}</dd>
                            </dl>
                        </details>
                    </div>
                </li>
            </ul>

            <nav v-if="generations.last_page > 1" class="flex items-center justify-between text-sm">
                <Link v-if="generations.prev_page_url" :href="generations.prev_page_url" preserve-scroll class="underline underline-offset-4">Newer</Link>
                <span v-else />
                <span class="text-muted-foreground">Page {{ generations.current_page }} of {{ generations.last_page }}</span>
                <Link v-if="generations.next_page_url" :href="generations.next_page_url" preserve-scroll class="underline underline-offset-4">Older</Link>
                <span v-else />
            </nav>
        </div>
    </AppLayout>
</template>
