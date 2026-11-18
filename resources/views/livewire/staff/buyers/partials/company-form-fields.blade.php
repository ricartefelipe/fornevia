{{-- Campos do cadastro da empresa compradora (propriedade "form" do componente). --}}
<div class="grid gap-4 sm:grid-cols-2">
    <div class="sm:col-span-2"><x-ui.input label="Razão social" name="form.legalName" wire:model="form.legalName" required maxlength="200" autocomplete="organization" /></div>
    <x-ui.input label="Nome fantasia" name="form.tradeName" wire:model="form.tradeName" required maxlength="160" />
    <x-ui.input label="CNPJ" name="form.document" wire:model="form.document" required inputmode="numeric" maxlength="18" hint="Com ou sem pontuação." />
    <x-ui.input label="Inscrição estadual" name="form.stateRegistration" wire:model="form.stateRegistration" maxlength="20" hint="Opcional. Use ISENTO quando aplicável." />
    <x-ui.input label="E-mail" name="form.email" type="email" wire:model="form.email" maxlength="254" autocomplete="email" />
    <x-ui.input label="Telefone" name="form.phone" type="tel" wire:model="form.phone" maxlength="20" autocomplete="tel" />
    <x-ui.select label="Grupo de clientes" name="form.customerGroupId" wire:model="form.customerGroupId" placeholder="Sem grupo" :options="$groupOptions" />
    <x-ui.select label="Representante responsável" name="form.salesRepMembershipId" wire:model="form.salesRepMembershipId" placeholder="Sem representante" :options="$salesRepOptions" />
    <div class="sm:col-span-2"><x-ui.textarea label="Observações" name="form.notes" wire:model="form.notes" rows="3" maxlength="2000"></x-ui.textarea></div>
</div>
