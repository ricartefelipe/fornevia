# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/)
e versionamento [SemVer](https://semver.org/lang/pt-BR/).

## [Não lançado]

### Adicionado

- Fundação da plataforma: Laravel 13, PHP 8.5, Docker multi-estágio,
  Compose de desenvolvimento e CI.
- Multitenancy com RLS forçada, contexto local à transação e chaves
  estrangeiras compostas por tenant.
- Logs estruturados com correlação, CSP com nonce e health checks.
- Identidade e acesso: associações por contexto, papéis e permissões,
  convites, revogação imediata, troca de contexto, 2FA para perfis
  privilegiados e auditoria.
- Assinaturas da plataforma: planos configuráveis, trial e limites
  efetivos de usuários, filiais e pedidos.
- Interface Blade/Livewire em português com identidade visual própria.
- Harness de testes de concorrência com processos separados.

### Alterado

- PostgreSQL de desenvolvimento sem reinício automático.
