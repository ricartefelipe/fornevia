<?php

declare(strict_types=1);

use App\Enums\Catalog\ProductStatus;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Catalog\ProductMediaManager;
use App\Livewire\Staff\Catalog\ProductSkus;
use App\Models\Catalog\Sku;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

/*
| Proteções das telas de catálogo: envio temporário de arquivo só para quem
| altera o catálogo e valores fora do intervalo como erro de campo.
*/

beforeEach(function (): void {
    Storage::fake('private');

    $this->asMember = fn (Membership $membership, Closure $callback): mixed => $this->tenant->actingAs(
        $membership,
        static function () use ($membership, $callback): mixed {
            Livewire::actingAs(User::query()->findOrFail($membership->user_id));

            return $callback();
        },
    );

    $this->tenant = TenantScenario::create();
    $this->owner = $this->tenant->ownerMembership;
    $this->un = Fixtures::unit($this->tenant, 'UN');
    $this->product = Fixtures::product($this->tenant, $this->un, ['name' => 'Avental descartável TNT']);
    $this->fileInfo = [['name' => 'ficha.pdf', 'size' => 2048, 'type' => 'application/pdf']];
});

it('recusa iniciar envio de arquivo para perfis sem alteração de catálogo', function (SystemRole $role): void {
    $member = $this->tenant->addStaff($role);

    ($this->asMember)($member, fn () => Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])
        ->call('_startUpload', 'upload', $this->fileInfo, false)
        ->assertForbidden());

    // Referência assinada válida: sem a proteção do componente, o arquivo
    // temporário seria associado ao formulário.
    Storage::disk('private')->put('livewire-tmp/ficha.pdf', '%PDF-1.4');
    $signed = \Livewire\Features\SupportFileUploads\TemporaryUploadedFile::signPath('ficha.pdf');

    ($this->asMember)($member, fn () => Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])
        ->call('_finishUpload', 'upload', [$signed], false)
        ->assertForbidden()
        ->assertSet('upload', null));
})->with([
    'consulta' => [SystemRole::Viewer],
    'estoque e expedição' => [SystemRole::Warehouse],
]);

it('recusa envio para outro campo ou para produto arquivado', function (): void {
    ($this->asMember)($this->owner, fn () => Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])
        ->call('_startUpload', 'productId', $this->fileInfo, false)
        ->assertForbidden());

    $this->tenant->system(fn () => $this->product->forceFill(['status' => ProductStatus::Archived, 'archived_at' => now()])->save());

    ($this->asMember)($this->owner, fn () => Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])
        ->call('_startUpload', 'upload', $this->fileInfo, false)
        ->assertForbidden());
});

it('gera a URL de envio para quem altera o catálogo', function (): void {
    ($this->asMember)($this->owner, fn () => Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])
        ->call('_startUpload', 'upload', $this->fileInfo, false)
        ->assertStatus(200)
        ->assertDispatched('upload:generatedSignedUrl'));
});

it('aponta custo e preço mínimo acima do limite como erro de campo', function (): void {
    ($this->asMember)($this->owner, fn () => Livewire::test(ProductSkus::class, ['productId' => $this->product->id])
        ->set('code', 'AVT-TNT')
        ->set('salesUnitId', $this->un->id)
        ->set('conversionFactor', '1')
        ->set('minOrderQuantity', '1')
        ->set('orderMultiple', '1')
        ->set('cost', '99999999999999999,99')
        ->set('minUnitPrice', '99999999999999999,99')
        ->call('save')
        ->assertHasErrors(['cost', 'minUnitPrice'])
        ->assertSee('Valor acima do limite suportado.'));

    expect($this->tenant->system(static fn (): int => Sku::query()->count()))->toBe(0);
});

it('mostra no campo a recusa de troca de embalagem e de conversão acima do limite', function (): void {
    $cx = Fixtures::unit($this->tenant, 'CX');
    $sku = Fixtures::sku($this->tenant, $this->product, $this->un, 'AVT-UN');
    $list = Tests\Integration\Pricing\PricingFixtures::priceList($this->tenant, 'PADRAO', activate: false);
    Tests\Integration\Pricing\PricingFixtures::item($this->tenant, $list, $sku, '2,50');

    ($this->asMember)($this->owner, fn () => Livewire::test(ProductSkus::class, ['productId' => $this->product->id])
        ->call('edit', $sku->id)
        ->set('salesUnitId', $cx->id)
        ->set('conversionFactor', '50')
        ->call('save')
        ->assertHasErrors(['salesUnitId'])
        ->assertHasNoErrors(['skuStatus']));

    ($this->asMember)($this->owner, fn () => Livewire::test(ProductSkus::class, ['productId' => $this->product->id])
        ->set('code', 'AVT-PALETE')
        ->set('salesUnitId', $cx->id)
        ->set('conversionFactor', '999999999999')
        ->set('minOrderQuantity', '999999999')
        ->set('orderMultiple', '1')
        ->call('save')
        ->assertHasErrors(['conversionFactor']));
});

it('simula quantidade de teste extrema sem erro', function (): void {
    ($this->asMember)($this->owner, fn () => Livewire::test(ProductSkus::class, ['productId' => $this->product->id])
        ->set('salesUnitId', $this->un->id)
        ->set('conversionFactor', '999999999999')
        ->set('minOrderQuantity', '999999999999999')
        ->set('orderMultiple', '2')
        ->set('sampleQuantity', '999999999999999')
        ->assertOk());
});
