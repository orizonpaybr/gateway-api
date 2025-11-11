# 🚀 Guia Rápido - Aprovação de Saques

## ⚡ Início Rápido

### 1. Acesse a página

```
/dashboard/admin/aprovar-saques
```

### 2. O que você verá

- 5 cards com estatísticas em tempo real
- Filtros por status, tipo e data
- Lista de saques com paginação
- Ações de aprovar, rejeitar e visualizar

---

## 🎯 Principais Funcionalidades

### Filtrar Saques

- **Por Status:** Pendentes | Aprovados | Rejeitados | Todos
- **Por Tipo:** Manual | Automático | Todos
- **Por Data:** Hoje | 7 dias | 30 dias | Personalizado

### Ações Disponíveis

- 👁️ **Ver Detalhes** - Abre modal com todas as informações
- ✅ **Aprovar** - Processa o pagamento (apenas pendentes)
- ❌ **Rejeitar** - Devolve o valor ao usuário (apenas pendentes)
- 📊 **Exportar** - Gera arquivo Excel com os dados filtrados

### Busca Inteligente

Digite para buscar por:

- Nome do cliente
- Documento (CPF/CNPJ)
- ID da transação
- Email do usuário
- Username

---

## 📊 Cards de Estatísticas

1. **Pendentes** - Total de saques aguardando aprovação
2. **Aprovados Hoje** - Quantidade aprovada no dia
3. **Rejeitados Hoje** - Quantidade rejeitada no dia
4. **Valor Aprovado** - Soma dos valores aprovados hoje
5. **Manual / Auto** - Comparativo de saques manuais vs automáticos

---

## 🔄 Fluxo de Aprovação

### Aprovar Saque

1. Localize o saque pendente
2. Clique no ícone de olho (👁️) para ver detalhes
3. Revise todas as informações
4. Clique em "Aprovar"
5. Confirme a ação
6. O sistema processará o pagamento automaticamente

### Rejeitar Saque

1. Localize o saque pendente
2. Clique no ícone de olho (👁️) para ver detalhes
3. Clique em "Rejeitar"
4. Confirme a ação
5. O valor será devolvido ao saldo do usuário

---

## ⚙️ Configuração Inicial

### Adicionar ao Menu (Necessário)

Edite o arquivo de menu do dashboard para incluir:

```typescript
{
  name: 'Aprovar Saques',
  path: '/dashboard/admin/aprovar-saques',
  icon: <CheckCircle />,
  permission: 'admin', // Apenas administradores
}
```

### Verificar Permissões

O usuário deve ter:

- `permission = 3` (Administrador)
- Token Sanctum válido

---

## 🔧 Modo Automático vs Manual

### Modo Automático

Saques processados automaticamente quando:

- Valor <= limite configurado
- Saque automático ativado nas configurações
- Todas as validações passam

### Modo Manual

Saques que requerem aprovação manual:

- Valor > limite configurado
- Saque automático desativado
- Primeira vez do usuário (opcional)

**Configurar em:** Painel Admin > Configurações de Saque

---

## 📈 Boas Práticas

### Aprovação

✅ Sempre revise os detalhes antes de aprovar
✅ Verifique o saldo do usuário
✅ Confirme a chave PIX
✅ Valide o documento

### Rejeição

✅ Só rejeite se houver irregularidade
✅ O valor volta automaticamente
✅ O usuário será notificado

### Monitoramento

✅ Verifique os cards de estatísticas diariamente
✅ Acompanhe a taxa de aprovação vs rejeição
✅ Monitore saques automáticos vs manuais

---

## 🐛 Problemas Comuns

### "Você não tem permissão"

**Solução:** Verificar se o usuário é administrador (permission = 3)

### "Nenhum adquirente configurado"

**Solução:** Configurar pelo menos um adquirente de pagamento no sistema

### Lista vazia mas sei que há saques

**Solução:** Verificar os filtros aplicados (status, tipo, data)

### Modal não abre

**Solução:** Recarregar a página (F5) e tentar novamente

---

## 📞 Suporte

Em caso de dúvidas:

1. Consulte a documentação completa: `IMPLEMENTACAO_APROVACAO_SAQUES.md`
2. Verifique os logs do backend em `storage/logs`
3. Verifique o console do navegador (F12)

---

## ✨ Dicas

- Use o **filtro de pendentes** como padrão para focar no que precisa atenção
- Configure **atualização automática** a cada 60 segundos
- Use a **busca** para encontrar saques específicos rapidamente
- **Exporte** os dados regularmente para relatórios
- Aproveite os **atalhos de teclado** do navegador

---

**Data:** 11/11/2025
**Versão:** 1.0
**Status:** ✅ Pronto para uso
