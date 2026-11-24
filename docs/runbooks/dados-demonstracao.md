# Dados de demonstração

Para homologação e apresentações. O comando é recusado em produção.

```bash
DEMO_PASSWORD='<senha com 12 ou mais caracteres>' php artisan demo:seed
# ou: php artisan demo:seed --password='<senha>'
```

Cria dois distribuidores isolados entre si:

| Distribuidor | Equipe (`@domínio`) | Compradores |
|---|---|---|
| Distribuidora Pedra Branca (`pedrabranca.example`) | dono, vendas, representante, estoque, financeiro | Construtora Serra Azul (`serraazul.example`), Mercado Bom Preço (`bompreco.example`, CNPJ alfanumérico) |
| Elétrica Horizonte (`horizonte.example`) | dono, vendas, representante, estoque, financeiro | Hospital Vida Plena (`vidaplena.example`), Condomínio Parque das Águas (`parquedasaguas.example`) |

Cada empresa compradora tem `compras@` (comprador) e `admin@`
(administrador da empresa), endereço de entrega, condições à vista e 28
dias, limite de crédito e um pedido já enviado pelo fluxo de compra. O
catálogo tem oito SKUs de material elétrico com preço, custo e estoque.

Perfis com permissões administrativas (dono, financeiro) pedem a
configuração da verificação em duas etapas no primeiro acesso. Os domínios
`.example` são reservados e não recebem e-mail.

O comando não roda duas vezes no mesmo banco; para recriar, use um banco
novo (`php artisan migrate:fresh` com o papel de manutenção, fora de
produção).
