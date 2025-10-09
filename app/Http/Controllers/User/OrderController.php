<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\CheckoutBuild;
use App\Models\CheckoutOrders;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        $buscar = $request->input('buscar');
        $status = $request->input('status');

        // Monta a query com join para buscar no checkout.name
        $ordersQuery = CheckoutOrders::join('checkout_build', 'checkout_orders.checkout_id', '=', 'checkout_build.id')
            ->select('checkout_orders.*', 'checkout_build.produto_name as checkout_name');

        // Busca case-insensitive nos campos pedidos e no checkout.name - SEGURO
        if ($buscar) {
            // Sanitizar entrada para prevenir SQL injection
            $searchTerm = preg_replace('/[^a-zA-Z0-9\s@._-]/', '', $buscar);
            $searchTerm = trim($searchTerm);
            
            if (!empty($searchTerm)) {
                $ordersQuery->where(function ($q) use ($searchTerm) {
                    $q->whereRaw('LOWER(checkout_orders.name) LIKE ?', ['%' . strtolower($searchTerm) . '%'])
                        ->orWhereRaw('LOWER(checkout_orders.telefone) LIKE ?', ['%' . strtolower($searchTerm) . '%'])
                        ->orWhereRaw('LOWER(checkout_orders.email) LIKE ?', ['%' . strtolower($searchTerm) . '%'])
                        ->orWhereRaw('LOWER(checkout_build.produto_name) LIKE ?', ['%' . strtolower($searchTerm) . '%']);
                });
            }
        }

        // Filtro por status
        if ($status && $status != 'todos') {
            switch ($status) {
                case 'pagos':
                    $ordersQuery->where('checkout_orders.status', 'pago');
                    break;
                case 'pendentes':
                    $ordersQuery->where('checkout_orders.status', 'gerado');
                    break;
                case 'med':
                    $ordersQuery->where('checkout_orders.status', 'med');
                    break;
                case 'chargeback':
                    $ordersQuery->where('checkout_orders.status', 'cancelado');
                    break;
                case 'reembolso':
                    $ordersQuery->where('checkout_orders.status', 'reembolso');
                    break;
            }
        }

        // Pega resultados
        $orders = $ordersQuery->get();

        // Opcional: busca os checkouts do usuário para a view (se precisar)
        $checkouts = CheckoutBuild::where('user_id', auth()->user()->id)->get();

        return view('profile.orders', compact('orders', 'checkouts'));
    }
}
