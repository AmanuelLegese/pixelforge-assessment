<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Money {
    nano: number;
    display: string;
}
interface Estimate {
    min: Money;
    typical: Money;
    max: Money;
    affordable: boolean;
}

const props = defineProps<{ available: Money }>();

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const sizes = [
    { value: '1024x1024', label: 'Square', hint: '1024×1024' },
    { value: '1536x1024', label: 'Landscape', hint: '1536×1024' },
    { value: '1024x1536', label: 'Portrait', hint: '1024×1536' },
];
const qualities = ['low', 'medium', 'high'];

const estimate = ref<Estimate | null>(null);
const estimating = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let sequence = 0;

function xsrf(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

async function fetchEstimate() {
    const current = ++sequence; // ignore responses that arrive after a newer request
    if (!form.prompt.trim()) {
        estimate.value = null;
        estimating.value = false;
        return;
    }
    const response = await fetch(route('generations.estimate'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: JSON.stringify({ prompt: form.prompt, size: form.size, quality: form.quality, reference_images: form.reference_images }),
    });
    if (current !== sequence) return;
    estimate.value = response.ok ? await response.json() : null;
    estimating.value = false;
}

watch(
    () => [form.prompt, form.size, form.quality, [...form.reference_images]],
    () => {
        estimating.value = true;
        clearTimeout(timer);
        timer = setTimeout(fetchEstimate, 300);
    },
    { deep: true },
);

const canSubmit = computed(() => !form.processing && form.prompt.trim() !== '' && estimate.value !== null && estimate.value.affordable);

function submit() {
    form.post(route('generations.store'));
}

function buy(amount: number) {
    router.post(route('credits.store'), { amount }, { preserveScroll: true, only: ['available'] });
}
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="[{ title: 'Generate', href: '/generate' }]">
        <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-4">
            <form class="flex flex-col gap-6" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="prompt">Prompt</Label>
                    <textarea
                        id="prompt"
                        v-model="form.prompt"
                        rows="4"
                        maxlength="4000"
                        placeholder="A lighthouse at dusk, painted in watercolor…"
                        class="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:ring-1 focus-visible:outline-none"
                    />
                    <p v-if="form.errors.prompt" class="text-sm text-red-600">{{ form.errors.prompt }}</p>
                </div>

                <div class="grid gap-2">
                    <Label>Size</Label>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="size in sizes"
                            :key="size.value"
                            type="button"
                            class="rounded-md border px-3 py-2 text-left text-sm"
                            :class="form.size === size.value ? 'border-primary bg-primary/5' : 'hover:bg-muted'"
                            @click="form.size = size.value"
                        >
                            <div class="font-medium">{{ size.label }}</div>
                            <div class="text-muted-foreground text-xs">{{ size.hint }}</div>
                        </button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Quality</Label>
                    <div class="grid grid-cols-3 gap-2">
                        <button
                            v-for="quality in qualities"
                            :key="quality"
                            type="button"
                            class="rounded-md border px-3 py-2 text-sm capitalize"
                            :class="form.quality === quality ? 'border-primary bg-primary/5' : 'hover:bg-muted'"
                            @click="form.quality = quality"
                        >
                            {{ quality }}
                        </button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Reference images <span class="text-muted-foreground font-normal">(optional, up to 4 URLs)</span></Label>
                    <div v-for="(_, index) in form.reference_images" :key="index" class="flex gap-2">
                        <Input v-model="form.reference_images[index]" type="url" placeholder="https://…" />
                        <Button type="button" variant="outline" @click="form.reference_images.splice(index, 1)">Remove</Button>
                    </div>
                    <Button v-if="form.reference_images.length < 4" type="button" variant="outline" class="w-fit" @click="form.reference_images.push('')">
                        Add reference image
                    </Button>
                    <p v-if="form.errors.reference_images" class="text-sm text-red-600">{{ form.errors.reference_images }}</p>
                </div>

                <div class="rounded-lg border p-4">
                    <div class="flex items-baseline justify-between">
                        <span class="text-sm font-medium">Estimated cost</span>
                        <span class="text-lg font-semibold tabular-nums" :class="{ 'opacity-50': estimating }">
                            {{ estimate ? '≈ ' + estimate.typical.display : '—' }}
                        </span>
                    </div>
                    <p class="text-muted-foreground mt-1 text-xs">
                        <template v-if="estimate">
                            Usually between {{ estimate.min.display }} and {{ estimate.max.display }}. You are charged the exact amount when the image finishes; failed
                            generations are free.
                        </template>
                        <template v-else>Write a prompt to see what it will cost.</template>
                    </p>
                    <p class="text-muted-foreground mt-2 text-xs">
                        Available credits: <span class="font-medium tabular-nums">{{ props.available.display }}</span>
                    </p>
                </div>

                <div v-if="estimate && !estimate.affordable || form.errors.balance" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                    <p>{{ form.errors.balance ?? 'You do not have enough credits for the highest possible cost of this image.' }}</p>
                    <div class="mt-3 flex gap-2">
                        <Button v-for="amount in [5, 10, 25]" :key="amount" type="button" size="sm" variant="outline" @click="buy(amount)">Buy ${{ amount }}</Button>
                    </div>
                </div>

                <Button type="submit" :disabled="!canSubmit">{{ form.processing ? 'Submitting…' : 'Generate' }}</Button>
            </form>
        </div>
    </AppLayout>
</template>
