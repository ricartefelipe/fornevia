<div>
    <x-ui.page-header :title="$ruleId === null ? 'Nova regra de aprovação' : 'Editar regra de aprovação'"
        description="Escolha quando a regra se aplica e quem aprova cada nível. Os níveis são aprovados em sequência.">
        <x-slot:actions>
            <a href="{{ route('portal.approval-rules') }}" class="fv-btn-secondary">Voltar às regras</a>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.alert type="info" class="mb-6">
        As alterações valem para os pedidos enviados a partir de agora. Pedidos que já aguardam aprovação mantêm os níveis e aprovadores definidos no envio.
    </x-ui.alert>

    <form wire:submit="save" class="space-y-6" novalidate>
        <section class="fv-card p-5" aria-labelledby="regra-identificacao">
            <h2 id="regra-identificacao" class="text-lg font-semibold">Identificação</h2>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Nome da regra" name="name" wire:model="name" maxlength="120" autocomplete="off" required />
                <x-ui.input label="Prioridade" name="priority" type="number" min="-1000" max="1000" step="1" wire:model="priority" required
                    hint="De -1000 a 1000. Quando mais de uma regra se aplica ao pedido, vale a de maior prioridade; no empate, a criada primeiro." />
            </div>
            <label class="mt-4 flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                Regra ativa (considerada nos novos pedidos)
            </label>
        </section>

        <section class="fv-card p-5" aria-labelledby="regra-criterios" aria-describedby="regra-criterios-ajuda">
            <h2 id="regra-criterios" class="text-lg font-semibold">Critérios</h2>
            <p id="regra-criterios-ajuda" class="mt-1 text-sm text-slate-600">
                A regra se aplica ao pedido que atende a todos os critérios preenchidos. Sem critérios, ela se aplica a todos os pedidos.
            </p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <x-ui.input label="Valor mínimo do pedido (R$)" name="minTotal" wire:model="minTotal" inputmode="decimal" maxlength="25" autocomplete="off"
                    hint="Pedidos com total igual ou maior, por exemplo 5.000,00. Em branco: qualquer valor." />
                <x-ui.select label="Perfil do solicitante" name="requesterRoleKey" wire:model="requesterRoleKey" :options="$roleOptions" placeholder="Qualquer perfil" />
                <x-ui.select label="Centro de custo" name="costCenterId" wire:model="costCenterId" :options="$costCenterOptions" placeholder="Qualquer centro de custo" />
                <x-ui.select label="Unidade" name="buyerUnitId" wire:model="buyerUnitId" :options="$unitOptions" placeholder="Qualquer unidade" />
                <div class="sm:col-span-2">
                    <x-ui.select label="Categoria do catálogo" name="categoryId" wire:model="categoryId" :options="$categoryOptions" placeholder="Qualquer categoria"
                        :aria-describedby="$errors->has('categoryId') ? null : 'regra-categoria-ajuda'" />
                    @unless ($errors->has('categoryId'))
                        <p id="regra-categoria-ajuda" class="mt-1 text-xs text-slate-500">O pedido atende ao critério quando tem ao menos um produto da categoria escolhida (subcategorias não são incluídas).</p>
                    @endunless
                </div>
            </div>
        </section>

        <section class="fv-card p-5" aria-labelledby="regra-niveis">
            <h2 id="regra-niveis" class="text-lg font-semibold">Níveis de aprovação</h2>
            <p class="mt-1 text-sm text-slate-600">
                Cada nível precisa do quórum de aprovações distintas antes de o próximo começar; uma rejeição em qualquer nível encerra o pedido.
                Quem solicitou a compra não aprova o próprio pedido, nem como delegado.
            </p>

            @error('levels')
                <x-ui.alert type="error" class="mt-3">{{ $message }}</x-ui.alert>
            @enderror

            @if ($eligible === [])
                <x-ui.alert type="warning" class="mt-3">
                    Nenhum usuário ativo da empresa tem permissão para aprovar compras. Inclua aprovadores em Usuários da empresa antes de criar a regra.
                </x-ui.alert>
            @endif

            <div class="mt-4 space-y-4">
                @foreach ($levels as $index => $level)
                    @php($number = $index + 1)
                    @php($approversError = $errors->first('levels.'.$index.'.approvers'))
                    <fieldset wire:key="nivel-{{ $index }}" class="rounded-md border border-slate-200 p-4">
                        <legend class="px-1 text-base font-semibold">Nível {{ $number }}</legend>

                        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                            <div class="sm:w-64">
                                <x-ui.input :label="'Quórum do nível '.$number" name="levels.{{ $index }}.required" type="number" min="1" max="{{ $maxRequired }}" step="1"
                                    wire:model="levels.{{ $index }}.required" required hint="Aprovações distintas necessárias, de 1 a {{ $maxRequired }}." />
                            </div>
                            @if (count($levels) > 1)
                                <x-ui.button variant="secondary" wire:click="removeLevel({{ $index }})">Remover nível {{ $number }}</x-ui.button>
                            @endif
                        </div>

                        <fieldset class="mt-4" @if ($approversError) aria-describedby="nivel-{{ $index }}-aprovadores-erro" @endif>
                            <legend class="fv-label">Aprovadores do nível {{ $number }}</legend>
                            <ul class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($eligible as $option)
                                    <li wire:key="nivel-{{ $index }}-aprovador-{{ $option->membershipId }}">
                                        <label class="flex items-start gap-2 text-sm text-slate-800">
                                            <input type="checkbox" value="{{ $option->membershipId }}" wire:model="levels.{{ $index }}.approvers"
                                                class="mt-0.5 rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                                            <span>
                                                {{ $option->name }}
                                                <span class="block text-xs text-slate-500">{{ $option->roleName }} · {{ $option->email }}</span>
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                                @foreach ($withoutAccess as $option)
                                    @if (in_array($option->membershipId, $level['approvers'], true))
                                        <li wire:key="nivel-{{ $index }}-sem-acesso-{{ $option->membershipId }}">
                                            <label class="flex items-start gap-2 text-sm text-slate-800">
                                                <input type="checkbox" value="{{ $option->membershipId }}" wire:model="levels.{{ $index }}.approvers"
                                                    class="mt-0.5 rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                                                <span>
                                                    {{ $option->name }}
                                                    <span class="block text-xs text-red-700">Sem acesso para aprovar: desmarque para salvar a regra.</span>
                                                </span>
                                            </label>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                            @if ($approversError)
                                <p id="nivel-{{ $index }}-aprovadores-erro" class="mt-1 text-xs text-red-700">{{ $approversError }}</p>
                            @endif
                        </fieldset>
                    </fieldset>
                @endforeach
            </div>

            @if (count($levels) < $maxLevels)
                <div class="mt-4">
                    <x-ui.button variant="secondary" wire:click="addLevel">Adicionar nível</x-ui.button>
                </div>
            @endif
        </section>

        <div class="flex flex-wrap gap-2">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $ruleId === null ? 'Criar regra' : 'Salvar alterações' }}</x-ui.button>
            <a href="{{ route('portal.approval-rules') }}" class="fv-btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
