# Sistema de Notificações Push HKPay

Sistema completo de notificações push implementado para o HKPay, similar ao BSPay, com logo personalizada e integração com relatórios de entradas/saídas.

## 🚀 Funcionalidades Implementadas

### ✅ Backend Laravel
- **Sistema de Push Notifications** com Expo Push API
- **Observers** para monitorar mudanças de status nas transações
- **Notificações automáticas** quando transações são aprovadas nos relatórios
- **API completa** para gerenciar tokens e notificações
- **Logs detalhados** para debugging

### ✅ App Mobile React Native
- **Componente NotificationCard** com logo HK personalizada
- **Tela de notificações** completa com paginação
- **Integração com Expo Notifications**
- **Interface moderna** com tema escuro
- **Logo HK** como ícone do app

## 📱 Como Funciona

### 1. Monitoramento Automático
O sistema monitora automaticamente as tabelas:
- `solicitacoes` (entradas/depósitos)
- `solicitacoes_cash_out` (saídas/saques)

Quando o status muda para `PAID_OUT` ou `COMPLETED`, uma notificação é enviada automaticamente.

### 2. Tipos de Notificação
- **Depósito**: Quando um depósito é aprovado
- **Saque**: Quando um saque é processado
- **Comissão**: Quando uma comissão é creditada
- **Transferência**: Para transferências entre usuários

### 3. Logo HK
- Logo personalizada nas notificações
- Ícone do app com design HK
- Tema escuro moderno
- Cores da marca (#00d4aa, #6c5ce7)

## 🛠️ Configuração

### Backend
1. **Migrações executadas**:
   ```bash
   php artisan migrate
   ```

2. **Observers registrados** no `AppServiceProvider`

3. **Rotas API** configuradas em `routes/api.php`

### App Mobile
1. **Dependências instaladas**:
   ```bash
   npm install expo-notifications expo-linear-gradient
   ```

2. **Configuração** no `app.json` atualizada

## 📋 Endpoints da API

### Notificações
- `POST /api/notifications/register-token` - Registrar token de push
- `GET /api/notifications` - Listar notificações
- `POST /api/notifications/{id}/read` - Marcar como lida
- `POST /api/notifications/mark-all-read` - Marcar todas como lidas
- `GET /api/notifications/stats` - Estatísticas
- `POST /api/notifications/deactivate-token` - Desativar token

## 🧪 Testando o Sistema

### Comando de Teste
```bash
php artisan notifications:test {user_id} --type=deposit --amount=100.00
```

### Tipos de teste disponíveis:
- `deposit` - Teste de depósito
- `withdraw` - Teste de saque  
- `commission` - Teste de comissão
- `transfer` - Teste de transferência

### Exemplo:
```bash
php artisan notifications:test usuario123 --type=deposit --amount=250.50
```

## 📊 Monitoramento

### Logs
- Todas as notificações são logadas em `storage/logs/laravel.log`
- Busque por "Observer:" para ver notificações enviadas
- Busque por "PushNotificationService" para detalhes do envio

### Banco de Dados
- Tabela `push_tokens` - Tokens dos dispositivos
- Tabela `notifications` - Histórico de notificações

## 🎨 Design das Notificações

### NotificationCard
- **Logo HK** com gradiente roxo/azul
- **Valor destacado** com cor baseada no tipo
- **Timestamp** formatado (agora, 5min, 2h, 3d)
- **Indicador** de não lida
- **Animações** suaves

### Cores por Tipo
- **Depósito**: Verde (#00d4aa)
- **Saque**: Vermelho (#ff6b6b)
- **Comissão**: Amarelo (#fdcb6e)
- **Transferência**: Azul (#74b9ff)

## 🔧 Troubleshooting

### Notificações não chegam
1. Verificar se o usuário tem tokens ativos:
   ```sql
   SELECT * FROM push_tokens WHERE user_id = 'usuario' AND is_active = 1;
   ```

2. Verificar logs de erro:
   ```bash
   tail -f storage/logs/laravel.log | grep -i notification
   ```

3. Testar com comando:
   ```bash
   php artisan notifications:test usuario123 --type=deposit
   ```

### App não recebe notificações
1. Verificar permissões no dispositivo
2. Verificar se o token foi registrado
3. Verificar conexão com internet
4. Testar em dispositivo físico (não simulador)

## 📈 Próximos Passos

- [ ] Implementar notificações de transferência entre usuários
- [ ] Adicionar notificações de limite de saldo
- [ ] Implementar notificações de manutenção
- [ ] Adicionar analytics de notificações
- [ ] Implementar notificações agendadas

## 🎯 Resultado Final

O sistema agora funciona exatamente como o BSPay:
- ✅ Notificações automáticas quando transações são aprovadas
- ✅ Logo HK personalizada em todas as notificações
- ✅ Interface moderna e intuitiva
- ✅ Integração completa com relatórios de entradas/saídas
- ✅ Sistema robusto e escalável

**Todas as notificações são enviadas automaticamente quando as transações ficam aprovadas nos relatórios do sistema HKPay!** 🚀
