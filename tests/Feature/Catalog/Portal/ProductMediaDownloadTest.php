<?php

declare(strict_types=1);

use App\Enums\Catalog\MediaKind;
use App\Enums\Catalog\ProductStatus;
use App\Enums\Catalog\ProductVisibility;
use App\Enums\Identity\Permission;
use App\Enums\Identity\SystemRole;
use App\Models\Identity\Membership;
use App\Models\Identity\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Integration\Catalog\Portal\PortalCatalogFixtures as Catalog;
use Tests\Support\Builders\TenantScenario;

/*
| Download autorizado de mídias de produto (catalog.media.download para a
| equipe e portal.media.download para o comprador): visibilidade, isolamento
| entre tenants, identificador não confiável e cabeçalhos de proteção.
*/

beforeEach(function (): void {
    $this->withoutVite();
    Storage::fake('private');

    $this->tenant = TenantScenario::create(name: 'Distribuidora Planalto');
    $this->company = $this->tenant->addBuyerCompany(['trade_name' => 'Hotel Bela Vista']);
    $this->buyer = $this->tenant->addBuyerUser($this->company);
    $this->staff = $this->tenant->addStaff(SystemRole::Viewer);

    $product = Catalog::simpleProduct($this->tenant, 'Toalha de banho 100% algodão', 'TOA-001', ['brand' => 'Fio Nobre']);
    $this->image = Catalog::media($this->tenant, $product, MediaKind::Image, 'toalha-branca.png', 'image/png', "\x89PNG imagem da toalha");
    $this->document = Catalog::media(
        $this->tenant,
        $product,
        MediaKind::Document,
        "../../privado/Ficha \"técnica\"\r\nSet-Cookie: sessao=1; Toalha.pdf",
        'application/pdf',
        '%PDF-1.7 ficha técnica',
    );
    $this->staffOnly = Catalog::media($this->tenant, $product, MediaKind::Document, 'tabela-de-custos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'custos internos', visibleToBuyers: false);

    $this->request = function (string $routeName, string $media, Membership $membership, array $headers = []): TestResponse {
        app()->forgetScopedInstances();

        return $this->actingAs(User::query()->findOrFail($membership->user_id))
            ->withSession(TenantScenario::sessionFor($membership))
            ->get(route($routeName, $media), $headers);
    };
});

it('entrega a imagem inline à equipe com cabeçalhos de proteção', function (): void {
    $response = ($this->request)('catalog.media.download', $this->image->id, $this->staff)->assertOk();

    expect($response->streamedContent())->toBe("\x89PNG imagem da toalha")
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename=toalha-branca.png')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen("\x89PNG imagem da toalha"))
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->not->toContain('public')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("default-src 'none'; img-src 'self'; sandbox")
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
        ->and($response->headers->get('ETag'))->toBe('"'.$this->image->sha256.'"');
});

it('entrega à equipe também a mídia oculta aos compradores', function (): void {
    $response = ($this->request)('catalog.media.download', $this->staffOnly->id, $this->staff)->assertOk();

    expect($response->streamedContent())->toBe('custos internos')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment;');
});

it('entrega ao comprador o documento como anexo com nome de arquivo saneado', function (): void {
    $response = ($this->request)('portal.media.download', $this->document->id, $this->buyer)->assertOk();
    $disposition = (string) $response->headers->get('Content-Disposition');

    expect($response->streamedContent())->toBe('%PDF-1.7 ficha técnica')
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($disposition)->toBe('attachment; filename="Ficha _tecnica_ Set-Cookie_ sessao_1_ Toalha.pdf"; filename*=utf-8\'\'Ficha%20_t%C3%A9cnica_%20Set-Cookie_%20sessao%3D1_%20Toalha.pdf')
        ->and($disposition)->not->toContain("\r")
        ->and($disposition)->not->toContain("\n")
        ->and($disposition)->not->toContain('privado')
        ->and($disposition)->not->toContain('..');
});

it('entrega ao comprador a imagem de produto visível', function (): void {
    $response = ($this->request)('portal.media.download', $this->image->id, $this->buyer)->assertOk();

    expect($response->streamedContent())->toBe("\x89PNG imagem da toalha")
        ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename=toalha-branca.png');
});

it('responde 404 ao comprador para mídia que ele não pode ver', function (string $case): void {
    $media = match ($case) {
        'mídia oculta aos compradores' => $this->staffOnly,
        'produto restrito sem catálogo atribuído' => Catalog::media(
            $this->tenant,
            Catalog::simpleProduct($this->tenant, 'Roupão atoalhado exclusivo', 'ROU-001', ['visibility' => ProductVisibility::Restricted]),
            MediaKind::Image,
            'roupao.png',
            'image/png',
            'roupão',
        ),
        'produto em rascunho' => Catalog::media(
            $this->tenant,
            Catalog::simpleProduct($this->tenant, 'Lençol percal 200 fios', 'LEN-200', ['status' => ProductStatus::Draft]),
            MediaKind::Document,
            'lencol.pdf',
            'application/pdf',
            'lençol',
        ),
    };

    ($this->request)('portal.media.download', $media->id, $this->buyer)->assertNotFound();
    // A equipe continua enxergando a mesma mídia.
    ($this->request)('catalog.media.download', $media->id, $this->staff)->assertOk();
})->with(['mídia oculta aos compradores', 'produto restrito sem catálogo atribuído', 'produto em rascunho']);

it('entrega ao comprador mídia de produto restrito atribuído à sua empresa', function (): void {
    $product = Catalog::simpleProduct($this->tenant, 'Roupão atoalhado exclusivo', 'ROU-001', ['visibility' => ProductVisibility::Restricted]);
    Catalog::assign($this->tenant, Catalog::privateCatalog($this->tenant, 'HOTELARIA', [$product]), $this->company);
    $media = Catalog::media($this->tenant, $product, MediaKind::Image, 'roupao.webp', 'image/webp', 'roupão');

    expect(($this->request)('portal.media.download', $media->id, $this->buyer)->assertOk()->streamedContent())->toBe('roupão');
});

it('responde 404 para mídia de outro tenant', function (string $route): void {
    $foreign = TenantScenario::create(name: 'Distribuidora Litoral');
    $media = Catalog::media($foreign, Catalog::simpleProduct($foreign, 'Toalha de rosto', 'TOA-002'), MediaKind::Image, 'rosto.png', 'image/png', 'outro tenant');

    ($this->request)($route, $media->id, $route === 'portal.media.download' ? $this->buyer : $this->staff)->assertNotFound();
})->with(['catalog.media.download', 'portal.media.download']);

it('responde 404 para identificador malformado', function (string $route, string $id): void {
    ($this->request)($route, $id, $route === 'portal.media.download' ? $this->buyer : $this->staff)->assertNotFound();
})->with([
    'equipe, texto' => ['catalog.media.download', 'toalha-branca.png'],
    'equipe, injeção' => ['catalog.media.download', "1' or '1'='1"],
    'portal, texto' => ['portal.media.download', 'toalha-branca.png'],
    'portal, UUID incompleto' => ['portal.media.download', '01970000-0000-7000-8000'],
]);

it('responde 404 quando o arquivo não está no disco', function (): void {
    $media = Catalog::media(
        $this->tenant,
        Catalog::simpleProduct($this->tenant, 'Travesseiro de fibra', 'TRA-001'),
        MediaKind::Document,
        'manual.pdf',
        'application/pdf',
        'manual',
        storeFile: false,
    );

    ($this->request)('catalog.media.download', $media->id, $this->staff)->assertNotFound();
});

it('nunca exibe inline imagem de tipo arriscado', function (): void {
    $media = Catalog::media(
        $this->tenant,
        Catalog::simpleProduct($this->tenant, 'Cortina blackout', 'COR-001'),
        MediaKind::Image,
        'cortina.svg',
        'image/svg+xml',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    );

    $response = ($this->request)('portal.media.download', $media->id, $this->buyer)->assertOk();

    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename=cortina.svg')
        ->and($response->headers->get('Content-Type'))->toBe('image/svg+xml')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toContain('sandbox');
});

it('responde 304 sem conteúdo quando o arquivo não mudou', function (): void {
    $response = ($this->request)('portal.media.download', $this->image->id, $this->buyer, ['If-None-Match' => '"'.$this->image->sha256.'"']);

    $response->assertStatus(304);

    expect($response->streamedContent())->toBe('');
});

it('nega o download a quem não tem a permissão de catálogo', function (string $case): void {
    if ($case === 'equipe sem catalog.view') {
        $user = User::factory()->withTwoFactor()->create()->fresh();
        $membership = $this->tenant->addStaff(SystemRole::Finance, $user);
        $route = 'catalog.media.download';
    } else {
        Catalog::revokePermission($this->tenant, SystemRole::Purchaser, Permission::PortalCatalog);
        $membership = $this->buyer;
        $route = 'portal.media.download';
    }

    ($this->request)($route, $this->image->id, $membership)->assertForbidden();
})->with(['equipe sem catalog.view', 'comprador sem portal.catalog']);

it('nega a rota de mídia de outro contexto', function (): void {
    ($this->request)('catalog.media.download', $this->image->id, $this->buyer)->assertForbidden();
    ($this->request)('portal.media.download', $this->image->id, $this->staff)->assertForbidden();
});
