<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\UsersKey;
use Illuminate\Support\Facades\Log;

class EncryptUserKeys extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:encrypt-keys {--dry-run : Simular sem alterar dados}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Criptografa os tokens e secrets dos usuários no banco de dados';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando criptografia das chaves de usuários...');
        
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->warn('Modo DRY-RUN ativado - nenhuma alteração será feita');
        }

        $total = UsersKey::count();
        $encrypted = 0;
        $alreadyEncrypted = 0;
        $errors = 0;
        
        $this->info("Total de registros: {$total}");
        
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        
        UsersKey::chunk(100, function ($keys) use (&$encrypted, &$alreadyEncrypted, &$errors, $dryRun, $bar) {
            foreach ($keys as $key) {
                try {
                    if ($key->areCredentialsEncrypted()) {
                        $alreadyEncrypted++;
                    } else {
                        if (!$dryRun) {
                            if ($key->encryptCredentials()) {
                                $encrypted++;
                            } else {
                                $errors++;
                            }
                        } else {
                            $encrypted++;
                        }
                    }
                } catch (\Exception $e) {
                    $errors++;
                    Log::error('EncryptUserKeys - Erro ao processar', [
                        'user_id' => $key->user_id,
                        'error' => $e->getMessage(),
                    ]);
                }
                
                $bar->advance();
            }
        });
        
        $bar->finish();
        $this->newLine(2);
        
        $this->info("Resumo:");
        $this->line("  - Já criptografados: {$alreadyEncrypted}");
        $this->line("  - Criptografados agora: {$encrypted}");
        
        if ($errors > 0) {
            $this->error("  - Erros: {$errors}");
        }
        
        if ($dryRun) {
            $this->newLine();
            $this->warn("Este foi um DRY-RUN. Execute sem --dry-run para aplicar as alterações.");
        }
        
        return $errors > 0 ? 1 : 0;
    }
}
