<flux:card class="space-y-4">
    <div>
        <flux:heading size="lg" class="font-display">Účty v domácnosti</flux:heading>
        <flux:text class="text-sm">Vlastník spravuje domácnosť, editor upravuje recepty a plány, člen spravuje svoje chute a prezerá obsah.</flux:text>
    </div>

    <x-confirm-modal name="remove-member" title="Odobrať účet z domácnosti?" text="Používateľ stratí prístup k receptom a plánom. Jeho profil stravníka zostane." confirm="Odobrať" action="remove" />

    <flux:table>
        <flux:table.columns>
            <flux:table.column>Účet</flux:table.column>
            <flux:table.column>Rola</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->members as $membership)
                <flux:table.row :key="'member-'.$membership->id">
                    <flux:table.cell class="max-w-56">
                        <div class="truncate font-medium">{{ $membership->user?->name }}</div>
                        <div class="truncate text-xs text-zinc-500">{{ $membership->user?->email }}</div>
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($this->canManage && $membership->user_id !== $this->household->owner_user_id)
                            <flux:select size="sm" wire:change="setRole({{ $membership->id }}, $event.target.value)" class="w-32">
                                <flux:select.option value="editor" :selected="$membership->role->value === 'editor'">Editor</flux:select.option>
                                <flux:select.option value="member" :selected="$membership->role->value === 'member'">Člen</flux:select.option>
                            </flux:select>
                        @else
                            <flux:badge size="sm" color="orange" variant="pill">{{ $membership->role->label() }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($this->canManage && $membership->user_id !== $this->household->owner_user_id)
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="askRemove({{ $membership->id }})" aria-label="Odobrať" data-test="remove-member-{{ $membership->id }}" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    @if ($this->canManage)
        <flux:separator />

        <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
            <flux:select wire:model="role" label="Rola" class="sm:w-32">
                <flux:select.option value="member">Člen</flux:select.option>
                <flux:select.option value="editor">Editor</flux:select.option>
            </flux:select>
            <flux:select wire:model="personId" label="Prepojiť s profilom (voliteľné)" class="flex-1">
                <flux:select.option value="">Bez prepojenia</flux:select.option>
                @foreach ($this->unlinkedPeople as $person)
                    <flux:select.option :value="$person->id">{{ $person->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button wire:click="create" size="sm" variant="primary" icon="link">Vytvoriť pozvánku</flux:button>
        </div>

        @if ($createdLink)
            <flux:callout icon="link" variant="success">
                <flux:callout.heading>Pozvánka platí 7 dní a dá sa použiť raz</flux:callout.heading>
                <flux:callout.text><flux:input :value="$createdLink" readonly copyable /></flux:callout.text>
            </flux:callout>
        @endif

        @foreach ($this->invitations as $invitation)
            <div class="flex items-center gap-2 text-sm">
                <span class="flex-1 truncate">{{ $invitation->role->label() }}@if($invitation->person) · {{ $invitation->person->name }}@endif · platí do {{ $invitation->expires_at->format('j. n.') }}</span>
                <flux:input size="sm" :value="route('invite.show', $invitation->token)" readonly copyable class="w-56" />
                <flux:button size="xs" variant="ghost" icon="trash" wire:click="revoke({{ $invitation->id }})" aria-label="Zrušiť pozvánku" />
            </div>
        @endforeach
    @endif
</flux:card>
