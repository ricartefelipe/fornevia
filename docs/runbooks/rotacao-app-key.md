# Rotação da APP_KEY

A `APP_KEY` cifra sessões, cookies, segredos de webhooks e chaves do
gateway de pagamento guardados no banco. Ela deve ser estável por ambiente.

## Quando rotacionar

- Suspeita de exposição (imediato).
- Rotina definida pela política de segurança.

## Procedimento

1. Gere a nova chave: `fv run --rm app php artisan key:generate --show`.
2. Em `.env.production`, mova a chave atual para `APP_PREVIOUS_KEYS`
   (separadas por vírgula, a mais recente primeiro) e coloque a nova em
   `APP_KEY`.
3. Suba os processos: `fv up -d`. Dados cifrados com a chave anterior
   continuam legíveis; os novos usam a chave nova.
4. Recifre os segredos guardados pelos distribuidores: peça a cada um que
   troque o segredo dos webhooks (Integrações → Webhooks → Trocar segredo) e
   salve de novo a chave do gateway (Financeiro → Gateway). Entregas de
   webhook com segredo ilegível aparecem como falha "Segredo do endpoint
   ilegível".
5. Depois que nenhum dado depender da chave anterior (sessões expiradas,
   segredos trocados), remova-a de `APP_PREVIOUS_KEYS` e suba de novo.

Em caso de exposição, considere todas as sessões comprometidas: após o passo
3, remova a chave anterior imediatamente (todos os usuários entram de novo) e
trate os segredos de webhooks e gateway como vazados (troca obrigatória).
