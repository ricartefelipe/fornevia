<?php

declare(strict_types=1);

use App\Actions\Catalog\DeleteProductMedia;
use App\Actions\Catalog\UploadProductMedia;
use App\Domain\Catalog\Exceptions\CatalogItemArchived;
use App\Domain\Catalog\Exceptions\InvalidMediaFile;
use App\Enums\Catalog\MediaKind;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Identity\SystemRole;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductMedia;
use App\Models\Identity\Membership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Integration\Catalog\Staff\CatalogFixtures as Fixtures;
use Tests\Support\Builders\TenantScenario;

/**
 * Conteúdos mínimos reconhecidos pelo finfo (assinaturas reais de cada formato).
 */
function amostraDeMidia(string $tipo, int $preenchimento = 64): string
{
    return match ($tipo) {
        'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==').str_repeat("\0", $preenchimento),
        'jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00".str_repeat("\0", $preenchimento)."\xFF\xD9",
        'webp' => 'RIFF'.pack('V', 4 + 18 + $preenchimento).'WEBPVP8 '.pack('V', 10 + $preenchimento)."\x30\x01\x00\x9d\x01\x2a\x01\x00\x01\x00".str_repeat("\0", $preenchimento),
        'pdf' => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n".str_repeat('%', $preenchimento)."\n%%EOF\n",
        default => "Tabela de preços de referência\nCafé torrado 1 kg;45,90\n",
    };
}

beforeEach(function (): void {
    Storage::fake('private');

    $this->upload = fn (TenantScenario $tenant, Membership $actor, Product $product, UploadedFile $file, MediaKind $kind, bool $visible = true): ProductMedia => $tenant->actingAs(
        $actor,
        static fn (): ProductMedia => app(UploadProductMedia::class)->handle($product, $file, $kind, $visible),
    );

    $this->media = fn (TenantScenario $tenant): array => $tenant->system(
        static fn (): array => ProductMedia::query()->orderBy('sort_order')->get()->all(),
    );

    $this->tenant = TenantScenario::create();
    $this->product = Fixtures::product($this->tenant, Fixtures::unit($this->tenant, 'UN'), ['name' => 'Luva nitrílica sem pó']);
});

it('grava imagem no disco privado com caminho do tenant, SHA-256, tamanho e auditoria', function (): void {
    $content = amostraDeMidia('png');
    $media = ($this->upload)($this->tenant, $this->tenant->ownerMembership, $this->product, UploadedFile::fake()->createWithContent('luva-frente.png', $content), MediaKind::Image);

    $stored = $this->tenant->system(static fn (): ProductMedia => ProductMedia::query()->findOrFail($media->id));
    $expectedPath = "tenants/{$this->tenant->organization->id}/products/{$this->product->id}/{$media->id}.png";

    expect($stored->path)->toBe($expectedPath)
        ->and($stored->disk)->toBe('private')
        ->and($stored->kind)->toBe(MediaKind::Image)
        ->and($stored->mime_type)->toBe('image/png')
        ->and($stored->size_bytes)->toBe(strlen($content))
        ->and($stored->sha256)->toBe(hash('sha256', $content))
        ->and($stored->original_name)->toBe('luva-frente.png')
        ->and($stored->visible_to_buyers)->toBeTrue()
        ->and($stored->sort_order)->toBe(0)
        ->and($stored->uploaded_by_user_id)->toBe($this->tenant->owner->id)
        ->and(Storage::disk('private')->get($expectedPath))->toBe($content)
        ->and(Fixtures::audits($this->tenant, 'product_media.uploaded')->sole()->data)->toMatchArray([
            'product_id' => $this->product->id,
            'kind' => 'image',
            'mime_type' => 'image/png',
            'sha256' => hash('sha256', $content),
        ]);
});

it('reconhece JPEG, WebP e PDF pelo conteúdo e ordena as mídias', function (string $type, MediaKind $kind, string $mime, string $extension): void {
    $first = ($this->upload)($this->tenant, $this->tenant->ownerMembership, $this->product, UploadedFile::fake()->createWithContent('arquivo.bin', amostraDeMidia($type)), $kind, false);
    $second = ($this->upload)($this->tenant, $this->tenant->ownerMembership, $this->product, UploadedFile::fake()->createWithContent('arquivo-2.bin', amostraDeMidia($type, 128)), $kind);

    expect($first->mime_type)->toBe($mime)
        ->and($first->path)->toEndWith(".{$extension}")
        ->and($first->original_name)->toBe("arquivo.{$extension}")
        ->and($first->visible_to_buyers)->toBeFalse()
        ->and([$first->sort_order, $second->sort_order])->toBe([0, 1]);
})->with([
    'JPEG' => ['jpeg', MediaKind::Image, 'image/jpeg', 'jpg'],
    'WebP' => ['webp', MediaKind::Image, 'image/webp', 'webp'],
    'PDF' => ['pdf', MediaKind::Document, 'application/pdf', 'pdf'],
]);

it('decide o tipo pelo conteúdo, e não pelo nome do arquivo', function (string $name, string $content, MediaKind $kind, string $message): void {
    expect(fn () => ($this->upload)($this->tenant, $this->tenant->ownerMembership, $this->product, UploadedFile::fake()->createWithContent($name, $content), $kind))
        ->toThrow(InvalidMediaFile::class, $message);

    expect(($this->media)($this->tenant))->toBe([])
        ->and(Storage::disk('private')->allFiles())->toBe([]);
})->with([
    'PDF disfarçado de imagem' => ['foto.jpg', amostraDeMidia('pdf'), MediaKind::Image, 'não corresponde ao tipo escolhido (Imagem)'],
    'imagem enviada como documento' => ['manual.pdf', amostraDeMidia('png'), MediaKind::Document, 'não corresponde ao tipo escolhido (Documento)'],
    'texto com extensão de PDF' => ['tabela.pdf', amostraDeMidia('texto'), MediaKind::Document, 'Tipo de arquivo não permitido'],
    'script com extensão de imagem' => ['foto.png', "<?php echo 'teste';", MediaKind::Image, 'Tipo de arquivo não permitido'],
    'arquivo vazio' => ['vazio.png', '', MediaKind::Image, 'vazio'],
]);

it('aplica os limites de 5 MB para imagens e 10 MB para documentos', function (): void {
    $owner = $this->tenant->ownerMembership;
    $bigImage = UploadedFile::fake()->createWithContent('grande.png', amostraDeMidia('png', 5 * 1024 * 1024));
    $bigDocument = UploadedFile::fake()->createWithContent('grande.pdf', amostraDeMidia('pdf', 10 * 1024 * 1024));
    $mediumDocument = UploadedFile::fake()->createWithContent('catalogo-tecnico.pdf', amostraDeMidia('pdf', 6 * 1024 * 1024));

    expect(fn () => ($this->upload)($this->tenant, $owner, $this->product, $bigImage, MediaKind::Image))
        ->toThrow(InvalidMediaFile::class, 'excede o limite de 5 MB')
        ->and(fn () => ($this->upload)($this->tenant, $owner, $this->product, $bigDocument, MediaKind::Document))
        ->toThrow(InvalidMediaFile::class, 'excede o limite de 10 MB');

    $accepted = ($this->upload)($this->tenant, $owner, $this->product, $mediumDocument, MediaKind::Document);

    expect($accepted->size_bytes)->toBeGreaterThan(6 * 1024 * 1024)
        ->and(count(($this->media)($this->tenant)))->toBe(1);
});

it('remove diretórios e caracteres de controle do nome original', function (): void {
    $media = ($this->upload)($this->tenant, $this->tenant->ownerMembership, $this->product,
        UploadedFile::fake()->createWithContent("..\\..\\Ficha\x07 Técnica: luvas.PDF", amostraDeMidia('pdf')), MediaKind::Document);

    expect($media->original_name)->toBe('Ficha Técnica luvas.pdf');
});

it('apaga o arquivo gravado quando a transação é desfeita', function (): void {
    $file = UploadedFile::fake()->createWithContent('luva.png', amostraDeMidia('png'));

    try {
        $this->tenant->actingAs($this->tenant->ownerMembership, function () use ($file): void {
            $media = app(UploadProductMedia::class)->handle($this->product, $file, MediaKind::Image);
            expect(Storage::disk('private')->exists($media->path))->toBeTrue();

            throw new RuntimeException('Falha posterior na mesma requisição.');
        });
    } catch (RuntimeException) {
    }

    expect(($this->media)($this->tenant))->toBe([])
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('não anexa mídia a produto arquivado nem a produto de outro tenant', function (): void {
    $archived = Fixtures::product($this->tenant, Fixtures::unit($this->tenant, 'UN'), ['status' => ProductStatus::Archived, 'archived_at' => now()]);
    $other = TenantScenario::create();
    $foreign = Fixtures::product($other, Fixtures::unit($other, 'UN'));
    $file = UploadedFile::fake()->createWithContent('luva.png', amostraDeMidia('png'));

    expect(fn () => ($this->upload)($this->tenant, $this->tenant->ownerMembership, $archived, $file, MediaKind::Image))
        ->toThrow(CatalogItemArchived::class)
        ->and(fn () => ($this->upload)($this->tenant, $this->tenant->ownerMembership, $foreign, $file, MediaKind::Image))
        ->toThrow(ModelNotFoundException::class);

    expect(Storage::disk('private')->allFiles())->toBe([])
        ->and($other->system(static fn (): int => ProductMedia::query()->count()))->toBe(0);
});

it('exige catalog.manage para anexar e remover', function (SystemRole $role): void {
    $actor = $this->tenant->addStaff($role);
    $file = UploadedFile::fake()->createWithContent('luva.png', amostraDeMidia('png'));
    $existing = ($this->upload)($this->tenant, $this->tenant->ownerMembership, $this->product, $file, MediaKind::Image);

    expect(fn () => ($this->upload)($this->tenant, $actor, $this->product, $file, MediaKind::Image))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $this->tenant->actingAs($actor, static fn () => app(DeleteProductMedia::class)->handle($existing)))
        ->toThrow(AuthorizationException::class);

    expect(count(($this->media)($this->tenant)))->toBe(1)
        ->and(Storage::disk('private')->exists($existing->path))->toBeTrue();
})->with([
    'gerente comercial' => [SystemRole::SalesManager],
    'estoque' => [SystemRole::Warehouse],
]);

it('remove o registro na transação e o arquivo somente após o commit', function (): void {
    $media = ($this->upload)($this->tenant, $this->tenant->ownerMembership, $this->product, UploadedFile::fake()->createWithContent('luva.png', amostraDeMidia('png')), MediaKind::Image);

    $existedBeforeCommit = $this->tenant->actingAs($this->tenant->ownerMembership, static function () use ($media): bool {
        app(DeleteProductMedia::class)->handle($media);

        return Storage::disk('private')->exists($media->path);
    });

    expect($existedBeforeCommit)->toBeTrue()
        ->and(Storage::disk('private')->exists($media->path))->toBeFalse()
        ->and(($this->media)($this->tenant))->toBe([])
        ->and(Fixtures::audits($this->tenant, 'product_media.deleted')->sole()->data)->toMatchArray([
            'product_id' => $this->product->id,
            'original_name' => 'luva.png',
        ]);
});

it('preserva registro e arquivo quando a remoção é desfeita', function (): void {
    $media = ($this->upload)($this->tenant, $this->tenant->ownerMembership, $this->product, UploadedFile::fake()->createWithContent('luva.png', amostraDeMidia('png')), MediaKind::Image);

    try {
        $this->tenant->actingAs($this->tenant->ownerMembership, static function () use ($media): void {
            app(DeleteProductMedia::class)->handle($media);

            throw new RuntimeException('Falha posterior na mesma requisição.');
        });
    } catch (RuntimeException) {
    }

    expect(count(($this->media)($this->tenant)))->toBe(1)
        ->and(Storage::disk('private')->exists($media->path))->toBeTrue();
});

it('não remove mídia de outro tenant', function (): void {
    $other = TenantScenario::create();
    $foreignProduct = Fixtures::product($other, Fixtures::unit($other, 'UN'));
    $foreign = ($this->upload)($other, $other->ownerMembership, $foreignProduct, UploadedFile::fake()->createWithContent('luva.png', amostraDeMidia('png')), MediaKind::Image);

    expect(fn () => $this->tenant->actingAs($this->tenant->ownerMembership, static fn () => app(DeleteProductMedia::class)->handle($foreign)))
        ->toThrow(ModelNotFoundException::class);

    expect(Storage::disk('private')->exists($foreign->path))->toBeTrue()
        ->and($other->system(static fn (): int => ProductMedia::query()->count()))->toBe(1);
});
