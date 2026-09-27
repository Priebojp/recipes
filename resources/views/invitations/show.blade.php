<x-layouts::app :title="__('Pozvánka')">
    <div class="mx-auto max-w-md space-y-4">
        <flux:heading size="xl">{{ __('Pozvánka do domácnosti') }}</flux:heading>
        <flux:text>{!! __('Bol/a si pozvaný/á do domácnosti :household s rolou :role.', ['household' => '<strong>'.e($invitation->household->name).'</strong>', 'role' => e($invitation->role->label())]) !!}</flux:text>
        @if ($invitation->person)
            <flux:text>{!! __('Pozvánka je prepojená s profilom stravníka :person.', ['person' => '<strong>'.e($invitation->person->name).'</strong>']) !!}</flux:text>
        @endif
        <form method="POST" action="{{ route('invite.accept', $invitation->token) }}" class="space-y-3">
            @csrf
            @if ($invitation->person)
                <flux:checkbox name="link_person" value="1" checked :label="__('Prepojiť môj účet s týmto profilom')" />
            @endif
            <flux:button type="submit" variant="primary">{{ __('Prijať pozvánku') }}</flux:button>
        </form>
    </div>
</x-layouts::app>
