<?php

declare(strict_types=1);

/*
| Limites das cotações (ADR-025).
*/
return [
    // Linhas por solicitação ou versão.
    'max_lines' => 100,
    // Validade padrão e máxima da proposta, em dias comerciais a partir de hoje.
    'default_validity_days' => 7,
    'max_validity_days' => 90,
    // Prazo máximo da reserva de estoque, em dias a partir de hoje.
    'max_hold_days' => 7,
    // Pedidos de revisão (contrapropostas) por cotação.
    'max_counteroffers' => 20,
    // Cotações processadas por tenant em cada rodada da expiração.
    'expire_batch' => 100,
];
