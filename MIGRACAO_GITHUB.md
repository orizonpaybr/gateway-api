# Guia de Migração do Backend para Novo Repositório GitHub

## 📋 Pré-requisitos
- Conta GitHub: `orizonpaybr`
- Repositório atual: `Voltusk/gateway-api`
- Novo repositório: `orizonpaybr/[NOME_DO_REPOSITORIO]`

---

## 🚀 Passo a Passo Completo

### **PASSO 1: Criar Novo Repositório no GitHub**

1. Acesse: https://github.com/new
2. Preencha os dados:
   - **Repository name**: `gateway-api` (ou outro nome de sua preferência)
   - **Description**: (opcional) "Gateway API - Backend de pagamentos"
   - **Visibility**: 
     - ✅ **Private** (recomendado para produção)
     - ⬜ Public
   - ⬜ **NÃO marque** "Add a README file"
   - ⬜ **NÃO marque** "Add .gitignore"
   - ⬜ **NÃO marque** "Choose a license"
3. Clique em **"Create repository"**

**⚠️ IMPORTANTE**: Anote o nome exato do repositório que você criou!

---

### **PASSO 2: Preparar Código Local**

Execute os seguintes comandos no terminal:

```bash
cd /home/romano/Pictures/pjct/gateway-api

# Verificar status atual
git status

# Adicionar todas as mudanças pendentes
git add .

# Fazer commit das mudanças
git commit -m "Preparação para migração para novo repositório"
```

---

### **PASSO 3: Trocar Remote do Git**

Substitua `[NOME_DO_REPOSITORIO]` pelo nome exato do repositório criado:

```bash
# Remover o remote antigo
git remote remove origin

# Adicionar o novo remote (substitua [NOME_DO_REPOSITORIO])
git remote add origin git@github.com:orizonpaybr/[NOME_DO_REPOSITORIO].git

# Verificar se foi adicionado corretamente
git remote -v
```

**Exemplo**: Se o repositório for `gateway-api`, o comando será:
```bash
git remote add origin git@github.com:orizonpaybr/gateway-api.git
```

---

### **PASSO 4: Fazer Push para o Novo Repositório**

```bash
# Enviar todas as branches para o novo repositório
git push -u origin feature/producao-treeal
git push -u origin main

# Ou enviar tudo de uma vez
git push --all origin
```

---

### **PASSO 5: Verificar no GitHub**

1. Acesse: `https://github.com/orizonpaybr/[NOME_DO_REPOSITORIO]`
2. Verifique se todos os arquivos foram enviados
3. Confirme que a branch `feature/producao-treeal` está presente

---

## 🔐 Configuração de Autenticação SSH (se necessário)

Se você ainda não configurou SSH no GitHub:

1. **Gerar chave SSH** (se ainda não tiver):
```bash
ssh-keygen -t ed25519 -C "seu-email@exemplo.com"
```

2. **Copiar chave pública**:
```bash
cat ~/.ssh/id_ed25519.pub
```

3. **Adicionar no GitHub**:
   - Acesse: https://github.com/settings/keys
   - Clique em "New SSH key"
   - Cole a chave pública
   - Salve

---

## ✅ Checklist Final

- [ ] Repositório criado no GitHub
- [ ] Mudanças locais commitadas
- [ ] Remote antigo removido
- [ ] Novo remote adicionado
- [ ] Push realizado com sucesso
- [ ] Código visível no novo repositório

---

## 🆘 Troubleshooting

### Erro: "Permission denied (publickey)"
- Configure a chave SSH no GitHub (veja seção acima)

### Erro: "Repository not found"
- Verifique se o nome do repositório está correto
- Confirme que você tem acesso à conta `orizonpaybr`

### Erro: "Updates were rejected"
- Execute: `git push --force origin feature/producao-treeal` (cuidado: isso sobrescreve o histórico)

---

## 📝 Próximos Passos

Após concluir a migração para o GitHub, seguiremos com:
1. Configuração do servidor VPS
2. Deploy automatizado
3. Configuração de CI/CD (opcional)
