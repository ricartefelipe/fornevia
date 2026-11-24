# Runbooks de operação

Procedimentos para operar o Fornevia em produção com `compose.prod.yaml`.
Comandos assumem o alias:

```bash
alias fv='docker compose -f compose.prod.yaml --env-file .env.production'
```

| Runbook | Quando usar |
|---|---|
| [Implantação e atualização](implantacao.md) | primeira instalação, nova versão, volta de versão |
| [Rotação da APP_KEY](rotacao-app-key.md) | chave exposta ou rotação periódica |
| [Backup e restauração](backup-restauracao.md) | rotina diária, recuperação de dados, teste de restauração |
| [Outbox, filas e webhooks](outbox-filas-webhooks.md) | alerta de idade da outbox, fila acumulada, jobs ou entregas com falha |
| [Gateway de pagamento](gateway-pagamentos.md) | Asaas indisponível, cobrança ausente, reembolso em verificação |
| [Alertas e métricas](alertas.md) | configurar a coleta e responder a cada alerta |
| [Dados de demonstração](dados-demonstracao.md) | ambiente de homologação ou apresentação |

Princípios:

- Migrations rodam só com o papel de manutenção (`migrate`), nunca na
  inicialização dos processos da aplicação.
- Nenhum procedimento desliga o RLS ou usa um papel com `BYPASSRLS`.
- Toda ação manual sobre dados de um distribuidor fica registrada: prefira
  as telas e comandos da aplicação a SQL direto.
