<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/playground/start';

defineProps<{
    lifetime: string;
}>();
</script>

<template>
    <Head title="Scheduling playground" />

    <div class="mx-auto max-w-2xl space-y-6">
        <h1 class="text-2xl font-semibold tracking-tight">
            Scheduling playground
        </h1>
        <div class="space-y-4 text-muted-foreground">
            <p>
                Bookalive chooses which appointment times to offer by scoring
                each one by how much unbookable time it would leave in the
                diary. A strictness slider sets how tightly the diary is packed.
            </p>
            <p>
                Try it on a sample business, a physio and sports massage
                therapist with three services. Book some appointments, move the
                slider and watch the offered times change.
            </p>
            <p>
                Your playground is yours alone and needs no sign-up. It ends
                after {{ lifetime }} without a visit.
            </p>
        </div>
        <Form v-bind="store.form()" v-slot="{ processing }">
            <Button type="submit" size="lg" :disabled="processing">
                <Spinner v-if="processing" />
                Start
            </Button>
        </Form>
    </div>
</template>
