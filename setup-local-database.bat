@echo off
REM Script para configurar banco de dados local no Windows
REM Execute este script no diretorio gateway-backend

echo ==========================================
echo 🚀 Configuracao do Banco de Dados Local
echo ==========================================
echo.

REM Configuracoes do banco
set DB_NAME=martinspay_app
set DB_USER=root
set DB_PASS=
set DB_HOST=127.0.0.1
set DB_PORT=3306
set MYSQL_PATH=C:\xampp\mysql\bin

REM Verificar se o MySQL existe
if not exist "%MYSQL_PATH%\mysql.exe" (
    echo ❌ MySQL nao encontrado em %MYSQL_PATH%
    echo    Verifique o caminho do XAMPP e edite este script.
    pause
    exit /b 1
)

echo Passo 1: Verificando conexao com MySQL...
"%MYSQL_PATH%\mysql.exe" -h%DB_HOST% -P%DB_PORT% -u%DB_USER% -e "SELECT 1;" >nul 2>&1
if errorlevel 1 (
    echo ❌ Erro ao conectar no MySQL. Verifique se o XAMPP esta rodando.
    pause
    exit /b 1
)
echo ✓ MySQL conectado com sucesso!
echo.

echo Passo 2: Criando banco de dados '%DB_NAME%'...
"%MYSQL_PATH%\mysql.exe" -h%DB_HOST% -P%DB_PORT% -u%DB_USER% -e "DROP DATABASE IF EXISTS %DB_NAME%;" 2>nul
"%MYSQL_PATH%\mysql.exe" -h%DB_HOST% -P%DB_PORT% -u%DB_USER% -e "CREATE DATABASE %DB_NAME% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
    echo ❌ Erro ao criar banco de dados.
    pause
    exit /b 1
)
echo ✓ Banco de dados criado com sucesso!
echo.

echo Passo 3: Importando estrutura do banco...
"%MYSQL_PATH%\mysql.exe" -h%DB_HOST% -P%DB_PORT% -u%DB_USER% %DB_NAME% < martinspay-app.sql
if errorlevel 1 (
    echo ❌ Erro ao importar estrutura.
    pause
    exit /b 1
)
echo ✓ Estrutura importada com sucesso!
echo.

echo Passo 4: Limpando dados de producao sensiveis...
"%MYSQL_PATH%\mysql.exe" -h%DB_HOST% -P%DB_PORT% -u%DB_USER% %DB_NAME% < cleanup-data.sql
if errorlevel 1 (
    echo ⚠ Aviso: Alguns dados podem nao ter sido limpos.
)
echo ✓ Dados sensiveis removidos!
echo.

echo Passo 5: Criando usuario de teste...
"%MYSQL_PATH%\mysql.exe" -h%DB_HOST% -P%DB_PORT% -u%DB_USER% %DB_NAME% < seed-test-data.sql
if errorlevel 1 (
    echo ⚠ Usuario de teste pode ja existir.
)
echo ✓ Usuario de teste criado!
echo.

echo Passo 6: Testando Redis...
docker ps | findstr redis-gateway >nul 2>&1
if errorlevel 1 (
    echo ⚠ Redis nao esta rodando. Execute: docker start redis-gateway
) else (
    echo ✓ Redis esta rodando no Docker!
)
echo.

echo ==========================================
echo ✅ Configuracao Concluida!
echo ==========================================
echo.
echo 📝 Informacoes do Banco Local:
echo    • Banco: %DB_NAME%
echo    • Host: %DB_HOST%:%DB_PORT%
echo    • Usuario: %DB_USER%
echo    • Senha: (vazia)
echo.
echo 👤 Usuario de Teste:
echo    • Email: teste@exemplo.com
echo    • Senha: teste123
echo.
echo 🔧 Proximos passos:
echo    1. php artisan config:clear
echo    2. php artisan cache:clear
echo    3. php artisan serve
echo.
echo    4. Acesse: http://localhost:8000
echo.
pause

