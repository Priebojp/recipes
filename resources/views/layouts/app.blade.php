<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main container class="max-w-6xl pb-24 lg:pb-8">
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
