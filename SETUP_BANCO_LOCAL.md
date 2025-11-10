# 🗄️ Guia de Configuração do Banco de Dados Local

## 📋 Pré-requisitos

Antes de começar, verifique se você tem:

- ✅ **XAMPP** instalado e rodando (MySQL na porta 3306)
- ✅ **Docker Desktop** instalado e rodando (Redis na porta 6379)
- ✅ **PHP** instalado e configurado
- ✅ **Composer** instalado

## 🚀 Método 1: Automático (Recomendado)

### No Windows (usando Git Bash):

```bash
cd gateway-backend
./setup-local-database.sh
```

### No Windows (usando CMD):

```cmd
cd gateway-backend
setup-local-database.bat
```

## 🔧 Método 2: Manual (Passo a Passo)

Se preferir fazer manualmente ou se os scripts automáticos não funcionarem:

### Passo 1: Abrir o phpMyAdmin

1. Acesse: http://localhost/phpmyadmin
2. Faça login (usuário: `root`, senha: vazia)

### Passo 2: Criar o Banco de Dados

Execute o seguinte SQL no phpMyAdmin:

```sql
DROP DATABASE IF EXISTS martinspay_app;
CREATE DATABASE martinspay_app CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### Passo 3: Importar a Estrutura

1. Selecione o banco `martinspay_app` na lateral esquerda
2. Clique na aba **"Importar"**
3. Clique em **"Escolher arquivo"**
4. Navegue até `C:\gateway-orizon\gateway-backend\martinspay-app.sql`
5. Clique em **"Executar"**

⏳ Aguarde a importação (pode demorar alguns minutos)

### Passo 4: Limpar Dados Sensíveis

No phpMyAdmin, execute o SQL do arquivo `cleanup-data.sql`:

1. Clique na aba **"SQL"**
2. Copie o conteúdo do arquivo `cleanup-data.sql`
3. Cole na caixa de texto
4. Clique em **"Executar"**

### Passo 5: Criar Dados de Teste

Execute o SQL do arquivo `seed-test-data.sql`:

1. Clique na aba **"SQL"**
2. Copie o conteúdo do arquivo `seed-test-data.sql`
3. Cole na caixa de texto
4. Clique em **"Executar"**

### Passo 6: Verificar Redis

Abra o Docker Desktop e verifique se o container `redis-gateway` está rodando.

Se não estiver, execute:

```bash
docker start redis-gateway
```

### Passo 7: Configurar o Laravel

No diretório `gateway-backend`, execute:

```bash
php artisan config:clear
php artisan cache:clear
php artisan storage:link
```

### Passo 8: Iniciar o Servidor

```bash
php artisan serve
```

Acesse: http://localhost:8000

## 👤 Usuários de Teste Criados

Após a configuração, você terá os seguintes usuários:

### Administrador
- **Email:** admin@exemplo.com
- **Senha:** teste123
- **Permissão:** admin

### Usuário Normal
- **Email:** teste@exemplo.com
- **Senha:** teste123
- **Permissão:** user

## 🔍 Verificação

Para verificar se tudo está funcionando:

### 1. Testar conexão com MySQL:

```bash
php artisan tinker
```

```php
DB::connection()->getPdo();
// Deve retornar um objeto PDO
```

### 2. Testar conexão com Redis:

```php
Redis::ping();
// Deve retornar "+PONG"
```

### 3. Verificar usuários:

```php
App\Models\User::count();
// Deve retornar pelo menos 2 (os usuários de teste)
```

## ⚠️ Problemas Comuns

### MySQL não conecta

**Solução:**
1. Abra o XAMPP Control Panel
2. Verifique se o MySQL está com status **"Running"** (verde)
3. Se não estiver, clique em **"Start"**

### Redis não conecta

**Solução:**
1. Abra o Docker Desktop
2. Verifique se o container `redis-gateway` está rodando
3. Se não estiver, execute: `docker start redis-gateway`

### Erro ao importar SQL

**Solução:**
1. Verifique se o arquivo `martinspay-app.sql` existe
2. Tente importar em partes menores usando o terminal:

```bash
cd gateway-backend
mysql -uroot martinspay_app < martinspay-app.sql
```

### Erro "Access denied"

**Solução:**
1. Verifique as configurações do `.env`:
   - `DB_USERNAME=root`
   - `DB_PASSWORD=` (vazio)
   - `DB_DATABASE=martinspay_app`
   - `DB_HOST=127.0.0.1`
   - `DB_PORT=3306`

## 📊 Estrutura do Banco

Após a importação, você terá:

- ✅ **45+ tabelas** com estrutura completa
- ✅ **Usuários de teste** prontos para uso
- ✅ **Níveis de gamificação** configurados
- ✅ **Adquirentes** cadastrados
- ✅ **Transações de exemplo**
- ❌ **Sem dados sensíveis** de produção

## 🔄 Resetar o Banco

Se precisar resetar tudo do zero:

```bash
cd gateway-backend
./setup-local-database.sh  # ou .bat no Windows CMD
```

Ou manualmente:

```sql
DROP DATABASE martinspay_app;
-- E siga os passos novamente
```

## 📝 Próximos Passos

Após configurar o banco:

1. ✅ Testar login com os usuários de teste
2. ✅ Verificar se as imagens estão carregando (agora do banco local)
3. ✅ Testar as funcionalidades de depósito e saque
4. ✅ Verificar o painel de administração

## 🆘 Suporte

Se encontrar problemas:

1. Verifique os logs do Laravel: `storage/logs/laravel.log`
2. Verifique os logs do MySQL no XAMPP
3. Verifique se todos os serviços estão rodando

---

**Desenvolvido para Gateway Orizon** 🚀

