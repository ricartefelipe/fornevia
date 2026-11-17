# Requisitos e critérios de aceite

Identificadores estáveis: `RF` (funcional), `RN` (regra de negócio),
`RS` (segurança), `RQ` (qualidade/operação). O estado de cada requisito
está em [STATUS.md](STATUS.md).

## 1. Produto e plataforma

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-001 | Distribuidor contrata um plano da plataforma | Planos configuráveis (preço mensal, limites de usuários, filiais, pedidos/mês, módulos); valores são hipóteses de produto editáveis pelo administrador da plataforma |
| RF-002 | Trial | Nova organização inicia em trial com prazo; ao expirar sem assinatura ativa, acesso de escrita é bloqueado |
| RF-003 | Limites efetivos do plano | Convite acima do limite de usuários, criação de filial acima do limite e confirmação de pedido acima do volume mensal são recusados com mensagem explícita |
| RF-004 | Separação entre assinatura e vendas | Cobrança do Fornevia ao distribuidor não compartilha tabelas nem fluxos com as vendas do distribuidor a seus clientes |

## 2. Organizações, identidade e acesso

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-010 | Login, recuperação de senha, confirmação de e-mail | Fortify; tokens de redefinição de uso único; e-mail confirmado antes de acessar contextos |
| RF-011 | MFA para perfis privilegiados | Perfis com gestão de membros, crédito, financeiro ou organização e administradores da plataforma precisam de TOTP confirmado |
| RF-012 | Organizações e filiais | CRUD de filiais com limite do plano; uma matriz por organização |
| RF-013 | Convites | Convite por e-mail com token de uso único (hash armazenado), validade, papel e tipo de contexto; aceite exige e-mail correspondente |
| RF-014 | Associações e troca de contexto | Um usuário pode ter vários contextos (equipe de A, comprador da empresa X em B); troca valida associação ativa |
| RF-015 | Revogação de acesso | Revogar associação encerra o acesso imediatamente no próximo request; registro auditado com motivo |
| RF-016 | Papéis e permissões por contexto | Papéis de equipe e de comprador com permissões distintas; papéis padrão criados para cada tenant |
| RS-010 | Tenant validado contra a identidade | Tenant de URL, header ou formulário nunca é usado sem validação contra a associação/token autenticado |
| RS-011 | Isolamento entre tenants | Escopo explícito, policies, FKs compostas e RLS forçada; papel de aplicação sem superuser, sem BYPASSRLS e sem propriedade |
| RS-012 | Contexto local à transação | `set_config(..., true)`; conexão reutilizada não herda contexto; jobs estabelecem contexto antes de consultar |

## 3. Empresas compradoras e catálogo

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-020 | Empresas compradoras | Dados cadastrais (CNPJ validado), contatos, endereços, unidades, centros de custo, representante responsável, condições comerciais, histórico |
| RF-021 | Usuários compradores e aprovadores | Associações de comprador por empresa com papéis (administrador, comprador, solicitante, aprovador) |
| RF-022 | Catálogo | Produtos, SKUs/variações, categorias, unidades de medida, embalagem (fator de conversão), quantidade mínima, múltiplo, imagens e documentos, situação comercial |
| RN-020 | Embalagem e múltiplos | Quantidade deve respeitar mínimo, múltiplo e casas decimais da unidade; ex.: caixa com 12 não aceita quantidade incompatível |
| RN-021 | Conversão de unidades | Conversão exata com `numeric(18,6)`; quantidade base = quantidade de venda × fator |
| RF-023 | Catálogos privados | Produto restrito só aparece para empresas/grupos com catálogo privado atribuído |
| RS-020 | Dados internos | Custo e margem nunca aparecem no portal; na equipe, só com permissão `catalog.view_costs` |
| RN-022 | Arquivamento | Produto com histórico comercial é arquivado (não excluído); registros anteriores preservados |

## 4. Preços e condições comerciais

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-030 | Tabelas de preço | Padrão, por grupo e por cliente, com vigência e prioridade |
| RN-030 | Precedência determinística | Negociada → cliente → grupo → padrão; desempate por prioridade, início de vigência e criação (ADR-011) |
| RN-031 | Faixas por quantidade | Preço por volume sobre todas as unidades (ADR-010) |
| RN-032 | Preço e margem mínimos | Preço líquido abaixo do mínimo ou margem abaixo da mínima exige aprovação comercial |
| RN-033 | Limite de desconto por perfil | Desconto acima do limite do papel exige aprovação de perfil com limite suficiente |
| RN-034 | Snapshots | Documentos comerciais guardam preço, descrição, descontos, condições e origem do preço |
| RS-030 | Preço do servidor | Preços nunca são aceitos do navegador ou do cliente da API |
| RN-035 | Dinheiro | BRL, centavos inteiros, `BigDecimal`, sem float (ADR-004) |
| RQ-030 | Cache de preços | Chave inclui tenant, empresa, grupo, versão de preços, SKU, quantidade e data |

## 5. Cotações e negociação

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-040 | Ciclo da cotação | Solicitação, proposta, contraproposta, versões, comentários, validade, aceite/rejeição, conversão em pedido |
| RN-040 | Aceite vinculado à versão | Aceite referencia a versão exata; alteração relevante cria nova versão e invalida aceite anterior |
| RN-041 | Aprovação comercial | Desconto abaixo da política exige aprovação comercial do distribuidor, distinta da aprovação interna do comprador |
| RN-042 | Conversão idempotente | Uma versão aceita gera no máximo um pedido (unique) |
| RN-043 | Garantia de estoque | Cotação informa se há reserva e até quando; disponibilidade consultada não é apresentada como reserva |
| RN-044 | Imutabilidade | Alterações no catálogo não reescrevem cotações aceitas (snapshots) |

## 6. Aprovação de compras e orçamento

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-050 | Regras de aprovação | Por valor, centro de custo, unidade, categoria e perfil do solicitante; um ou mais níveis |
| RN-050 | Quórum | Cada nível define quantas aprovações distintas são necessárias; rejeição em qualquer nível encerra |
| RN-051 | Autoaprovação | Proibida quando a regra exige; solicitante não conta como aprovador |
| RN-052 | Perda de acesso | Aprovador revogado deixa de ser elegível; aprovações já registradas permanecem no histórico, e níveis pendentes sem aprovadores elegíveis ficam bloqueados para administração |
| RN-053 | Delegação | Com prazo, auditada; delegado não pode ser o solicitante |
| RN-054 | Reavaliação | Mudança de itens, quantidades, preços, condições ou endereço invalida aprovações |
| RN-055 | Orçamento por centro de custo | Reserva na submissão, consumo na confirmação, liberação em rejeição/cancelamento; concorrência protegida por lock |
| RN-056 | Distinção | Interface informa que aprovação interna não garante estoque nem crédito no distribuidor |

## 7. Carrinho, checkout e pedidos

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-060 | Carrinho, listas, pedido rápido por SKU, recompra | Recompra recalcula preço e disponibilidade vigentes |
| RF-061 | Checkout e confirmação | Valida identidade, permissões, cliente, endereço, aprovações, vigência, preços, embalagens, estoque, crédito e política de pagamento |
| RN-060 | Atomicidade | Pedido, reservas, comprometimento de crédito, eventos e outbox na mesma transação; falha não deixa efeito parcial |
| RN-061 | Idempotência | Chave persistida com escopo, fingerprint e resultado; conteúdo diferente gera conflito (ADR-007) |
| RF-062 | Cancelamento | Total ou parcial de itens não expedidos, liberando reserva e crédito correspondentes |
| RN-062 | Estados separados | Comercial, aprovação, logístico e financeiro (ADR-009) |
| RF-063 | Referência de ordem de compra | Campo de OC interna do comprador no pedido |

## 8. Estoque

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-070 | Estoque por depósito | Físico, reservado, disponível; entrada, reserva, separação, saída, transferência, ajuste, devolução |
| RN-070 | Rastreabilidade | Todo saldo deriva de movimentos append-only (ADR-005) |
| RN-071 | Concorrência | Sem negativo, sem reserva acima do disponível; duas compras pela última unidade: apenas uma confirma |
| RN-072 | Encomenda | Produto define explicitamente se admite venda sem estoque; nunca como efeito de falha |
| RN-073 | Expedição | Consome reserva e reduz físico atomicamente; sem expedição duplicada |
| RN-074 | Transferência | Saída e entrada na mesma transação |

## 9. Crédito comercial

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-080 | Limite comercial | Limite concedido, bloqueio, exceções autorizadas com prazo |
| RN-080 | Fórmula de exposição | `exposição = pedidos confirmados não faturados + faturas em aberto`; `disponível = limite + exceções vigentes − exposição`; vencidos acima da tolerância bloqueiam |
| RN-081 | Transferência | Faturamento move comprometimento para recebível na mesma transação, sem dupla contagem |
| RN-082 | Concorrência | Duas compras não consomem o mesmo limite disponível |
| RN-083 | Liberação | Somente por pagamento confirmado, cancelamento ou crédito confirmado |
| RS-080 | Alteração de limite | Permissão, justificativa e auditoria |

## 10. Expedição, entrega e devoluções

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-090 | Separação, conferência, expedição parcial, múltiplas remessas | Transportadora e rastreamento; confirmação de entrega; ocorrências |
| RN-090 | Consistência por item | cancelado + expedido ≤ pedido; entregue ≤ expedido; devolvido ≤ expedido |
| RF-091 | Devoluções | Autorização, recebimento, inspeção; retorno ao disponível somente após inspeção quando exigido |
| RN-091 | Eventos externos | Duplicados ou fora de ordem não corrompem a entrega |
| RF-092 | Frete | Tabela configurável ou condição negociada; não apresentada como cotação de transportadora |

## 11. Financeiro

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-100 | Contas a receber | Faturas comerciais (não fiscais), parcelas, vencimentos, pagamentos parciais, taxas, reembolsos, créditos, ajustes, conciliação |
| RN-100 | Correções | Somente por lançamentos compensatórios |
| RN-101 | Reembolsos concorrentes | Soma de reembolsos pendentes e efetivados nunca excede o elegível |
| RF-101 | Gateway | Simulador local e adaptador Asaas sandbox; timeout após sucesso não repete cobrança |
| RS-100 | Dados de cartão | PAN e CVV nunca armazenados |
| RF-102 | Indicadores | Ticket médio, conversão, margem, inadimplência com fórmulas documentadas |

## 12. Integrações e arquivos

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RF-110 | API v1 | Sanctum com abilities, vínculo ao tenant, paginação limitada, filtros permitidos, erros estruturados, OpenAPI |
| RF-111 | Webhooks enviados | Assinatura HMAC, timestamp, id estável, retentativas, proteção SSRF |
| RF-112 | Webhooks recebidos | Autenticação do provedor, persistência, deduplicação |
| RF-113 | Importação CSV | Clientes, catálogo e estoque com preview, validação, limites, assíncrona, duplicidade, resultado por linha, política de aplicação parcial, reprocessamento seguro |
| RS-110 | Exportações | Neutralização de fórmulas em campos textuais |
| RS-111 | Anexos | Disco privado, tipo e tamanho validados, download autorizado e vinculado ao tenant |

## 13. Segurança, operação e qualidade

| ID | Requisito | Critérios de aceite |
|---|---|---|
| RS-120 | Web | CSRF, cookies HttpOnly/Secure/SameSite, rate limiting, CSP com nonce, escape de saída |
| RS-121 | Livewire | Ações autorizadas no servidor; IDs e propriedades públicas tratados como entrada não confiável |
| RS-122 | Mass assignment | tenant, papel, preço, limite e campos privilegiados nunca atribuíveis por entrada |
| RS-123 | Operacional | `APP_DEBUG` desligado em produção; Horizon protegido; chave estável com rotação documentada |
| RQ-120 | Outbox e jobs | Ver ADR-008; jobs idempotentes, tentativas limitadas, contexto por job |
| RQ-121 | Observabilidade | Logs JSON com correlação; métricas de latência, erros, fila, idade da outbox, falhas, conflitos; liveness e readiness |
| RQ-122 | Testes | Unitários sem infraestrutura; PostgreSQL real para transações, RLS e locks; concorrência com processos separados e sincronização determinística |
| RQ-123 | Cobertura | ≥ 90% linhas no núcleo (Domain + Actions); ≥ 80% branches nos caminhos críticos; exclusões justificadas |
| RQ-124 | CI | Lockfiles, Pint, PHPStan, audits, segredos, testes, cobertura, navegador, build, migrations em banco limpo, análise de imagem |
