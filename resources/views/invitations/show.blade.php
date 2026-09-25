<x-layouts::app :title="'Pozvánka'">
    <div class="mx-auto max-w-md space-y-4">
        <flux:heading size="xl">Pozvánka do domácnosti</flux:heading>
        <flux:text>Bol/a si pozvaný/á do domácnosti <strong>{{ $invitation->household->name }}</strong> s rolou {{ $invitation->role->label() }}.</flux:text>
        @if ($invitation->person)
            <flux:text>Pozvánka je prepojená s profilom stravníka <strong>{{ $invitation->person->name }}</strong>.</flux:text>
        @endif
        <form method="POST" action="{{ route('invite.accept', $invitation->token) }}" class="space-y-3">
            @csrf
            @if ($invitation->person)
                <flux:checkbox name="link_person" value="1" checked label="Prepojiť môj účet s týmto profilom" />
            @endif
            <flux:button type="submit" variant="primary">Prijať pozvánku</flux:button>
        </form>
    </div>
</x-layouts::app>
