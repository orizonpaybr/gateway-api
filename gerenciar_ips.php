<?php
/**
 * Gerenciador de IPs Permitidos
 * 
 * Este script permite gerenciar os IPs permitidos para saques
 * Uso: php gerenciar_ips.php [comando] [argumentos]
 */

require_once 'vendor/autoload.php';

use App\Models\User;

function mostrarAjuda() {
    echo "=== GERENCIADOR DE IPs PERMITIDOS ===\n\n";
    echo "Comandos disponíveis:\n";
    echo "  listar                    - Lista todos os usuários e seus IPs\n";
    echo "  adicionar <user> <ip>     - Adiciona IP para um usuário\n";
    echo "  remover <user> <ip>       - Remove IP de um usuário\n";
    echo "  configurar <user> <ips>   - Configura lista de IPs (JSON)\n";
    echo "  limpar <user>             - Remove todos os IPs de um usuário\n\n";
    echo "Exemplos:\n";
    echo "  php gerenciar_ips.php listar\n";
    echo "  php gerenciar_ips.php adicionar admin 192.168.1.100\n";
    echo "  php gerenciar_ips.php configurar admin '[\"192.168.1.100\",\"10.0.0.50\"]'\n";
}

function listarUsuarios() {
    echo "=== USUÁRIOS E IPs PERMITIDOS ===\n\n";
    
    $users = User::whereNotNull('ips_saque_permitidos')->get();
    
    if ($users->isEmpty()) {
        echo "Nenhum usuário com IPs configurados encontrado.\n";
        return;
    }
    
    foreach ($users as $user) {
        echo "Usuário: {$user->username}\n";
        echo "IPs: {$user->ips_saque_permitidos}\n";
        
        $ips = json_decode($user->ips_saque_permitidos, true);
        if (is_array($ips)) {
            echo "IPs parseados: " . implode(', ', $ips) . "\n";
        }
        echo "---\n";
    }
}

function adicionarIP($username, $ip) {
    $user = User::where('username', $username)->first();
    
    if (!$user) {
        echo "❌ Usuário '$username' não encontrado.\n";
        return;
    }
    
    // Validar IP
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        echo "❌ IP '$ip' inválido.\n";
        return;
    }
    
    $currentIPs = json_decode($user->ips_saque_permitidos ?? '[]', true);
    
    if (in_array($ip, $currentIPs)) {
        echo "⚠️  IP '$ip' já está na lista do usuário '$username'.\n";
        return;
    }
    
    $currentIPs[] = $ip;
    $user->ips_saque_permitidos = json_encode($currentIPs);
    $user->save();
    
    echo "✅ IP '$ip' adicionado para o usuário '$username'.\n";
    echo "IPs atuais: " . implode(', ', $currentIPs) . "\n";
}

function removerIP($username, $ip) {
    $user = User::where('username', $username)->first();
    
    if (!$user) {
        echo "❌ Usuário '$username' não encontrado.\n";
        return;
    }
    
    $currentIPs = json_decode($user->ips_saque_permitidos ?? '[]', true);
    $newIPs = array_filter($currentIPs, function($currentIP) use ($ip) {
        return $currentIP !== $ip;
    });
    
    if (count($newIPs) === count($currentIPs)) {
        echo "⚠️  IP '$ip' não encontrado na lista do usuário '$username'.\n";
        return;
    }
    
    $user->ips_saque_permitidos = json_encode(array_values($newIPs));
    $user->save();
    
    echo "✅ IP '$ip' removido do usuário '$username'.\n";
    echo "IPs restantes: " . implode(', ', $newIPs) . "\n";
}

function configurarIPs($username, $ipsJson) {
    $user = User::where('username', $username)->first();
    
    if (!$user) {
        echo "❌ Usuário '$username' não encontrado.\n";
        return;
    }
    
    $ips = json_decode($ipsJson, true);
    
    if (!is_array($ips)) {
        echo "❌ Formato JSON inválido para IPs.\n";
        return;
    }
    
    // Validar todos os IPs
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            echo "❌ IP '$ip' inválido.\n";
            return;
        }
    }
    
    $user->ips_saque_permitidos = json_encode($ips);
    $user->save();
    
    echo "✅ IPs configurados para o usuário '$username'.\n";
    echo "IPs: " . implode(', ', $ips) . "\n";
}

function limparIPs($username) {
    $user = User::where('username', $username)->first();
    
    if (!$user) {
        echo "❌ Usuário '$username' não encontrado.\n";
        return;
    }
    
    $user->ips_saque_permitidos = null;
    $user->save();
    
    echo "✅ Todos os IPs removidos do usuário '$username'.\n";
    echo "⚠️  ATENÇÃO: Saques serão BLOQUEADOS para este usuário!\n";
}

// Processar argumentos da linha de comando
$comando = $argv[1] ?? 'ajuda';

switch ($comando) {
    case 'listar':
        listarUsuarios();
        break;
        
    case 'adicionar':
        if (count($argv) < 4) {
            echo "❌ Uso: php gerenciar_ips.php adicionar <user> <ip>\n";
            exit(1);
        }
        adicionarIP($argv[2], $argv[3]);
        break;
        
    case 'remover':
        if (count($argv) < 4) {
            echo "❌ Uso: php gerenciar_ips.php remover <user> <ip>\n";
            exit(1);
        }
        removerIP($argv[2], $argv[3]);
        break;
        
    case 'configurar':
        if (count($argv) < 4) {
            echo "❌ Uso: php gerenciar_ips.php configurar <user> <ips_json>\n";
            exit(1);
        }
        configurarIPs($argv[2], $argv[3]);
        break;
        
    case 'limpar':
        if (count($argv) < 3) {
            echo "❌ Uso: php gerenciar_ips.php limpar <user>\n";
            exit(1);
        }
        limparIPs($argv[2]);
        break;
        
    default:
        mostrarAjuda();
        break;
}
?>
