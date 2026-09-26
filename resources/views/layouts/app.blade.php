<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main container class="max-w-6xl pb-24 lg:pb-8">
        {{ $slot }}
        @include('partials.footer', ['class' => 'mt-12 !px-0'])
    </flux:main>
</x-layouts::app.sidebar>
