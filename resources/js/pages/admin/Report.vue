<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd, formatUsd } from '@/lib/money';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

type Money = { charged_nanos: number; cost_nanos: number; profit_nanos: number; generations: number };
type Day = Money & { day: string; pixelforge_cost_nanos: number | null; pixelforge_generations: number | null; matches: boolean | null };
type UserRow = Money & { user_id: number; name: string; email: string };
type Row = UserRow & { day: string };

const props = defineProps<{
    range: { start_date: string; end_date: string };
    days: Day[];
    users: UserRow[];
    rows: Row[];
    totals: Money & { pixelforge_cost_nanos: number | null };
    usageError: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Operator report', href: '/admin/report' }];

const filters = reactive({ ...props.range });

function apply() {
    router.get('/admin/report', filters, { preserveState: true });
}
</script>

<template>
    <Head title="Operator report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-8 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Operator report</h1>
                    <p class="mt-1 max-w-prose text-sm text-muted-foreground">
                        Days are UTC and follow when each image finished, the same way Pixelforge bills, so provider cost can be checked against
                        GET /v1/usage line by line.
                    </p>
                </div>
                <form class="flex items-end gap-2 text-sm" @submit.prevent="apply">
                    <label class="flex flex-col gap-1">
                        <span class="text-muted-foreground">From</span>
                        <input v-model="filters.start_date" type="date" class="h-9 rounded-lg border border-input bg-background px-2" />
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-muted-foreground">To</span>
                        <input v-model="filters.end_date" type="date" class="h-9 rounded-lg border border-input bg-background px-2" />
                    </label>
                    <button class="h-9 rounded-lg bg-primary px-3 font-medium text-primary-foreground">Show</button>
                </form>
            </div>

            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">Charged to users</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums" :title="exactUsd(totals.charged_nanos)">{{ formatUsd(totals.charged_nanos) }}</dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">Pixelforge cost (our records)</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums" :title="exactUsd(totals.cost_nanos)">{{ formatUsd(totals.cost_nanos) }}</dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">Pixelforge cost (their report)</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums" :title="exactUsd(totals.pixelforge_cost_nanos)">
                        {{ totals.pixelforge_cost_nanos === null ? 'Unavailable' : formatUsd(totals.pixelforge_cost_nanos) }}
                    </dd>
                </div>
                <div class="rounded-xl border p-4">
                    <dt class="text-sm text-muted-foreground">Profit</dt>
                    <dd class="mt-1 text-2xl font-semibold tabular-nums" :title="exactUsd(totals.profit_nanos)">{{ formatUsd(totals.profit_nanos) }}</dd>
                </div>
            </dl>

            <p v-if="usageError" class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">{{ usageError }}</p>

            <section>
                <h2 class="text-lg font-semibold">By day</h2>
                <div class="mt-3 overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Day (UTC)</th>
                                <th class="px-4 py-2 text-right font-medium">Images</th>
                                <th class="px-4 py-2 text-right font-medium">Charged</th>
                                <th class="px-4 py-2 text-right font-medium">Cost</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                                <th class="px-4 py-2 text-right font-medium">Pixelforge says</th>
                                <th class="px-4 py-2 font-medium">Check</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y tabular-nums">
                            <tr v-for="d in days" :key="d.day">
                                <td class="px-4 py-2 whitespace-nowrap">{{ d.day }}</td>
                                <td class="px-4 py-2 text-right">{{ d.generations }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(d.charged_nanos)">{{ formatUsd(d.charged_nanos) }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(d.cost_nanos)">{{ exactUsd(d.cost_nanos) }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(d.profit_nanos)">{{ formatUsd(d.profit_nanos) }}</td>
                                <td class="px-4 py-2 text-right">{{ d.pixelforge_cost_nanos === null ? '' : exactUsd(d.pixelforge_cost_nanos) }}</td>
                                <td class="px-4 py-2">
                                    <span v-if="d.matches === true" class="text-emerald-700 dark:text-emerald-400">Matches</span>
                                    <span v-else-if="d.matches === false" class="font-medium text-rose-600">
                                        Off by {{ exactUsd((d.pixelforge_cost_nanos ?? 0) - d.cost_nanos) }}
                                    </span>
                                </td>
                            </tr>
                            <tr v-if="days.length === 0">
                                <td colspan="7" class="px-4 py-6 text-center text-muted-foreground">No finished images in this range.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section>
                <h2 class="text-lg font-semibold">By user</h2>
                <div class="mt-3 overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">User</th>
                                <th class="px-4 py-2 text-right font-medium">Images</th>
                                <th class="px-4 py-2 text-right font-medium">Charged</th>
                                <th class="px-4 py-2 text-right font-medium">Cost</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y tabular-nums">
                            <tr v-for="u in users" :key="u.user_id">
                                <td class="px-4 py-2">{{ u.name }} <span class="text-muted-foreground">{{ u.email }}</span></td>
                                <td class="px-4 py-2 text-right">{{ u.generations }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(u.charged_nanos)">{{ formatUsd(u.charged_nanos) }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(u.cost_nanos)">{{ formatUsd(u.cost_nanos) }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(u.profit_nanos)">{{ formatUsd(u.profit_nanos) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section>
                <h2 class="text-lg font-semibold">By day and user</h2>
                <div class="mt-3 overflow-x-auto rounded-xl border">
                    <table class="w-full text-sm">
                        <thead class="bg-muted/50 text-left text-muted-foreground">
                            <tr>
                                <th class="px-4 py-2 font-medium">Day (UTC)</th>
                                <th class="px-4 py-2 font-medium">User</th>
                                <th class="px-4 py-2 text-right font-medium">Images</th>
                                <th class="px-4 py-2 text-right font-medium">Charged</th>
                                <th class="px-4 py-2 text-right font-medium">Cost</th>
                                <th class="px-4 py-2 text-right font-medium">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y tabular-nums">
                            <tr v-for="r in rows" :key="`${r.day}-${r.user_id}`">
                                <td class="px-4 py-2 whitespace-nowrap">{{ r.day }}</td>
                                <td class="px-4 py-2">{{ r.email }}</td>
                                <td class="px-4 py-2 text-right">{{ r.generations }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(r.charged_nanos)">{{ formatUsd(r.charged_nanos) }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(r.cost_nanos)">{{ formatUsd(r.cost_nanos) }}</td>
                                <td class="px-4 py-2 text-right" :title="exactUsd(r.profit_nanos)">{{ formatUsd(r.profit_nanos) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
