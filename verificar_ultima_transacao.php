<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\SolicitacoesCashOut;

echo "Verificando última transação de saque:\n";
echo "=====================================\n";

$ultimaTransacao = SolicitacoesCashOut::where('user_id', 'admin')
    ->where('descricao_transacao', 'AUTOMATICO')
    ->orderBy('created_at', 'desc')
    ->first();

if ($ultimaTransacao) {
    echo "Transação encontrada:\n";
    echo "ID: " . $ultimaTransacao->id . "\n";
    echo "Transaction ID: " . $ultimaTransacao->idTransaction . "\n";
    echo "Valor: R$ " . number_format($ultimaTransacao->amount, 2, ',', '.') . "\n";
    echo "Status: " . $ultimaTransacao->status . "\n";
    echo "Callback: " . ($ultimaTransacao->callback ?: 'NÃO CONFIGURADO') . "\n";
    echo "Data: " . $ultimaTransacao->created_at . "\n";
} else {
    echo "Nenhuma transação encontrada!\n";
}
?>
