# Rastreabilidade dos requisitos

Relação entre os grupos de requisitos de [REQUISITOS.md](REQUISITOS.md), o
incremento que os implementou (detalhes em [STATUS.md](STATUS.md)), as
decisões e a evidência automatizada. Todas as suítes rodam na CI de cada
pull request; a concorrência usa processos PHP separados contra o
PostgreSQL real.

| Grupo | Requisitos | Incremento | Decisões | Evidência automatizada |
|---|---|---|---|---|
| Produto e plataforma | RF-001 a RF-004 | 2 | ADR-015 | `tests/Integration/Platform`, `tests/Feature/Platform` |
| Organizações, identidade e acesso | RF-010 a RF-016, RS-010 a RS-012 | 1 e 2 | ADR-003, ADR-013, ADR-014, ADR-016 | `tests/Feature/Auth`, `tests/Feature/Identity`, `tests/Integration/Identity`, `tests/Integration/Tenancy`, `tests/Concurrency/Identity` |
| Empresas compradoras e catálogo | RF-020 a RF-023, RN-020 a RN-022, RS-020 | 3 | ADR-018, ADR-019, ADR-020 | `tests/Feature/Buyers`, `tests/Feature/Catalog`, `tests/Integration/Buyers`, `tests/Integration/Catalog`, `tests/Unit/Domain` |
| Preços e condições comerciais | RF-030, RN-030 a RN-035, RS-030, RQ-030 | 3 | ADR-004, ADR-010, ADR-011, ADR-019, ADR-020 | `tests/Unit/Domain/PriceResolverTest.php`, `tests/Integration/Pricing`, `tests/Feature/Pricing` |
| Cotações e negociação | RF-040, RN-040 a RN-044 | 6 | ADR-025 | `tests/Integration/Quotes`, `tests/Feature/Quotes`, `tests/Concurrency/Quotes` |
| Aprovação de compras e orçamento | RF-050, RN-050 a RN-056 | 5 | ADR-023, ADR-024 | `tests/Integration/Approvals`, `tests/Concurrency/Approvals`, `tests/Unit/Domain/ApprovalFlowTest.php` |
| Carrinho, checkout e pedidos | RF-060 a RF-063, RN-060 a RN-062 | 4 | ADR-006, ADR-007, ADR-009, ADR-022 | `tests/Integration/Ordering`, `tests/Feature/Orders`, `tests/Feature/Portal`, `tests/Concurrency/Ordering`, `tests/Browser` |
| Estoque | RF-070, RN-070 a RN-074 | 4 | ADR-005, ADR-006 | `tests/Integration/Inventory`, `tests/Feature/Inventory`, `tests/Concurrency/Inventory` |
| Crédito comercial | RF-080, RN-080 a RN-083, RS-080 | 4 e 9 | ADR-005, ADR-006, ADR-028 | `tests/Integration/Credit`, `tests/Feature/Credit`, `tests/Concurrency/Credit` |
| Expedição, entrega e devoluções | RF-090 a RF-092, RN-090 e RN-091 | 8 | ADR-027 | `tests/Integration/Fulfillment`, `tests/Feature/Fulfillment`, `tests/Concurrency/Fulfillment` |
| Financeiro | RF-100 a RF-102, RN-100 e RN-101, RS-100 | 9 | ADR-028 | `tests/Integration/Finance`, `tests/Feature/Finance`, `tests/Concurrency/Finance` |
| Integrações e arquivos | RF-110 a RF-113, RS-110 e RS-111 | 10 (RS-111 no 3) | ADR-029 | `tests/Feature/Api`, `tests/Integration/Integrations`, `tests/Feature/Integrations`, `tests/Unit/Support/Integrations` |
| Segurança, operação e qualidade | RS-120 a RS-123, RQ-120 a RQ-124 | 1, 7, 11, 12 e 13 | ADR-001, ADR-002, ADR-008, ADR-012, ADR-017, ADR-021, ADR-026, ADR-030 | `tests/Arch`, `tests/Feature/Auth`, `tests/Feature/Observability`, `tests/Feature/Audit`, `tests/Integration/Outbox`, `tests/Browser`, jobs de mutação, cobertura e implantação de referência na CI |

## Verificação da versão

| Verificação | Resultado |
|---|---|
| Suíte completa | ver a CI do PR da release |
| Cobertura de linhas | meta de 90% no núcleo (Domain + Actions) e nos caminhos críticos |
| Cobertura de branches dos caminhos críticos | meta de 80% (Xdebug) |
| Mutação do domínio | nota mínima de 90% no código coberto |
| Navegador | login, compra pelo carrinho e lista de pedidos com Chrome real |
| Implantação de referência | `compose.prod.yaml` sobe com as imagens de produção e responde `/health/ready` |
