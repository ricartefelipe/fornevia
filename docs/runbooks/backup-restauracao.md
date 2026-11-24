# Backup e restauração

## Backup diário

```bash
fv exec -T postgres pg_dump -U postgres -d fornevia --format=custom --no-owner \
  --file=/backups/fornevia-$(date +%Y%m%d-%H%M).dump
```

- Os arquivos ficam no volume `pgbackups`, montado em `/backups` no
  container do banco. Copie-os para um armazenamento externo, cifrado e com
  retenção: `fv cp postgres:/backups/<arquivo>.dump ./`.
- Arquivos enviados (mídia de catálogo e anexos) ficam no volume
  `storage-app`: inclua-o no backup do host.
- O Redis guarda filas e cache: não precisa de backup, mas jobs na fila no
  momento de uma perda são refeitos pela outbox e pelas varreduras
  agendadas (`webhooks:retry`, `payments:reconcile`).

## Restauração

1. Pare os processos da aplicação: `fv stop app web horizon scheduler outbox`.
2. Restaure em um banco novo para conferir antes de substituir:

```bash
fv exec -T postgres createdb -U postgres -O fornevia_owner fornevia_restaurado
fv exec -T postgres pg_restore -U postgres -d fornevia_restaurado --no-owner \
  --role=fornevia_owner /backups/<arquivo>.dump
```

3. Reaplique os privilégios do papel de aplicação e do relay rodando de novo
   o script de papéis com `FORNEVIA_DATABASES=fornevia_restaurado`.
4. Confira contagens e as políticas de RLS (`select relname, relforcerowsecurity
   from pg_class where relrowsecurity`), troque `DB_DATABASE` ou renomeie os
   bancos e suba os processos.

## Teste de restauração

Restaure o backup mais recente em um ambiente separado ao menos uma vez por
mês e rode `php artisan migrate:status` e a verificação de `/health/ready`.
