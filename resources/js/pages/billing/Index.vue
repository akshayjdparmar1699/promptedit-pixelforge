<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd, formatUsd } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    balanceNanos: number;
    packs: number[];
    transactions: { id: number; type: string; description: string; amount_nanos: number; balance_after_nanos: number; created_at: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: '/billing' }];

const buying = ref<number | null>(null);

function buy(dollars: number) {
    buying.value = dollars;
    router.post('/billing/purchase', { dollars }, { preserveScroll: true, onFinish: () => (buying.value = null) });
}
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex max-w-3xl flex-col gap-8 p-4 md:p-6">
            <section>
                <h1 class="text-2xl font-semibold tracking-tight">Credits</h1>
                <p class="mt-1 text-sm text-muted-foreground">1 credit is 1 US dollar. This is a demo, so no card is charged.</p>

                <p class="mt-6 text-sm text-muted-foreground">Available balance</p>
                <p class="text-4xl font-semibold tracking-tight tabular-nums" :title="exactUsd(balanceNanos)">{{ formatUsd(balanceNanos) }}</p>

                <div class="mt-5 flex flex-wrap gap-2">
                    <button
                        v-for="pack in packs"
                        :key="pack"
                        :disabled="buying !== null"
                        class="rounded-lg border border-input px-4 py-2 text-sm font-medium hover:bg-accent disabled:opacity-50"
                        @click="buy(pack)"
                    >
                        {{ buying === pack ? 'Adding...' : `Buy $${pack}` }}
                    </button>
                </div>
            </section>

            <section>
                <h2 class="text-lg font-semibold">Activity</h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Starting an image holds its highest possible price. When it finishes, the unused part comes back; failed images are refunded in full.
                </p>

                <div v-if="transactions.length" class="mt-4 overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">When</th>
                                <th class="px-4 py-2 font-medium">What</th>
                                <th class="px-4 py-2 text-right font-medium">Amount</th>
                                <th class="px-4 py-2 text-right font-medium">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="t in transactions" :key="t.id">
                                <td class="px-4 py-2 whitespace-nowrap text-muted-foreground">{{ new Date(t.created_at).toLocaleString() }}</td>
                                <td class="px-4 py-2">{{ t.description }}</td>
                                <td
                                    class="px-4 py-2 text-right tabular-nums"
                                    :class="t.amount_nanos < 0 ? '' : 'text-emerald-700 dark:text-emerald-400'"
                                    :title="exactUsd(t.amount_nanos)"
                                >
                                    {{ t.amount_nanos > 0 ? '+' : '' }}{{ formatUsd(t.amount_nanos) }}
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums text-muted-foreground" :title="exactUsd(t.balance_after_nanos)">
                                    {{ formatUsd(t.balance_after_nanos) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-else class="mt-4 text-sm text-muted-foreground">No activity yet. Buy credits to start creating images.</p>
            </section>
        </div>
    </AppLayout>
</template>
