<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd, formatTokens, formatUsd } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';

type Case = 'min' | 'expected' | 'max';
type Estimate = {
    tokens: Record<Case, Record<string, number>>;
    provider_cost: Record<Case, number>;
    charge: Record<Case, number>;
};

const props = defineProps<{
    sizes: string[];
    qualities: string[];
    balanceNanos: number;
    prefill: { prompt?: string; size?: string; quality?: string; reference_images?: string[] };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/generate' }];

const MAX_PROMPT = 4000;
const MAX_REFERENCES = 4;

const form = useForm({
    prompt: props.prefill.prompt ?? '',
    size: props.prefill.size && props.sizes.includes(props.prefill.size) ? props.prefill.size : (props.sizes[0] ?? '1024x1024'),
    quality: props.prefill.quality && props.qualities.includes(props.prefill.quality) ? props.prefill.quality : 'medium',
    reference_images: [...(props.prefill.reference_images ?? [])] as string[],
});

// Pixelforge counts Unicode characters, not UTF-16 code units, so spread into code points.
const promptCharacters = computed(() => [...form.prompt].length);
const filledReferences = computed(() => form.reference_images.filter((url) => url.trim() !== '').length);

const estimate = ref<Estimate | null>(null);
const estimateError = ref(false);
let debounce: ReturnType<typeof setTimeout> | undefined;
let requestSeq = 0;

async function loadEstimate() {
    const seq = ++requestSeq;
    const params = new URLSearchParams({
        prompt_characters: String(Math.min(promptCharacters.value, MAX_PROMPT)),
        size: form.size,
        quality: form.quality,
        reference_images: String(Math.min(filledReferences.value, MAX_REFERENCES)),
    });

    try {
        const response = await fetch(`/generations/estimate?${params}`, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(String(response.status));
        const body = (await response.json()) as Estimate;
        if (seq === requestSeq) {
            estimate.value = body;
            estimateError.value = false;
        }
    } catch {
        if (seq === requestSeq) estimateError.value = true;
    }
}

watch([promptCharacters, () => form.size, () => form.quality, filledReferences], () => {
    clearTimeout(debounce);
    debounce = setTimeout(loadEstimate, 200);
});

onMounted(loadEstimate);

const canAfford = computed(() => !estimate.value || props.balanceNanos >= estimate.value.charge.max);
const canSubmit = computed(
    () => form.prompt.trim() !== '' && promptCharacters.value <= MAX_PROMPT && canAfford.value && !form.processing && estimate.value !== null,
);

const sizeShapes: Record<string, { label: string; w: number; h: number }> = {
    '1024x1024': { label: 'Square', w: 24, h: 24 },
    '1536x1024': { label: 'Landscape', w: 30, h: 20 },
    '1024x1536': { label: 'Portrait', w: 20, h: 30 },
};

const tokenRows = computed(() => {
    if (!estimate.value) return [];
    const t = estimate.value.tokens;
    const range = (key: string) =>
        t.min[key] === t.max[key] ? formatTokens(t.max[key]) : `${formatTokens(t.min[key])} to ${formatTokens(t.max[key])}`;

    return [
        { label: 'Prompt text', value: range('input_text_tokens'), hint: 'About 1 token per 4 characters, final count can vary 15%' },
        { label: 'Reference images', value: range('input_image_tokens'), hint: '765 tokens each' },
        { label: 'Image output', value: range('output_tokens'), hint: 'Fixed by size and quality' },
        { label: 'Thinking', value: range('reasoning_tokens'), hint: 'Varies per image, known when it finishes' },
    ];
});

function addReference() {
    if (form.reference_images.length < MAX_REFERENCES) form.reference_images.push('');
}

function removeReference(index: number) {
    form.reference_images.splice(index, 1);
}

function submit() {
    form.transform((data) => ({
        ...data,
        reference_images: data.reference_images.map((url) => url.trim()).filter((url) => url !== ''),
    })).post('/generations', { preserveScroll: true });
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <form class="grid gap-8 p-4 md:p-6 lg:grid-cols-[minmax(0,1fr)_22rem]" @submit.prevent="submit">
            <div class="flex max-w-2xl flex-col gap-7">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Create an image</h1>
                    <p class="mt-1 text-sm text-muted-foreground">Describe what you want. You see the price before you start, and failed images are free.</p>
                </div>

                <div class="flex flex-col gap-2">
                    <div class="flex items-baseline justify-between">
                        <label for="prompt" class="text-sm font-medium">Prompt</label>
                        <span class="text-xs tabular-nums" :class="promptCharacters > MAX_PROMPT ? 'text-rose-600' : 'text-muted-foreground'">
                            {{ promptCharacters.toLocaleString() }} / {{ MAX_PROMPT.toLocaleString() }}
                        </span>
                    </div>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="5"
                        placeholder="A lighthouse on a cliff at dawn, oil painting"
                        class="w-full resize-y rounded-lg border border-input bg-background px-3 py-2.5 text-base leading-relaxed shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    />
                    <p v-if="form.errors.prompt" class="text-sm text-rose-600">{{ form.errors.prompt }}</p>
                </div>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">Size</legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="size in sizes"
                            :key="size"
                            class="flex cursor-pointer flex-col items-center gap-2 rounded-lg border px-3 py-3 text-sm transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
                            :class="form.size === size ? 'border-foreground bg-accent' : 'border-input hover:bg-accent/50'"
                        >
                            <input v-model="form.size" type="radio" name="size" :value="size" class="sr-only" />
                            <span class="flex h-8 items-center">
                                <span
                                    class="rounded-[3px] border-2 border-current"
                                    :style="{ width: (sizeShapes[size]?.w ?? 24) + 'px', height: (sizeShapes[size]?.h ?? 24) + 'px' }"
                                />
                            </span>
                            <span class="font-medium">{{ sizeShapes[size]?.label ?? size }}</span>
                            <span class="text-xs text-muted-foreground tabular-nums">{{ size }}</span>
                        </label>
                    </div>
                </fieldset>

                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-sm font-medium">Quality</legend>
                    <div class="inline-flex w-fit rounded-lg border border-input p-1">
                        <label
                            v-for="quality in qualities"
                            :key="quality"
                            class="cursor-pointer rounded-md px-4 py-1.5 text-sm capitalize transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
                            :class="form.quality === quality ? 'bg-foreground text-background' : 'text-muted-foreground hover:text-foreground'"
                        >
                            <input v-model="form.quality" type="radio" name="quality" :value="quality" class="sr-only" />
                            {{ quality }}
                        </label>
                    </div>
                    <p class="text-xs text-muted-foreground">Higher quality draws more detail, takes longer and costs more.</p>
                </fieldset>

                <div class="flex flex-col gap-2">
                    <div class="flex items-baseline justify-between">
                        <span class="text-sm font-medium">Reference images <span class="font-normal text-muted-foreground">(optional)</span></span>
                        <span class="text-xs text-muted-foreground">{{ form.reference_images.length }} of {{ MAX_REFERENCES }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">Links to images that guide the style. Each one adds 765 tokens.</p>
                    <div v-for="(_, index) in form.reference_images" :key="index" class="flex gap-2">
                        <input
                            v-model="form.reference_images[index]"
                            type="url"
                            inputmode="url"
                            placeholder="https://example.com/style.png"
                            :aria-label="`Reference image ${index + 1}`"
                            class="h-9 w-full rounded-lg border border-input bg-background px-3 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                        <button type="button" class="rounded-lg px-3 text-sm text-muted-foreground hover:bg-accent hover:text-foreground" @click="removeReference(index)">
                            Remove
                        </button>
                    </div>
                    <p v-for="(message, key) in form.errors" v-show="String(key).startsWith('reference_images')" :key="key" class="text-sm text-rose-600">
                        {{ message }}
                    </p>
                    <button
                        v-if="form.reference_images.length < MAX_REFERENCES"
                        type="button"
                        class="w-fit rounded-lg border border-dashed border-input px-3 py-1.5 text-sm text-muted-foreground hover:border-foreground hover:text-foreground"
                        @click="addReference"
                    >
                        Add reference image
                    </button>
                </div>
            </div>

            <aside class="lg:sticky lg:top-6 lg:self-start">
                <div class="rounded-xl border bg-card p-5 text-card-foreground">
                    <h2 class="text-sm font-medium text-muted-foreground">Price for this image</h2>

                    <template v-if="estimate">
                        <p class="mt-2 text-4xl font-semibold tracking-tight tabular-nums" :title="exactUsd(estimate.charge.expected)">
                            {{ formatUsd(estimate.charge.expected) }}
                        </p>
                        <p class="mt-1 text-sm text-muted-foreground tabular-nums">
                            Between {{ formatUsd(estimate.charge.min) }} and {{ formatUsd(estimate.charge.max) }}
                        </p>

                        <dl class="mt-5 divide-y border-y text-sm">
                            <div v-for="row in tokenRows" :key="row.label" class="flex items-baseline justify-between gap-3 py-2" :title="row.hint">
                                <dt class="text-muted-foreground">{{ row.label }}</dt>
                                <dd class="text-right tabular-nums">{{ row.value }} <span class="text-muted-foreground">tokens</span></dd>
                            </div>
                        </dl>

                        <p class="mt-4 text-xs leading-relaxed text-muted-foreground">
                            Some tokens are only counted once the image is done, so this is an estimate that includes our 25% service fee.
                            We hold {{ formatUsd(estimate.charge.max) }} while it runs and refund whatever isn't used. If it fails, you pay nothing.
                        </p>
                    </template>
                    <p v-else-if="estimateError" class="mt-2 text-sm text-rose-600">Prices are unavailable right now. Try again in a moment.</p>
                    <div v-else class="mt-3 h-10 w-32 animate-pulse rounded bg-muted" />

                    <div class="mt-5 flex items-center justify-between rounded-lg bg-muted px-3 py-2 text-sm">
                        <span class="text-muted-foreground">Your balance</span>
                        <span class="font-medium tabular-nums" :title="exactUsd(balanceNanos)">{{ formatUsd(balanceNanos) }}</span>
                    </div>

                    <p v-if="!canAfford" class="mt-3 text-sm text-rose-600">
                        You need {{ formatUsd(estimate?.charge.max) }} available to start this image.
                        <Link href="/billing" class="font-medium underline underline-offset-2">Buy credits</Link>
                    </p>
                    <p v-if="form.errors.credits" class="mt-3 text-sm text-rose-600">{{ form.errors.credits }}</p>

                    <button
                        type="submit"
                        :disabled="!canSubmit"
                        class="mt-4 w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {{ form.processing ? 'Starting...' : 'Generate image' }}
                    </button>
                </div>
            </aside>
        </form>
    </AppLayout>
</template>
