# 🚀 PRÓXIMOS PASSOS - Gateway Backend

## ✅ O que foi feito

1. ✅ Análise completa de segurança (nenhum malware encontrado)
2. ✅ Remoção de todos os componentes de front-end
3. ✅ Limpeza de dependências desnecessárias
4. ✅ Remoção de arquivos de teste e desenvolvimento
5. ✅ Remoção de arquivos de segurança (phpinfo.php, etc.)
6. ✅ Criação de documentação completa

---

## 📋 Checklist Antes de Usar

### 1. Instalar Dependências PHP

```bash
composer install
```

**Importante:** Isso irá reinstalar as dependências PHP limpas (sem front-end).

### 2. Configurar Ambiente

```bash
# Copiar arquivo de exemplo
cp env_example.txt .env

# Editar o .env com suas configurações
nano .env  # ou use seu editor preferido
```

**Configurações obrigatórias no .env:**

-   [ ] `APP_KEY` (será gerado no próximo passo)
-   [ ] `APP_URL` (URL da sua aplicação)
-   [ ] `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
-   [ ] Credenciais dos adquirentes que você usa
-   [ ] Configurações de email (MAIL\_\*)

### 3. Gerar Chave da Aplicação

```bash
php artisan key:generate
```

### 4. Executar Migrations

```bash
# Criar banco de dados primeiro, depois:
php artisan migrate
```

### 5. Criar Link Simbólico do Storage

```bash
php artisan storage:link
```

### 6. Configurar Permissões (Linux/Mac)

```bash
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

No Windows, isso não é necessário.

### 7. Testar a API

```bash
# Iniciar servidor de desenvolvimento
php artisan serve

# Em outro terminal, teste:
curl http://localhost:8000/api/documentation
```

---

## 🔌 Integração com Novo Front-end

### Opções de Consumo da API:

#### 1. **React / Vue / Angular**

```javascript
// Exemplo com Axios
import axios from "axios";

const api = axios.create({
    baseURL: "http://localhost:8000/api",
    headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
    },
});

// Login
const response = await api.post("/auth/login", {
    username: "usuario",
    password: "senha",
});

const token = response.data.token;

// Usar token em requisições
api.defaults.headers.common["Authorization"] = `Bearer ${token}`;
api.defaults.headers.common["X-User-Secret"] = "sua_secret_key";

// Buscar saldo
const balance = await api.get("/balance");
```

#### 2. **Next.js / Nuxt.js**

Use a API em rotas server-side ou client-side normalmente.

#### 3. **Mobile (React Native / Flutter)**

Configure a baseURL para o IP/domínio do seu backend.

### CORS

O projeto já tem CORS configurado. Verifique em `config/cors.php` se precisa ajustar.

---

## 🔐 Configuração de Segurança

### 1. Configurar IPs Permitidos para Saques

```bash
# Adicionar IP permitido para um usuário
php gerenciar_ips.php adicionar username 192.168.1.100

# Listar IPs configurados
php gerenciar_ips.php listar

# Ver ajuda
php gerenciar_ips.php
```

### 2. Habilitar 2FA para Usuários

Através da API:

```bash
POST /api/2fa/generate-qr
POST /api/2fa/verify
POST /api/2fa/enable
```

### 3. Configurar Webhooks dos Adquirentes

Configure as URLs de callback no painel de cada adquirente:

```
Pixup:     https://seu-dominio.com/api/pixup/callback/deposit
BSPay:     https://seu-dominio.com/api/bspay/callback/deposit
Asaas:     https://seu-dominio.com/api/asaas/callback/deposit
PrimePay7: https://seu-dominio.com/api/primepay7/callback
XDPag:     https://seu-dominio.com/api/xdpag/callback/deposit
Woovi:     https://seu-dominio.com/api/woovi/callback
```

---

## 🛠️ Desenvolvimento

### Estrutura de Pastas Importantes

```
app/
├── Http/
│   ├── Controllers/
│   │   ├── Api/                    # Controllers da API
│   │   │   ├── AuthController.php  # Autenticação
│   │   │   ├── UserController.php  # Usuário
│   │   │   ├── DepositController.php
│   │   │   ├── SaqueController.php
│   │   │   └── Adquirentes/        # Controllers dos gateways
│   │   └── ...
│   ├── Middleware/                 # Middlewares personalizados
│   └── Requests/                   # Form Requests
├── Models/                         # Models Eloquent
├── Services/                       # Serviços de integração
│   ├── AsaasService.php
│   ├── BSPayService.php
│   ├── PixupService.php
│   └── ...
├── Traits/                         # Traits reutilizáveis
└── Helpers/                        # Funções auxiliares

routes/
├── api.php                         # Rotas da API
├── web.php                         # Rotas web (mínimas)
└── groups/                         # Grupos de rotas

database/
├── migrations/                     # 96 migrations
└── seeders/                        # Seeders
```

### Criar Novo Endpoint

1. Criar controller:

```bash
php artisan make:controller Api/MeuController
```

2. Adicionar rota em `routes/api.php`:

```php
Route::middleware(['check.token.secret'])->group(function () {
    Route::get('minha-rota', [MeuController::class, 'index']);
});
```

### Adicionar Novo Adquirente

1. Criar service: `app/Services/NovoAdquirenteService.php`
2. Criar trait: `app/Traits/NovoAdquirenteTrait.php`
3. Criar controller: `app/Http/Controllers/Api/Adquirentes/NovoAdquirenteController.php`
4. Adicionar rotas de callback em `routes/api.php`
5. Criar migration para tabela de configuração
6. Adicionar no enum de adquirentes

---

## 📊 Monitoramento e Logs

### Ver Logs

```bash
tail -f storage/logs/laravel.log
```

### Limpar Logs

```bash
php artisan log:clear  # Se o comando existir
# Ou manualmente:
> storage/logs/laravel.log
```

### Monitorar Filas (se usar)

```bash
php artisan queue:work
```

---

## 🚀 Deploy em Produção

### 1. Servidor Web (Apache/Nginx)

**Nginx Example:**

```nginx
server {
    listen 80;
    server_name seu-dominio.com;
    root /var/www/gateway-backend/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 2. SSL/HTTPS

```bash
# Usando Certbot (Let's Encrypt)
sudo certbot --nginx -d seu-dominio.com
```

### 3. Otimizações

```bash
# Cache de configuração
php artisan config:cache

# Cache de rotas
php artisan route:cache

# Otimizar autoload
composer install --optimize-autoloader --no-dev

# Modo de produção no .env
APP_ENV=production
APP_DEBUG=false
```

### 4. Supervisor (Para Queue Workers)

```ini
[program:gateway-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/gateway-backend/artisan queue:work --sleep=3 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/gateway-backend/storage/logs/worker.log
```

### 5. Backup Automático

Configure backup do banco de dados:

```bash
# Adicionar ao crontab
0 2 * * * mysqldump -u usuario -psenha database > /backup/db_$(date +\%Y\%m\%d).sql
```

---

## 📚 Documentação Adicional

Arquivos criados para referência:

-   `RELATORIO_ANALISE_SEGURANCA_E_LIMPEZA.md` - Relatório completo de auditoria
-   `README_BACKEND.md` - Documentação técnica completa
-   `ARQUIVOS_REMOVIDOS.txt` - Lista de tudo que foi removido
-   `doc.md` - Documentação original do projeto
-   `configuracao_protecao_ip.md` - Guia de proteção por IP

### Swagger/OpenAPI

Acesse em:

```
http://seu-dominio.com/api/documentation
```

---

## ⚠️ IMPORTANTE

### Antes de Usar em Produção:

1. [ ] Altere todas as senhas padrão
2. [ ] Configure `.env` corretamente
3. [ ] Ative HTTPS (SSL)
4. [ ] Configure firewall
5. [ ] Teste todos os endpoints
6. [ ] Configure backup automático
7. [ ] Configure monitoramento
8. [ ] Revise logs regularmente
9. [ ] Mantenha dependências atualizadas
10. [ ] Configure rate limiting adequado

### Arquivos Sensíveis (NUNCA commitar):

-   `.env`
-   `storage/logs/*`
-   `storage/framework/sessions/*`
-   Qualquer arquivo com credenciais

---

## 🆘 Troubleshooting

### Erro: "No application encryption key has been specified"

```bash
php artisan key:generate
```

### Erro: "Class not found"

```bash
composer dump-autoload
```

### Erro de permissão em storage/

```bash
chmod -R 775 storage bootstrap/cache
```

### Erro de CORS

Verifique `config/cors.php` e adicione seu domínio front-end em `allowed_origins`.

### Callback não funciona

1. Verifique se a URL está correta no painel do adquirente
2. Verifique os logs: `storage/logs/laravel.log`
3. Teste manualmente com Postman/Insomnia

---

## 📞 Suporte

Em caso de dúvidas:

1. Consulte a documentação Swagger
2. Veja os arquivos de documentação (.md)
3. Analise os logs em `storage/logs/`
4. Verifique os testes em `tests/`

---

## ✅ Checklist Final

Antes de considerar o projeto pronto:

-   [ ] Dependências instaladas (`composer install`)
-   [ ] `.env` configurado
-   [ ] Chave gerada (`php artisan key:generate`)
-   [ ] Migrations executadas (`php artisan migrate`)
-   [ ] Storage linkado (`php artisan storage:link`)
-   [ ] Testes rodando (`php artisan test`)
-   [ ] API respondendo (`php artisan serve`)
-   [ ] Documentação acessível (`/api/documentation`)
-   [ ] Webhooks configurados nos adquirentes
-   [ ] SSL configurado (produção)
-   [ ] Backup configurado (produção)

---

**Boa sorte com seu novo front-end! 🚀**

O back-end está limpo, seguro e pronto para uso.
