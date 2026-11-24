<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';

interface Money {
    nano: number;
    display: string;
}
interface Generation {
    id: number;
    prompt: string;
    size: string;
    quality: string;
    status: 'queued' | 'processing' | 'succeeded' | 'failed';
    estimated_cost: Money;
    charged: Money | null;
    image_url: string | null;
    image_expired: boolean;
    error: string | null;
    created_at: string;
}

const props = defineProps<{ generations: Generation[]; available: Money }>();

const hasActive = computed(() => props.generations.some((g) => g.status === 'queued' || g.status === 'processing'));

// Live status: refresh while anything is unfinished.
let interval: ReturnType<typeof setInterval>;
onMounted(() => {
    interval = setInterval(() => {
        if (hasActive.value) router.reload({ only: ['generations', 'available'] });
    }, 3000);
});
onBeforeUnmount(() => clearInterval(interval));

const labels = { queued: 'Queued', processing: 'Processing', succeeded: 'Done', failed: 'Failed' };
const colors = {
    queued: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    processing: 'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-200',
    succeeded: 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-200',
    failed: 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-200',
};

function buy(amount: number) {
    router.post(route('credits.store'), { amount }, { preserveScroll: true, only: ['available'] });
}
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="[{ title: 'History', href: '/generations' }]">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-4 p-4">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
                <div>
                    <div class="text-muted-foreground text-xs">Available credits</div>
                    <div class="text-xl font-semibold tabular-nums">{{ available.display }}</div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-muted-foreground text-sm">Buy credits</span>
                    <Button v-for="amount in [5, 10, 25]" :key="amount" size="sm" variant="outline" @click="buy(amount)">${{ amount }}</Button>
                    <Button as-child size="sm"><Link :href="route('generations.create')">New image</Link></Button>
                </div>
            </div>

            <p v-if="generations.length === 0" class="text-muted-foreground py-12 text-center text-sm">
                Nothing yet. <Link :href="route('generations.create')" class="underline">Generate your first image</Link>.
            </p>

            <ul class="flex flex-col gap-3">
                <li v-for="g in generations" :key="g.id" class="flex gap-4 rounded-lg border p-3">
                    <div class="bg-muted flex size-28 shrink-0 items-center justify-center overflow-hidden rounded-md text-center text-xs">
                        <a v-if="g.image_url" :href="g.image_url" target="_blank" rel="noopener"><img :src="g.image_url" :alt="g.prompt" class="size-28 object-cover" /></a>
                        <span v-else-if="g.image_expired" class="text-muted-foreground px-2">Image link expired</span>
                        <span v-else-if="g.status === 'failed'" class="text-muted-foreground px-2">No image</span>
                        <span v-else class="text-muted-foreground animate-pulse px-2">Generating…</span>
                    </div>

                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div class="flex items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="colors[g.status]">{{ labels[g.status] }}</span>
                            <span class="text-muted-foreground text-xs">{{ g.size }} · {{ g.quality }}</span>
                        </div>
                        <p class="line-clamp-2 text-sm">{{ g.prompt }}</p>
                        <p v-if="g.status === 'failed'" class="text-sm text-red-700 dark:text-red-300">{{ g.error }} You were not charged.</p>
                        <div class="text-muted-foreground mt-auto text-xs tabular-nums">
                            <template v-if="g.status === 'succeeded' && g.charged">Charged {{ g.charged.display }} · estimated {{ g.estimated_cost.display }}</template>
                            <template v-else-if="g.status === 'failed'">Cost $0.00</template>
                            <template v-else>Estimated {{ g.estimated_cost.display }} · final cost is shown when it finishes</template>
                        </div>
                    </div>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
