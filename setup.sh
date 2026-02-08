#!/bin/bash

echo "🚀 Configurando Gateway API..."

# Verificar se o Composer está instalado
if ! command -v composer &> /dev/null; then
    echo "❌ Composer não está instalado!"
    echo "📦 Instale o Composer com: sudo apt install composer"
    exit 1
fi

# Instalar dependências
echo "📦 Instalando dependências do Composer..."
composer install --no-interaction

# Gerar chave da aplicação
echo "🔑 Gerando chave da aplicação..."
php artisan key:generate

# Criar link simbólico do storage
echo "📁 Criando link simbólico do storage..."
php artisan storage:link

echo "✅ Gateway API configurado!"
echo ""
echo "📝 Próximos passos:"
echo "1. Configure o banco de dados no arquivo .env"
echo "2. Execute: php artisan migrate"
echo "3. Execute: php artisan serve"
echo ""
echo "🌐 A API estará disponível em: http://localhost:8000"
