# Testes de Carga e Performance - Gateway API

Suite completa de testes de carga usando [k6](https://k6.io/) para validar a performance e estabilidade da aplicação antes de ir para produção.

## Requisitos

### Instalar k6

```bash
# Ubuntu/Debian
sudo gpg -k
sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
sudo apt-get update
sudo apt-get install k6

# macOS
brew install k6

# Windows (chocolatey)
choco install k6

# Docker
docker pull grafana/k6
```

## Estrutura do Projeto

```
k6/
├── config/
│   ├── options.js      # Configurações de cenários (VUs, duração, etc)
│   └── thresholds.js   # Limites de performance aceitáveis
├── helpers/
│   ├── auth.js         # Funções de autenticação
│   ├── utils.js        # Utilitários gerais
│   └── data.js         # Dados de teste (usuários, payloads)
├── tests/
│   ├── auth.test.js    # Testes de autenticação
│   ├── cash-in.test.js # Testes de Cash In (PIX)
│   ├── cash-out.test.js# Testes de Cash Out (Saque)
│   ├── dashboard.test.js # Testes de consultas
│   └── webhook.test.js # Testes de webhook
├── scenarios/
│   ├── smoke.js        # Smoke test (verificação básica)
│   ├── load.js         # Load test (carga normal)
│   ├── stress.js       # Stress test (limite do sistema)
│   ├── spike.js        # Spike test (picos de tráfego)
│   └── full-journey.js # Jornada completa do usuário
├── .env.example        # Variáveis de ambiente
├── run-tests.sh        # Script para executar testes
└── README.md           # Esta documentação
```

## Configuração

### 1. Copiar variáveis de ambiente

```bash
cp k6/.env.example k6/.env
```

### 2. Configurar variáveis

Edite o arquivo `k6/.env`:

```bash
# URL base da API (SEM barra no final)
K6_BASE_URL=http://localhost:8000/api

# Credenciais de teste (usuário existente no sistema)
K6_TEST_EMAIL=teste@exemplo.com
K6_TEST_PASSWORD=senha123
K6_TEST_2FA_SECRET=  # Opcional, se 2FA estiver habilitado

# Credenciais de integração (token/secret para APIs externas)
K6_API_TOKEN=seu_token_aqui
K6_API_SECRET=seu_secret_aqui

# Configurações de carga
K6_VUS=10              # Usuários virtuais padrão
K6_DURATION=30s        # Duração padrão dos testes
```

### 3. Criar usuário de teste

Certifique-se de ter um usuário de teste no sistema:

```bash
php artisan tinker
>>> App\Models\User::create([
    'name' => 'Usuário de Teste k6',
    'email' => 'k6test@exemplo.com',
    'password' => bcrypt('senha123'),
    'cpf' => '12345678901',
    'phone' => '11999999999',
    'status' => 'active'
]);
```

## Executando os Testes

### Usando o script auxiliar

```bash
# Dar permissão de execução
chmod +x k6/run-tests.sh

# Smoke test (verificação rápida)
./k6/run-tests.sh smoke

# Load test (carga normal)
./k6/run-tests.sh load

# Stress test (encontrar limite)
./k6/run-tests.sh stress

# Spike test (picos de tráfego)
./k6/run-tests.sh spike

# Jornada completa do usuário
./k6/run-tests.sh journey

# Executar todos os testes
./k6/run-tests.sh all
```

### Execução manual

```bash
# Smoke test básico
k6 run k6/scenarios/smoke.js

# Com variáveis de ambiente
k6 run -e BASE_URL=http://localhost:8000/api k6/scenarios/smoke.js

# Com mais usuários virtuais
k6 run --vus 50 --duration 2m k6/scenarios/load.js

# Exportar resultados para JSON
k6 run --out json=results.json k6/scenarios/load.js

# Exportar métricas em tempo real para InfluxDB
k6 run --out influxdb=http://localhost:8086/k6 k6/scenarios/load.js
```

### Usando Docker

```bash
docker run --rm -i \
  -v $(pwd)/k6:/scripts \
  -e BASE_URL=http://host.docker.internal:8000/api \
  grafana/k6 run /scripts/scenarios/smoke.js
```

## Cenários de Teste

### 1. Smoke Test (`smoke.js`)
- **Objetivo**: Verificar se o sistema está funcionando
- **VUs**: 1-5
- **Duração**: 1 minuto
- **Uso**: Executar após cada deploy

### 2. Load Test (`load.js`)
- **Objetivo**: Validar performance sob carga normal
- **VUs**: 50-100 (ramp-up gradual)
- **Duração**: 10 minutos
- **Uso**: Validação de capacidade esperada

### 3. Stress Test (`stress.js`)
- **Objetivo**: Encontrar o ponto de ruptura
- **VUs**: 100 → 500 (incremento gradual)
- **Duração**: 20 minutos
- **Uso**: Identificar limites do sistema

### 4. Spike Test (`spike.js`)
- **Objetivo**: Testar picos repentinos
- **VUs**: 10 → 200 → 10 (pico instantâneo)
- **Duração**: 5 minutos
- **Uso**: Simular eventos virais ou campanhas

### 5. Full Journey (`full-journey.js`)
- **Objetivo**: Simular jornada real do usuário
- **Fluxo**: Login → Dashboard → Gerar PIX → Verificar Status
- **Uso**: Teste end-to-end realista

## Métricas e Thresholds

### Métricas Coletadas

| Métrica | Descrição |
|---------|-----------|
| `http_req_duration` | Tempo de resposta das requisições |
| `http_req_failed` | Taxa de falhas |
| `http_reqs` | Total de requisições por segundo |
| `vus` | Usuários virtuais ativos |
| `iterations` | Iterações completadas |

### Thresholds Recomendados (Produção)

```javascript
thresholds: {
  // 95% das requisições devem responder em < 500ms
  'http_req_duration': ['p(95)<500'],
  
  // 99% das requisições devem responder em < 1000ms
  'http_req_duration': ['p(99)<1000'],
  
  // Taxa de erro < 1%
  'http_req_failed': ['rate<0.01'],
  
  // Mínimo de 100 req/s
  'http_reqs': ['rate>100'],
}
```

### Thresholds por Endpoint

| Endpoint | P95 Esperado | P99 Esperado |
|----------|--------------|--------------|
| `POST /auth/login` | < 300ms | < 500ms |
| `POST /auth/verify-2fa` | < 200ms | < 400ms |
| `GET /balance` | < 100ms | < 200ms |
| `POST /wallet/deposit/payment` | < 1000ms | < 2000ms |
| `POST /pixout` | < 1500ms | < 3000ms |
| `POST /treeal/webhook` | < 200ms | < 500ms |

## Interpretando Resultados

### Saída típica do k6

```
          /\      |‾‾| /‾‾/   /‾‾/   
     /\  /  \     |  |/  /   /  /    
    /  \/    \    |     (   /   ‾‾\  
   /          \   |  |\  \ |  (‾)  | 
  / __________ \  |__| \__\ \_____/  

     execution: local
        script: k6/scenarios/load.js

scenarios: (100.00%) 1 scenario, 100 max VUs, 10m30s max duration
           * default: Up to 100 looping VUs for 10m0s

running (10m00.0s), 000/100 VUs, 15000 complete and 0 interrupted iterations
default ✓ [======================================] 100 VUs  10m0s

     ✓ status is 200
     ✓ response time OK

     checks.........................: 100.00% ✓ 30000      ✗ 0
     data_received..................: 45 MB   75 kB/s
     data_sent......................: 12 MB   20 kB/s
     http_req_blocked...............: avg=1.2ms   min=0s     med=0s     max=150ms  p(90)=0s     p(95)=0s
     http_req_duration..............: avg=120ms   min=15ms   med=95ms   max=2.5s   p(90)=200ms  p(95)=350ms
     http_req_failed................: 0.05%   ✓ 15         ✗ 29985
     http_reqs......................: 30000   50/s
     iteration_duration.............: avg=1.2s    min=1s     med=1.1s   max=3.5s   p(90)=1.5s   p(95)=1.8s
     iterations.....................: 15000   25/s
     vus............................: 100     min=1        max=100
     vus_max........................: 100     min=100      max=100
```

### O que observar

1. **`http_req_duration` p(95)**: Deve estar dentro do threshold
2. **`http_req_failed`**: Deve ser < 1% para produção
3. **`http_reqs`**: Taxa de requisições por segundo
4. **`checks`**: Todas as verificações devem passar (100%)

### Sinais de problema

- ⚠️ P95 > threshold definido
- ⚠️ Taxa de erro > 1%
- ⚠️ Tempo de resposta crescendo com mais VUs
- ⚠️ Checks falhando

## Monitoramento Avançado

### Integração com Grafana + InfluxDB

1. Subir InfluxDB e Grafana:

```bash
docker-compose -f k6/docker-compose.monitoring.yml up -d
```

2. Executar k6 com output para InfluxDB:

```bash
k6 run --out influxdb=http://localhost:8086/k6 k6/scenarios/load.js
```

3. Acessar Grafana em `http://localhost:3000`
4. Importar dashboard k6 (ID: 2587)

### Integração com Datadog

```bash
K6_DATADOG_API_KEY=<sua_api_key> k6 run --out datadog k6/scenarios/load.js
```

## Boas Práticas

### Antes dos Testes

1. ✅ Ambiente isolado (não testar em produção!)
2. ✅ Banco de dados populado com dados realistas
3. ✅ Cache aquecido (rodar smoke test antes)
4. ✅ Monitoramento ativo (CPU, memória, I/O)
5. ✅ Logs configurados para capturar erros

### Durante os Testes

1. 📊 Monitorar recursos do servidor
2. 📊 Observar logs de erro
3. 📊 Verificar conexões de banco de dados
4. 📊 Monitorar filas (Redis/Queue)

### Após os Testes

1. 📝 Documentar resultados
2. 📝 Comparar com baseline
3. 📝 Identificar gargalos
4. 📝 Planejar otimizações

## Troubleshooting

### Erro: "connection refused"

```bash
# Verificar se a API está rodando
curl http://localhost:8000/api/health

# Verificar se o k6 consegue acessar
k6 run -e BASE_URL=http://localhost:8000/api k6/scenarios/smoke.js
```

### Erro: "too many open files"

```bash
# Aumentar limite de arquivos abertos
ulimit -n 65535
```

### Resultados inconsistentes

1. Desabilitar rate limiting durante testes
2. Usar ambiente dedicado
3. Garantir que não há outros processos consumindo recursos

## Próximos Passos

1. **Baseline**: Executar load test e documentar métricas atuais
2. **Otimização**: Identificar endpoints lentos e otimizar
3. **CI/CD**: Integrar smoke test no pipeline de deploy
4. **Alertas**: Configurar alertas para degradação de performance
