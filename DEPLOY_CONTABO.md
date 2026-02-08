# 🚀 Guia Completo de Deploy - Contabo VPS

## 📋 Decisões de Arquitetura

### **Opção 1: Tudo na Mesma VPS (Recomendado para Começar)**
✅ **Vantagens:**
- Mais econômico (uma única VPS)
- Configuração mais simples
- Latência baixa (banco e API no mesmo servidor)
- Ideal para projetos pequenos/médios

❌ **Desvantagens:**
- Se a VPS cair, tudo cai junto
- Recursos compartilhados entre banco e aplicação
- Backup precisa ser bem configurado

**Recomendação de VPS Contabo:**
- **VPS S**: 4 vCores, 8GB RAM, 200GB SSD (~€4.99/mês)
- **VPS M**: 6 vCores, 16GB RAM, 400GB SSD (~€8.99/mês) - **Recomendado**

---

### **Opção 2: Banco de Dados Separado (Escalável)**
✅ **Vantagens:**
- Maior disponibilidade
- Escalabilidade independente
- Backup mais fácil
- Melhor para produção crítica

❌ **Desvantagens:**
- Mais caro (2 VPS)
- Configuração mais complexa
- Latência de rede entre servidores

**Recomendação:**
- **VPS S** para API (4 vCores, 8GB RAM)
- **VPS S** para MySQL (4 vCores, 8GB RAM)
- Total: ~€9.98/mês

---

## 🎯 **Nossa Recomendação**

Para começar, recomendamos **Opção 1** (tudo na mesma VPS):
- Mais simples de configurar
- Custo-benefício melhor
- Fácil migrar para Opção 2 depois se necessário

**VPS Recomendada:** Contabo VPS M (6 vCores, 16GB RAM, 400GB SSD)

---

## 📦 Stack Tecnológica

### **Servidor Web:**
- **Nginx** (recomendado) ou Apache
- PHP 8.2+ com PHP-FPM
- Composer

### **Banco de Dados:**
- MySQL 8.0+ ou MariaDB 10.6+

### **Cache/Sessões:**
- Redis 7.0+

### **SSL:**
- Certbot (Let's Encrypt) - Gratuito

### **Process Manager:**
- Supervisor (para queues Laravel)

---

## 🔧 Requisitos da VPS

### **Mínimo:**
- 4 vCores
- 8GB RAM
- 100GB SSD
- Ubuntu 22.04 LTS ou Debian 12

### **Recomendado:**
- 6 vCores
- 16GB RAM
- 200GB+ SSD
- Ubuntu 22.04 LTS

---

## 📝 Checklist Pré-Deploy

- [ ] VPS Contabo criada e acessível via SSH
- [ ] Domínio configurado apontando para IP da VPS
- [ ] Acesso root ou sudo configurado
- [ ] Porta 22 (SSH), 80 (HTTP), 443 (HTTPS) liberadas no firewall
- [ ] Backup do banco de dados atual (se houver)

---

## 🚀 Próximos Passos

1. **Escolher a arquitetura** (Opção 1 ou 2)
2. **Criar VPS na Contabo**
3. **Executar script de setup inicial**
4. **Configurar domínio e SSL**
5. **Fazer deploy do código**
6. **Configurar banco de dados**
7. **Configurar filas e cron jobs**

---

## 📚 Documentação Adicional

- [SETUP_VPS.md](./SETUP_VPS.md) - Setup inicial da VPS
- [DEPLOY_SCRIPT.md](./DEPLOY_SCRIPT.md) - Script de deploy automatizado
- [DATABASE_SETUP.md](./DATABASE_SETUP.md) - Configuração do banco de dados
- [NGINX_CONFIG.md](./NGINX_CONFIG.md) - Configuração do Nginx
- [SSL_SETUP.md](./SSL_SETUP.md) - Configuração SSL/HTTPS

---

## ❓ Dúvidas?

Qual arquitetura você prefere? Opção 1 (tudo junto) ou Opção 2 (separado)?
