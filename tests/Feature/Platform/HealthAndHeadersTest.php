<?php

declare(strict_types=1);

it('responde liveness sem sessão nem cookies', function (): void {
    $response = $this->get('/health/live');

    $response->assertOk()->assertExactJson(['status' => 'ok']);
    expect($response->headers->getCookies())->toBe([]);
});

it('responde readiness verificando banco, migrations e redis', function (): void {
    $this->get('/health/ready')
        ->assertOk()
        ->assertJson(['status' => 'ready', 'checks' => ['database' => 'ok', 'migrations' => 'ok', 'redis' => 'ok']]);
});

it('aplica Content Security Policy com nonce e cabeçalhos de segurança', function (): void {
    $response = $this->get('/');

    $csp = (string) $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9+\\/=]+'/")
        ->not->toContain('unsafe-eval')
        ->not->toContain('unsafe-inline');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});

it('propaga um identificador de correlação válido e substitui valores inválidos', function (): void {
    $this->get('/health/live', ['X-Request-Id' => 'pedido-abc-123'])
        ->assertHeader('X-Request-Id', 'pedido-abc-123');

    $generated = $this->get('/health/live', ['X-Request-Id' => "<script>\n"])->headers->get('X-Request-Id');

    expect($generated)->toMatch('/^[0-9a-f-]{36}$/');
});

it('retorna erros da API em formato estruturado com request_id', function (): void {
    $this->getJson('/api/v1/inexistente')
        ->assertNotFound()
        ->assertJsonStructure(['error' => ['code', 'message'], 'request_id'])
        ->assertJsonPath('error.code', 'not_found');
});
