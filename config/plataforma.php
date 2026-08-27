<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Regras de taxa PRÓPRIAS da Coratri (independentes de adquirente)
    |--------------------------------------------------------------------------
    |
    | Adquirentes entram e saem do sistema. A política MÍNIMA de cobrança do
    | cliente é NOSSA regra, não pode ser derivada do custo de uma adquirente
    | específica (era o erro de usar a Treeal como piso).
    |
    | Piso da plataforma = mínimo que SEMPRE cobramos do cliente por transação PIX,
    | valha qual adquirente estiver ativa. Aplicado em todos os modos de taxa
    | (fixa em centavos / percentual), em depósito e saque.
    |
    | Obs.: separado do "piso de custo da adquirente" (nunca cobrar menos que o
    | custo real daquela transação — esse sim depende da adquirente ativa e protege
    | a margem). Ver App\Helpers\CustoAdquirentePixHelper::custoTransacao().
    |
    */

    // Componente fixo do piso (R$). Default preserva o comportamento anterior (0,05),
    // mas agora é NOSSO parâmetro, não o custo da Treeal.
    'taxa_minima_fixa' => (float) env('PLATAFORMA_TAXA_MINIMA_FIXA', 0.05),

    // Componente percentual do piso (ex.: 0.5 = 0,5% do valor). 0 = sem piso percentual.
    'taxa_minima_percentual' => (float) env('PLATAFORMA_TAXA_MINIMA_PERCENTUAL', 0.0),

];
