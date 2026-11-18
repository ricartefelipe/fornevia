<?php

declare(strict_types=1);

use App\Actions\Catalog\UploadProductMedia;
use App\Enums\Catalog\MediaKind;
use App\Enums\Identity\SystemRole;
use App\Livewire\Staff\Catalog\ProductMediaManager;
use App\Models\Catalog\ProductMedia;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

beforeEach(function (): void {
    Storage::fake('private');

    $this->asMember = fn (TenantScenario $tenant, Membership $membership, Closure $callback): mixed => $tenant->actingAs(
        $membership,
        static function () use ($membership, $callback): mixed {
            Livewire::actingAs(User::query()->findOrFail($membership->user_id));

            return $callback();
        },
    );

    $this->png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $this->pdf = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    $this->tenant = TenantScenario::create();
    $this->owner = $this->tenant->ownerMembership;
    $this->product = Fixtures::product($this->tenant, Fixtures::unit($this->tenant, 'UN'), ['name' => 'Avental descartável TNT']);

    $this->attach = fn (TenantScenario $tenant, string $productId, string $name, string $content, MediaKind $kind): ProductMedia => $tenant->actingAs(
        $tenant->ownerMembership,
        static fn (): ProductMedia => app(UploadProductMedia::class)->handle(
            App\Models\Catalog\Product::query()->findOrFail($productId),
            UploadedFile::fake()->createWithContent($name, $content),
            $kind,
        ),
    );

    $this->mediaNames = fn (): array => $this->tenant->system(fn (): array => ProductMedia::query()->where('product_id', $this->product->id)->orderBy('sort_order')->pluck('original_name')->all());
});

it('anexa imagem pela tela e lista o arquivo', function (): void {
    ($this->asMember)($this->tenant, $this->owner, fn () => Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])
        ->assertSee('Nenhum arquivo anexado')
        ->set('upload', UploadedFile::fake()->createWithContent('avental-frente.png', $this->png))
        ->set('mediaKind', 'image')
        ->set('visibleToBuyers', false)
        ->call('uploadMedia')
        ->assertHasNoErrors()
        ->assertSet('upload', null)
        ->assertSet('message', 'Arquivo avental-frente.png anexado.')
        ->assertDispatched('catalog-product-changed')
        ->assertSee('avental-frente.png')
        ->assertSee('Somente equipe'));

    $media = $this->tenant->system(fn (): ProductMedia => ProductMedia::query()->where('product_id', $this->product->id)->sole());

    expect($media->mime_type)->toBe('image/png')
        ->and($media->visible_to_buyers)->toBeFalse()
        ->and(Storage::disk('private')->exists($media->path))->toBeTrue();
});

it('recusa arquivo cujo conteúdo não corresponde ao tipo escolhido', function (): void {
    ($this->asMember)($this->tenant, $this->owner, fn () => Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])
        ->set('upload', UploadedFile::fake()->createWithContent('avental.png', $this->pdf))
        ->set('mediaKind', 'image')
        ->call('uploadMedia')
        ->assertHasErrors(['upload'])
        ->assertSee('O conteúdo do arquivo não corresponde ao tipo escolhido (Imagem).')
        ->set('mediaKind', 'video')
        ->call('uploadMedia')
        ->assertHasErrors(['mediaKind']));

    expect(($this->mediaNames)())->toBe([]);
});

it('exige arquivo selecionado', function (): void {
    ($this->asMember)($this->tenant, $this->owner, fn () => Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])
        ->call('uploadMedia')
        ->assertHasErrors(['upload' => 'required']));
});

it('remove mídia do produto e trata identificadores adulterados como inexistentes', function (): void {
    $media = ($this->attach)($this->tenant, $this->product->id, 'ficha-tecnica.pdf', $this->pdf, MediaKind::Document);
    $otherProduct = Fixtures::product($this->tenant, Fixtures::unit($this->tenant, 'UN'), ['name' => 'Touca descartável']);
    $otherProductMedia = ($this->attach)($this->tenant, $otherProduct->id, 'touca.pdf', $this->pdf, MediaKind::Document);
    $otherTenant = TenantScenario::create();
    $foreignMedia = ($this->attach)($otherTenant, Fixtures::product($otherTenant, Fixtures::unit($otherTenant, 'UN'))->id, 'externo.pdf', $this->pdf, MediaKind::Document);

    ($this->asMember)($this->tenant, $this->owner, function () use ($media, $otherProductMedia, $foreignMedia): void {
        Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])->call('deleteMedia', $otherProductMedia->id)->assertNotFound();
        Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])->call('deleteMedia', $foreignMedia->id)->assertNotFound();
        Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])->call('deleteMedia', 'ficha-tecnica')->assertNotFound();

        Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])
            ->call('deleteMedia', $media->id)
            ->assertHasNoErrors()
            ->assertSet('message', 'Arquivo ficha-tecnica.pdf removido.');
    });

    expect(($this->mediaNames)())->toBe([])
        ->and(Storage::disk('private')->exists($media->path))->toBeFalse()
        ->and(Storage::disk('private')->exists($otherProductMedia->path))->toBeTrue()
        ->and(Storage::disk('private')->exists($foreignMedia->path))->toBeTrue();
});

it('não oferece anexação a quem só consulta e recusa o envio no servidor', function (): void {
    ($this->asMember)($this->tenant, $this->tenant->addStaff(SystemRole::Viewer), fn () => Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])
        ->assertDontSee('Anexar arquivo')
        // Primeira etapa do envio (URL assinada): recusada antes de gravar.
        ->call('_startUpload', 'upload', [['name' => 'avental.png', 'size' => strlen($this->png), 'type' => 'image/png']], false)
        ->assertForbidden());

    expect(($this->mediaNames)())->toBe([]);
});

it('usa a rota autorizada de download quando ela está registrada', function (): void {
    $media = ($this->attach)($this->tenant, $this->product->id, 'avental.png', $this->png, MediaKind::Image);

    if (! Route::has('catalog.media.download')) {
        Route::middleware('web')->get('/teste/catalogo/midias/{media}', static fn (): string => 'arquivo')->name('catalog.media.download');
        Route::getRoutes()->refreshNameLookups();
    }

    ($this->asMember)($this->tenant, $this->owner, fn () => Livewire::test(ProductMediaManager::class, ['productId' => $this->product->id])
        ->assertSeeHtml('href="'.e(route('catalog.media.download', $media)).'"'));
});
