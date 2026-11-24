# Gateway de pagamento

## Asaas indisponível

- Cobranças ficam pendentes com o motivo; nenhuma cobrança é duplicada
  quando o gateway volta (consulta pela referência externa antes de criar).
- `payments:reconcile` roda a cada 15 minutos e cria a cobrança que faltou,
  cancela a de parcela já quitada ou aplica a situação do gateway.
- Para antecipar: `fv exec app php artisan payments:reconcile`.

## Webhook do Asaas recusado

- Endereço: `/webhooks/pagamentos/asaas/{organização}`, com o token do
  distribuidor configurado em Financeiro → Gateway.
- Token trocado na aplicação e não no Asaas (ou o contrário) gera 401:
  gere um novo token pela tela e cadastre o mesmo valor no Asaas.
- Eventos recebidos ficam na fila de conciliação do financeiro e são
  deduplicados pelo identificador do evento.

## Reembolso em verificação

Reembolso sem resposta do gateway fica "em verificação", com o valor
reservado, para não reembolsar duas vezes. `payments:verify-refunds` (a
cada 15 minutos) consulta os estornos da cobrança no Asaas pela referência
do reembolso: efetivado conclui; recusado desfaz a reserva; sem registro
depois de 30 minutos, o pedido não chegou e a reserva é desfeita (o
reembolso pode ser pedido de novo). Para antecipar:
`fv exec app php artisan payments:verify-refunds`. A equipe financeira ainda
pode concluir manualmente pela tela da fatura.
