<?php

namespace App\Http\Controllers\Gerencia;

use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\GerenteApoio;
use App\Models\Transactions;
use App\Constants\UserStatus;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\UsersKey;
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Mail, Hash, Auth};

class ClientesController extends Controller
{
    public function index(Request $request)
    {
        if (Auth::user()->permission != \App\Constants\UserPermission::MANAGER) {
            return redirect()->to('/dashboard');
        }
        // Cadastrados hoje
        $usersHoje = User::where('gerente_id', Auth::id())->whereDate('created_at', Carbon::today())->count();

        // Cadastrados na semana
        $usersSemana = User::where('gerente_id', Auth::id())->whereBetween('created_at', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek(),
        ])->count();

        // Cadastrados no mês
        $usersMes = User::where('gerente_id', Auth::id())->whereBetween('created_at', [
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth(),
        ])->count();

        $users = User::where('gerente_id', Auth::id())->get();

        $comissoes = Transactions::where('gerente_id', Auth::user()->user_id)->sum('comission_value');

        $usersTotal = $users->count();
        return view('gerencia.index', compact(
            'users',
            'comissoes',
            'usersHoje',
            'usersSemana',
            'usersMes',
            'usersTotal'
        ));
    }

    public function relatorio(Request $request)
    {
        $query = DB::table('transactions')
            ->where('gerente_id', Auth::id());

        // Filtro de busca
        if ($request->filled('buscar')) {
            $buscar = $request->input('buscar');
            $query->where(function ($q) use ($buscar) {
                $q->where('descricao', 'like', "%{$buscar}%")
                    ->orWhere('nome_cliente', 'like', "%{$buscar}%"); // Adapte para os campos reais
            });
        }

        // Filtro de período
        $periodo = $request->input('periodo', 'hoje');
        switch ($periodo) {
            case 'hoje':
                $query->whereDate('created_at', Carbon::today());
                break;
            case 'ontem':
                $query->whereDate('created_at', Carbon::yesterday());
                break;
            case '7dias':
                $query->whereBetween('created_at', [Carbon::now()->subDays(7), Carbon::now()]);
                break;
            case '30dias':
                $query->whereBetween('created_at', [Carbon::now()->subDays(30), Carbon::now()]);
                break;
            case 'tudo':
                // Nenhum filtro de data
                break;
            case 'personalizado':
                // Exemplo se vier como "2024-05-01:2024-05-20"
                if (strpos($request->periodo, ':') !== false) {
                    [$inicio, $fim] = explode(':', $request->periodo);
                    $query->whereBetween('created_at', [$inicio, $fim]);
                }
                break;
            default:
                // Fallback para hoje se não reconhecido
                $query->whereDate('created_at', Carbon::today());
                break;
        }

        $transactions = $query->get();

        return view('gerencia.relatorio', compact('transactions'));
    }


    public function material(Request $request)
    {
        if (Auth::user()->permission != \App\Constants\UserPermission::MANAGER) {
            return redirect()->to('/dashboard');
        }

        $apoios = GerenteApoio::get();
        return view('gerencia.material', compact('apoios'));
    }

    public function detalhes($id, Request $request)
    {
        // Obter a data e hora atual usando Carbon
        $now = Carbon::now();

        // Início e fim do dia de hoje
        $todayStart = $now->copy()->startOfDay()->toDateTimeString();
        $todayEnd = $now->copy()->endOfDay()->toDateTimeString();

        // Início do mês
        $startOfMonth = $now->copy()->startOfMonth()->toDateTimeString();

        // Início da semana
        $startOfWeek = $now->copy()->startOfWeek()->toDateTimeString();

        // Consultas para obter os totais
        $totalCadastros = User::count();

        $cadastrosHoje = User::whereBetween('data_cadastro', [$todayStart, $todayEnd])
            ->count();

        $cadastrosMes = User::where('data_cadastro', '>=', $startOfMonth)
            ->count();

        $cadastrosSemana = User::where('data_cadastro', '>=', $startOfWeek)
            ->count();

        $usuario = User::find($id);
        $setting = App::first();
        return view('gerencia.clientedetalhe', compact('usuario', 'setting'));
    }

    public function usuarioStatus(Request $request)
    {
        $message = "";
        $usuarioId = $request->input('id');
        $usuario = User::where('id', $usuarioId)->first();

        if (!$usuario) {
            return response()->json(['status' => 'error', 'message' => 'Usuário não encontrado']);
        }

        if ($request->tipo === 'status') {
            // Alternar entre ACTIVE (1) e PENDING (2)
            // Se estiver pendente (2 ou 5 para compatibilidade), aprovar (1)
            // Se estiver aprovado (1), tornar pendente (2)
            $status = ($usuario->status == UserStatus::ACTIVE) ? UserStatus::PENDING : UserStatus::ACTIVE;
            $message = $status == UserStatus::PENDING ? "Status alterado para pendente!" : "Status alterado para Aprovado";
            $usuario->update(['status' => $status]);
        }

        if ($request->tipo === 'banido') {
            $banido = $usuario->banido == 1 ? 0 : 1;
            $message = $banido == 0 ? "Usuário desbanido com sucesso!" : "Usuário banido com sucesso!";
            $usuario->update(['banido' => $banido]);
        }

        return redirect()->back()->with('success', $message);
    }

    public function edit($id, Request $request)
    {
        if (!isset($id)) {
            return redirect()->back()->with('error', "Selecione um usuário!");
        }

        $user = User::find($id);

        if (!isset($user)) {
            return redirect()->back()->with('error', "Usuário não encontrado!");
        }

        $tipo = $request->input('tipo');

        // Se for edição de taxas
        if ($tipo === 'taxas') {
            $taxasData = [
                'taxa_percentual' => $request->input('taxa_percentual', 0),
                'taxa_fixa_baixos' => $request->input('taxa_fixa_baixos', 0),
                'taxa_percentual_altos' => $request->input('taxa_percentual_altos', 0),
                'valor_pago_taxa' => $request->input('valor_pago_taxa', 0),
                'taxa_percentual_pix' => $request->input('taxa_percentual_pix', 0),
                'taxa_minima_pix' => $request->input('taxa_minima_pix', 0),
                'taxa_fixa_pix' => $request->input('taxa_fixa_pix', 0),
                'taxa_percentual_deposito' => $request->input('taxa_percentual_deposito', 0),
                'taxa_fixa_deposito' => $request->input('taxa_fixa_deposito', 0),
                'taxa_saque_api' => $request->input('taxa_saque_api', 0),
                'taxa_saque_crypto' => $request->input('taxa_saque_crypto', 0),
                'observacoes_taxas' => $request->input('observacoes_taxas', ''),
                // Ativar taxas personalizadas para este usuário
                'taxas_personalizadas_ativas' => true,
            ];

            $user->update($taxasData);
            return redirect()->back()->with('success', "Taxas personalizadas ativadas e atualizadas com sucesso! O usuário agora usará essas taxas em vez das taxas do sistema.");
        }

        // Se for desativação de taxas personalizadas
        if ($tipo === 'desativar_taxas') {
            $user->update(['taxas_personalizadas_ativas' => false]);
            return redirect()->back()->with('success', "Taxas personalizadas desativadas com sucesso! O usuário voltará a usar as taxas do sistema.");
        }

        // Edição normal de dados do usuário
        $email = $request->input('email');
        $name = $request->input('name');
        $token = $request->input('token');
        $secret = $request->input('secret');

        if ($user->email != $email) {
            try {
                $request->validate([
                    'email' => ['unique:users,email,' . $id],
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return redirect()->back()->with('error', "Email já cadastrado na base!");
            }
        }

        $payl = [
            'email' => $email,
            'name' => $name
        ];

        if (!is_null($request->password)) {
            $payl['password'] = Hash::make($request->input('password'));
        }

        if ($user) {
            $user->update($payl);

            $userkey = UsersKey::where('user_id', $user->user_id)->first();
            if (is_null($userkey)) {
                $user_id = $user->user_id;
                UsersKey::create(compact('user_id', 'token', 'secret'));
            } else {
                UsersKey::where('user_id', $user->user_id)->update(compact('token', 'secret'));
            }
        }
        return redirect()->back()->with('success', "Usuário alterado com sucesso!");
    }

    /*  public function resetsenha($id, Request $reques)
    {
        $user = User::find($id);

        $newPassword = Str::random(10);
        $user->password = Hash::make($newPassword);
        $user->password_temp = true;
        $user->save();

        // Envia e-mail
        Mail::to($user->email)->send(new \App\Mail\NewPasswordMail($user, $newPassword));

        return back()->with('success', 'Senha enviada com sucesso.');
    } */
}
