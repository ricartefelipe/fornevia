<section class="fv-card" aria-labelledby="media-title">
    <div class="px-6 pt-5">
        <h2 id="media-title" class="text-lg font-semibold">Imagens e documentos</h2>
        <p class="mt-1 text-sm text-slate-600">Imagens JPEG, PNG ou WebP até {{ $imageLimitMb }} MB; documentos PDF até {{ $documentLimitMb }} MB. Arquivos ficam em armazenamento privado.</p>
    </div>

    @if ($message)
        <div class="px-6 pt-4"><x-ui.alert type="success">{{ $message }}</x-ui.alert></div>
    @endif
    @error('media')
        <div class="px-6 pt-4"><x-ui.alert type="error">{{ $message }}</x-ui.alert></div>
    @enderror

    <div class="mt-4 overflow-x-auto">
        @if ($media->isEmpty())
            <div class="px-6 pb-6">
                <x-ui.empty-state title="Nenhum arquivo anexado" description="Imagens visíveis ao comprador aparecem no portal; documentos podem ser fichas técnicas ou manuais." />
            </div>
        @else
            <table class="fv-table">
                <thead>
                    <tr>
                        <th scope="col">Arquivo</th>
                        <th scope="col">Tipo</th>
                        <th scope="col">Tamanho</th>
                        <th scope="col">Comprador</th>
                        <th scope="col"><span class="sr-only">Ações</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($media as $item)
                        <tr wire:key="media-{{ $item->id }}">
                            <td>
                                @if ($downloads)
                                    <a href="{{ route('catalog.media.download', $item) }}" class="font-medium text-brand-700 hover:underline">{{ $item->original_name }}</a>
                                @else
                                    <span class="font-medium">{{ $item->original_name }}</span>
                                @endif
                                <br><span class="font-mono text-xs text-slate-500" title="SHA-256">{{ substr($item->sha256, 0, 12) }}…</span>
                            </td>
                            <td>{{ $item->kind->label() }}<br><span class="text-xs text-slate-500">{{ $item->mime_type }}</span></td>
                            <td>{{ number_format($item->size_bytes / 1024, 0, ',', '.') }} KB</td>
                            <td>{{ $item->visible_to_buyers ? 'Visível' : 'Somente equipe' }}</td>
                            <td class="text-right">
                                @if ($canEdit)
                                    <x-ui.button variant="danger" wire:click="deleteMedia('{{ $item->id }}')" wire:confirm="Remover este arquivo do produto?" wire:loading.attr="disabled">
                                        Remover<span class="sr-only"> {{ $item->original_name }}</span>
                                    </x-ui.button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($canEdit)
        <form wire:submit="uploadMedia" class="grid gap-4 border-t border-slate-200 p-6 sm:grid-cols-3" novalidate aria-labelledby="media-form-title">
            <h3 id="media-form-title" class="text-base font-semibold sm:col-span-3">Anexar arquivo</h3>
            <div>
                <label for="f-upload" class="fv-label">Arquivo</label>
                <input id="f-upload" type="file" wire:model="upload" accept="image/jpeg,image/png,image/webp,application/pdf"
                    @error('upload') aria-invalid="true" aria-describedby="f-upload-error" @enderror
                    class="mt-1 block w-full text-sm text-slate-700 file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-brand-800">
                <p class="mt-1 text-xs text-slate-500" wire:loading wire:target="upload">Enviando arquivo…</p>
                @error('upload')
                    <p id="f-upload-error" class="mt-1 text-xs text-red-700">{{ $message }}</p>
                @enderror
            </div>
            <x-ui.select label="Tipo" name="mediaKind" wire:model="mediaKind">
                @foreach ($kinds as $kind)
                    <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                @endforeach
            </x-ui.select>
            <label class="flex items-center gap-2 self-end pb-2 text-sm text-slate-700">
                <input type="checkbox" wire:model="visibleToBuyers" class="rounded border-slate-300 text-brand-700 focus:ring-brand-600">
                Visível ao comprador no portal
            </label>
            <div class="sm:col-span-3">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="upload,uploadMedia">
                    <span wire:loading.remove wire:target="uploadMedia">Anexar</span>
                    <span wire:loading wire:target="uploadMedia">Anexando…</span>
                </x-ui.button>
            </div>
        </form>
    @endif
</section>
