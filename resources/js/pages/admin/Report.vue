<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

interface Money {
    nano: number;
    display: string;
}
interface Row {
    generations: number;
    charged: Money;
    provider_cost: Money;
    profit: Money;
}
interface Day extends Row {
    day: string;
    provider_reported: Money | null;
    users: (Row & { name: string; email: string })[];
}

defineProps<{ days: Day[]; totals: Omit<Row, 'generations'> }>();
</script>

<template>
    <Head title="Report" />

    <AppLayout :breadcrumbs="[{ title: 'Admin report', href: '/admin/report' }]">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4">
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-lg border p-4"><div class="text-muted-foreground text-xs">Credit spend (charged)</div><div class="text-xl font-semibold tabular-nums">{{ totals.charged.display }}</div></div>
                <div class="rounded-lg border p-4"><div class="text-muted-foreground text-xs">Provider cost</div><div class="text-xl font-semibold tabular-nums">{{ totals.provider_cost.display }}</div></div>
                <div class="rounded-lg border p-4"><div class="text-muted-foreground text-xs">Profit</div><div class="text-xl font-semibold tabular-nums">{{ totals.profit.display }}</div></div>
            </div>

            <p class="text-muted-foreground text-xs">Days are UTC and use the day a generation completed, like Pixelforge's usage report.</p>

            <section v-for="day in days" :key="day.day" class="rounded-lg border">
                <header class="flex flex-wrap items-baseline justify-between gap-2 border-b p-3">
                    <h2 class="font-medium">{{ day.day }}</h2>
                    <span
                        v-if="day.provider_reported"
                        class="text-xs tabular-nums"
                        :class="day.provider_reported.nano === day.provider_cost.nano ? 'text-green-700' : 'font-semibold text-red-700'"
                    >
                        Pixelforge reports {{ day.provider_reported.display }}
                        {{ day.provider_reported.nano === day.provider_cost.nano ? '✓ matches' : '✗ differs' }}
                    </span>
                </header>
                <table class="w-full text-sm tabular-nums">
                    <thead class="text-muted-foreground text-xs">
                        <tr><th class="p-2 text-left font-normal">User</th><th class="p-2 text-right font-normal">Generations</th><th class="p-2 text-right font-normal">Charged</th><th class="p-2 text-right font-normal">Provider cost</th><th class="p-2 text-right font-normal">Profit</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="u in day.users" :key="u.email" class="border-t">
                            <td class="p-2">{{ u.name }} <span class="text-muted-foreground text-xs">{{ u.email }}</span></td>
                            <td class="p-2 text-right">{{ u.generations }}</td>
                            <td class="p-2 text-right">{{ u.charged.display }}</td>
                            <td class="p-2 text-right">{{ u.provider_cost.display }}</td>
                            <td class="p-2 text-right">{{ u.profit.display }}</td>
                        </tr>
                        <tr class="border-t font-medium">
                            <td class="p-2">Day total</td>
                            <td class="p-2 text-right">{{ day.generations }}</td>
                            <td class="p-2 text-right">{{ day.charged.display }}</td>
                            <td class="p-2 text-right">{{ day.provider_cost.display }}</td>
                            <td class="p-2 text-right">{{ day.profit.display }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </AppLayout>
</template>
