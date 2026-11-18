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
- Empresas compradoras: cadastro com CNPJ, grupos de clientes, unidades,
  endereços, centros de custo, contatos, condições de pagamento, usuários
  e histórico.
- Catálogo: produtos, SKUs com embalagem e conversão exata, categorias,
  unidades de medida, mídias privadas, custos internos, política comercial
  e catálogos privados por empresa ou grupo; catálogo do portal com preço
  e regra de compra.
- Preços: tabelas padrão, por grupo e por empresa com vigência,
  prioridade e faixas; condições negociadas; margem mínima padrão;
  simulador que explica a precedência; cache invalidado por versão.
- Entrada de quantidades em português nas telas de preço e limites de
  magnitude para valores e quantidades.

### Alterado

- PostgreSQL de desenvolvimento sem reinício automático.
- Identificadores recebidos do cliente normalizados para minúsculas.

### Segurança

- Contexto de comprador sem leitura de catálogos privados de outros
  grupos, de registros de auditoria de terceiros e sem escrita nas
  configurações de preço do tenant (RLS).
- Envio temporário de mídias restrito a quem altera o catálogo.
- Margem mínima interna fora do estado enviado ao navegador de perfis
  somente de consulta.
