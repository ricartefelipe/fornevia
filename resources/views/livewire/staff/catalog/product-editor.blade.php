<div>
    <x-ui.page-header :title="$product ? $product->name : 'Novo produto'"
        :description="$product ? 'Dados do produto, situação comercial, SKUs e mídias.' : 'O produto começa como rascunho; cadastre os SKUs e ative-o depois.'">
        <x-slot:actions>
            <a href="{{ route('catalog.products') }}" class="fv-btn-secondary">Voltar ao catálogo</a>
        </x-slot:actions>
    </x-ui.page-header>

    @include('livewire.staff.catalog.partials.nav')

    @if ($message)
        <x-ui.alert type="success" class="mb-4">{{ $message }}</x-ui.alert>
    @endif

    @unless ($canManage)
        <x-ui.alert type="info" class="mb-4">Seu perfil permite apenas consultar o catálogo. Alterações exigem a permissão de gerenciar catálogo.</x-ui.alert>
    @endunless

    @if ($product?->status === \App\Enums\Catalog\ProductStatus::Archived)
        <x-ui.alert type="warning" class="mb-4">Produto arquivado em {{ $product->archived_at?->setTimezone(config('app.business_timezone'))->format('d/m/Y H:i') }}. Os dados ficam preservados apenas para o histórico.</x-ui.alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="fv-card p-6 lg:col-span-2" aria-labelledby="product-data-title">
            <h2 id="product-data-title" class="text-lg font-semibold">Dados do produto</h2>
            <form wire:submit="save" class="mt-4 grid gap-4 sm:grid-cols-2" novalidate>
                <div class="sm:col-span-2"><x-ui.input label="Nome" name="name" wire:model="name" required maxlength="200" /></div>
                <x-ui.input label="Endereço (slug)" name="slug" wire:model="slug" maxlength="220"
                    hint="Opcional. Gerado a partir do nome quando vazio." />
                <x-ui.input label="Marca" name="brand" wire:model="brand" maxlength="80" />
                <x-ui.select label="Categoria" name="categoryId" wire:model="categoryId" placeholder="Sem categoria"
                    :options="$categories->pluck('name', 'id')->all()" />
                <x-ui.select label="Unidade base (estoque)" name="baseUnitId" wire:model="baseUnitId" placeholder="Selecione" required>
                    @foreach ($units as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->code }} — {{ $unit->name }}</option>
                    @endforeach
                </x-ui.select>
                <x-ui.input label="NCM" name="ncm" wire:model="ncm" inputmode="numeric" maxlength="10" hint="Opcional, 8 dígitos (ex.: 1905.90.90)." />
                <x-ui.select label="Visibilidade" name="visibility" wire:model="visibility">
                    @foreach ($visibilities as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
                <div class="sm:col-span-2">
                    <x-ui.textarea label="Descrição" name="description" wire:model="description" rows="4" maxlength="5000" />
                </div>
                <fieldset class="sm:col-span-2">
                    <legend class="fv-label">Encomenda</legend>
                    <label class="mt-2 flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model.live="allowBackorder" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                        Admite venda sob encomenda (sem estoque disponível)
                    </label>
                    @if ($allowBackorder)
                        <div class="mt-3 max-w-xs">
                            <x-ui.input label="Prazo da encomenda (dias)" name="backorderLeadDays" type="number" min="1" max="365" wire:model="backorderLeadDays" />
                        </div>
                    @endif
                </fieldset>
                @if ($canManage && $product?->status !== \App\Enums\Catalog\ProductStatus::Archived)
                    <div class="sm:col-span-2">
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">{{ $product ? 'Salvar alterações' : 'Cadastrar produto' }}</span>
                            <span wire:loading wire:target="save">Salvando…</span>
                        </x-ui.button>
                    </div>
                @endif
            </form>
        </section>

        @if ($product)
            <aside class="space-y-6">
                <section class="fv-card p-6" aria-labelledby="product-status-title">
                    <h2 id="product-status-title" class="text-lg font-semibold">Situação</h2>
                    <p class="mt-2 text-sm">
                        Situação atual:
                        <x-ui.badge :color="match ($product->status->value) { 'active' => 'green', 'draft' => 'amber', 'archived' => 'slate', default => 'red' }">{{ $product->status->label() }}</x-ui.badge>
                    </p>
                    <p class="mt-1 text-sm text-slate-600">{{ $product->skus_count }} SKU(s) · unidade base {{ $product->baseUnit->code }}</p>
                    @error('status')
                        <x-ui.alert type="error" class="mt-3">{{ $message }}</x-ui.alert>
                    @enderror
                    @if ($canManage && $transitions !== [])
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach ($transitions as $transition)
                                @if ($transition === \App\Enums\Catalog\ProductStatus::Archived)
                                    <x-ui.button variant="secondary" wire:click="changeStatus('{{ $transition->value }}')"
                                        wire:confirm="Arquivar o produto? Os SKUs também serão arquivados e a operação não pode ser desfeita."
                                        wire:loading.attr="disabled">Arquivar</x-ui.button>
                                @else
                                    <x-ui.button variant="secondary" wire:click="changeStatus('{{ $transition->value }}')" wire:loading.attr="disabled">
                                        {{ $transition === \App\Enums\Catalog\ProductStatus::Active ? 'Ativar' : 'Inativar' }}
                                    </x-ui.button>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </section>

                @if ($canManage && $product->status !== \App\Enums\Catalog\ProductStatus::Archived)
                    <section class="fv-card p-6" aria-labelledby="product-delete-title">
                        <h2 id="product-delete-title" class="text-lg font-semibold">Excluir produto</h2>
                        <p class="mt-2 text-sm text-slate-600">Somente para cadastros sem histórico. Produtos com custos, preços ou pedidos devem ser arquivados.</p>
                        @error('delete')
                            <x-ui.alert type="error" class="mt-3">{{ $message }}</x-ui.alert>
                        @enderror
                        <x-ui.button variant="danger" class="mt-4" wire:click="delete" wire:confirm="Excluir definitivamente este produto, seus SKUs e mídias?" wire:loading.attr="disabled">Excluir</x-ui.button>
                    </section>
                @endif
            </aside>
        @endif
    </div>

    @if ($product)
        <div class="mt-6 space-y-6">
            <livewire:staff.catalog.product-skus :product-id="$product->id" wire:key="skus-{{ $product->id }}" />
            <livewire:staff.catalog.product-media-manager :product-id="$product->id" wire:key="media-{{ $product->id }}" />
        </div>
    @endif
</div>
