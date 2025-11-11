# ⚙️ Configuração Necessária - Aprovação de Saques

## 🔴 IMPORTANTE - Ações Necessárias

### 1. Adicionar Link no Menu de Navegação

A página foi criada mas precisa ser adicionada ao menu do dashboard.

#### Opção A: Menu Lateral (Recomendado)

Localize o componente de menu do dashboard e adicione:

```typescript
// Exemplo de onde adicionar (ajuste conforme seu componente de menu)
{
  id: 'aprovar-saques',
  label: 'Aprovar Saques',
  href: '/dashboard/admin/aprovar-saques',
  icon: <CheckCircle className="w-5 h-5" />,
  permission: 'admin', // Apenas administradores
  badge: totalPendentes, // Opcional: mostrar quantidade pendente
}
```

#### Opção B: Menu Superior

Se usar menu superior, adicione na seção de administração:

```typescript
<MenuItem
  href="/dashboard/admin/aprovar-saques"
  icon={<CheckCircle />}
  requiresAdmin
>
  Aprovar Saques
</MenuItem>
```

### 2. Testar Endpoints da API

Executar no terminal do backend:

```bash
cd gateway-backend
php artisan route:list | grep withdrawal
```

Você deve ver 5 rotas:

- GET /api/admin/withdrawals
- GET /api/admin/withdrawals/{id}
- POST /api/admin/withdrawals/{id}/approve
- POST /api/admin/withdrawals/{id}/reject
- GET /api/admin/withdrawals/stats

### 3. Verificar Banco de Dados

Certifique-se que a tabela `solicitacoes_cash_out` existe e tem todos os campos necessários:

```sql
-- Verificar estrutura
DESCRIBE solicitacoes_cash_out;

-- Campos obrigatórios:
-- id, user_id, externalreference, amount, beneficiaryname,
-- beneficiarydocument, pix, pixkey, date, status, type,
-- taxa_cash_out, cash_out_liquido, descricao_transacao, executor_ordem
```

Se faltar algum campo, rodar as migrations:

```bash
php artisan migrate
```

### 4. Configurar Variáveis de Ambiente (Opcional)

Se quiser personalizar, adicione ao `.env`:

```env
# Limite de valor para aprovação automática (em reais)
SAQUE_AUTOMATICO_LIMITE=1000.00

# Ativar/desativar aprovação automática
SAQUE_AUTOMATICO_ATIVO=true

# Valor mínimo de saque (padrão)
SAQUE_MINIMO=10.00
```

---

## 📋 Checklist de Configuração

### Back-end

- [ ] Verificar se as rotas foram registradas (`php artisan route:list`)
- [ ] Testar endpoint de listagem com token de admin
- [ ] Verificar logs em `storage/logs/laravel.log`
- [ ] Confirmar que migrations foram executadas

### Front-end

- [ ] Adicionar link no menu de navegação
- [ ] Testar acesso à página `/dashboard/admin/aprovar-saques`
- [ ] Verificar se componentes UI estão carregando
- [ ] Testar filtros e paginação
- [ ] Testar ações de aprovar/rejeitar

### Permissões

- [ ] Confirmar que apenas admins (`permission = 3`) acessam
- [ ] Testar com usuário não-admin (deve bloquear)
- [ ] Verificar autenticação Sanctum

### Funcionalidades

- [ ] Criar um saque de teste
- [ ] Ver o saque na lista de pendentes
- [ ] Abrir modal de detalhes
- [ ] Aprovar o saque
- [ ] Verificar se foi processado corretamente
- [ ] Testar rejeição
- [ ] Testar exportação Excel
- [ ] Verificar estatísticas

---

## 🧪 Script de Teste Rápido

### 1. Testar API (Backend)

```bash
# Substitua {TOKEN} pelo token de um admin
curl -X GET "http://localhost:8000/api/admin/withdrawals?status=PENDING" \
  -H "Authorization: Bearer {TOKEN}" \
  -H "Accept: application/json"
```

Resposta esperada:

```json
{
  "success": true,
  "data": {
    "data": [...],
    "current_page": 1,
    "last_page": 1,
    "total": 0
  }
}
```

### 2. Testar Front-end

Acesse no navegador:

```
http://localhost:3000/dashboard/admin/aprovar-saques
```

Deve mostrar:

- 5 cards de estatísticas
- Filtros (status, tipo, data)
- Tabela (vazia ou com dados)
- Sem erros no console (F12)

---

## 🔍 Verificações de Segurança

### Rotas Protegidas

✅ Todas as rotas usam middleware `auth:sanctum`
✅ Verificação de `permission = 3` no controller
✅ Validação de status antes de aprovar/rejeitar

### Front-end

✅ Verificação de permissão antes de renderizar
✅ Confirmação antes de ações destrutivas
✅ Sanitização de inputs

---

## 🚨 Problemas Conhecidos e Soluções

### 1. Erro 404 nas rotas

**Causa:** Routes não registradas
**Solução:**

```bash
php artisan route:clear
php artisan config:clear
php artisan cache:clear
```

### 2. Erro de CORS

**Causa:** Frontend e backend em domínios diferentes
**Solução:** Verificar `config/cors.php` e adicionar frontend URL

### 3. Token não funciona

**Causa:** Token expirado ou inválido
**Solução:** Fazer login novamente

### 4. Página em branco

**Causa:** Erro de build do Next.js
**Solução:**

```bash
cd gateway-web
npm run build
npm run dev
```

---

## 📊 Monitoramento

### Logs para Acompanhar

**Backend:**

```bash
tail -f gateway-backend/storage/logs/laravel.log
```

**Erros específicos de saque:**

```bash
grep "Erro ao.*saque" gateway-backend/storage/logs/laravel.log
```

### Métricas Importantes

1. **Taxa de Aprovação**

   - Total Aprovados / Total Solicitações

2. **Tempo Médio de Aprovação**

   - Diferença entre `created_at` e `updated_at`

3. **Volume por Período**

   - Usar cards de estatísticas

4. **Manual vs Automático**
   - Percentual de cada tipo

---

## 🎯 Próximos Passos Recomendados

Após a configuração inicial:

1. **Teste em Ambiente de Desenvolvimento**

   - Criar saques de teste
   - Aprovar e rejeitar
   - Verificar logs

2. **Treinamento da Equipe**

   - Demonstrar funcionalidades
   - Explicar fluxo de aprovação
   - Mostrar filtros e busca

3. **Configurar Notificações** (Opcional)

   - Email quando novo saque chegar
   - Push notification
   - Integração com Slack/Discord

4. **Ajustar Limites**

   - Definir limite para aprovação automática
   - Configurar valor mínimo de saque
   - Ajustar taxas se necessário

5. **Deploy em Produção**
   - Fazer backup do banco de dados
   - Deploy do backend
   - Deploy do frontend
   - Testar em produção
   - Monitorar logs

---

## ✅ Conclusão da Configuração

Após completar todas as etapas acima:

1. ✅ Rotas da API funcionando
2. ✅ Página acessível no menu
3. ✅ Permissões configuradas
4. ✅ Testes realizados
5. ✅ Equipe treinada

**Sistema pronto para uso em produção! 🚀**

---

**Última Atualização:** 11/11/2025
**Suporte:** Consulte `IMPLEMENTACAO_APROVACAO_SAQUES.md` para detalhes técnicos
