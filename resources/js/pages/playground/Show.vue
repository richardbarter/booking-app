<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { BookableService, BusinessSettings } from '@/types';

defineProps<{
    business: BusinessSettings;
    services: BookableService[];
}>();
</script>

<template>
    <Head title="Scheduling playground" />

    <div class="space-y-8">
        <header class="space-y-1">
            <p class="text-sm text-muted-foreground">Your playground</p>
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ business.name }}
            </h1>
        </header>

        <section aria-labelledby="services-heading" class="space-y-3">
            <h2 id="services-heading" class="text-lg font-medium">Services</h2>
            <ul class="divide-y divide-border rounded-lg border border-border">
                <li
                    v-for="service in services"
                    :key="service.id"
                    class="flex flex-wrap justify-between gap-x-4 px-4 py-3"
                >
                    <span>{{ service.name }}</span>
                    <span class="text-muted-foreground">
                        {{ service.durationMinutes }} minutes
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
