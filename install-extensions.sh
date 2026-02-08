#!/bin/bash

echo "🔧 Instalando extensões PHP necessárias..."

# Instalar extensões PHP necessárias
sudo apt-get update
sudo apt-get install -y \
    php8.1-xml \
    php8.1-dom \
    php8.1-gd \
    php8.1-zip \
    php8.1-bcmath \
    php8.1-curl \
    php8.1-mbstring \
    php8.1-tokenizer \
    php8.1-pdo \
    php8.1-mysql

echo "✅ Extensões instaladas!"
echo ""
echo "Agora execute: composer install"
