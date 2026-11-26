<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Toaster } from '@/components/ui/sonner';
import { home } from '@/routes';

const name = usePage().props.name;
</script>

<template>
    <div class="flex min-h-svh flex-col bg-background text-foreground">
        <header class="border-b border-border">
            <div class="mx-auto flex h-14 w-full max-w-5xl items-center px-4">
                <Link
                    :href="home()"
                    class="font-semibold tracking-tight underline-offset-4 hover:underline"
                >
                    {{ name }}
                </Link>
            </div>
        </header>
        <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8">
            <slot />
        </main>
        <Toaster />
    </div>
</template>
