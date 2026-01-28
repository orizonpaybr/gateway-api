#!/bin/bash

# ==============================================================================
# Script de Execução dos Testes k6
# ==============================================================================
#
# Uso:
#   ./k6/run-tests.sh [cenário] [opções]
#
# Cenários disponíveis:
#   smoke     - Verificação rápida do sistema (1-2 min)
#   load      - Teste de carga normal (~20 min)
#   stress    - Teste de stress (~30 min)
#   spike     - Teste de picos (~12 min)
#   journey   - Jornada do usuário (~10 min)
#   all       - Executa todos os cenários
#
# Opções:
#   --vus N         - Override número de VUs
#   --duration T    - Override duração (ex: 5m, 30s)
#   --env ENV       - Ambiente (local, staging, production)
#   --output FILE   - Exportar resultados para JSON
#   --influxdb URL  - Enviar métricas para InfluxDB
#   --debug         - Modo debug (logs detalhados)
#
# Exemplos:
#   ./k6/run-tests.sh smoke
#   ./k6/run-tests.sh load --vus 50 --duration 5m
#   ./k6/run-tests.sh stress --output results.json
#   ./k6/run-tests.sh all --env staging
#
# ==============================================================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Diretório do script
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Configurações padrão
BASE_URL="${K6_BASE_URL:-http://localhost:8000/api}"
VUS=""
DURATION=""
OUTPUT_FILE=""
INFLUXDB_URL=""
DEBUG="false"
ENV="local"

# Função para imprimir header
print_header() {
    echo ""
    echo -e "${CYAN}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC}           ${BLUE}Gateway API - Testes de Performance${NC}            ${CYAN}║${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# Função para verificar k6
check_k6() {
    if ! command -v k6 &> /dev/null; then
        echo -e "${RED}❌ k6 não está instalado!${NC}"
        echo ""
        echo "Instale o k6:"
        echo ""
        echo "  # Ubuntu/Debian"
        echo "  sudo gpg -k"
        echo "  sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg \\"
        echo "    --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69"
        echo "  echo \"deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main\" \\"
        echo "    | sudo tee /etc/apt/sources.list.d/k6.list"
        echo "  sudo apt-get update && sudo apt-get install k6"
        echo ""
        echo "  # macOS"
        echo "  brew install k6"
        echo ""
        exit 1
    fi
    echo -e "${GREEN}✓ k6 encontrado: $(k6 version | head -1)${NC}"
}

# Função para carregar variáveis de ambiente
load_env() {
    local env_file=""
    
    if [ -f "$SCRIPT_DIR/.env" ]; then
        env_file="$SCRIPT_DIR/.env"
        echo -e "${GREEN}✓ Carregando variáveis de $env_file${NC}"
    elif [ -f "$SCRIPT_DIR/.env.example" ]; then
        env_file="$SCRIPT_DIR/.env.example"
        echo -e "${YELLOW}⚠ Usando .env.example como fallback${NC}"
    fi
    
    if [ -n "$env_file" ]; then
        # Carrega variáveis linha por linha para lidar com caracteres especiais
        while IFS='=' read -r key value; do
            # Ignora linhas vazias e comentários
            [[ -z "$key" || "$key" =~ ^# ]] && continue
            # Remove espaços em branco
            key=$(echo "$key" | xargs)
            # Exporta a variável (preservando caracteres especiais no valor)
            export "$key=$value"
        done < "$env_file"
    fi
    
    # Atualiza BASE_URL se definido no .env
    BASE_URL="${K6_BASE_URL:-$BASE_URL}"
}

# Função para verificar API
check_api() {
    echo -e "${BLUE}Verificando conectividade com a API...${NC}"
    
    local api_root="${BASE_URL%/api}"
    
    if curl -s --connect-timeout 5 "$api_root" > /dev/null 2>&1; then
        echo -e "${GREEN}✓ API acessível em: $BASE_URL${NC}"
    else
        echo -e "${RED}❌ API não está acessível em: $BASE_URL${NC}"
        echo ""
        echo "Verifique se:"
        echo "  1. O servidor está rodando"
        echo "  2. A URL está correta"
        echo "  3. Não há firewall bloqueando"
        echo ""
        read -p "Deseja continuar mesmo assim? (s/N) " -n 1 -r
        echo
        if [[ ! $REPLY =~ ^[Ss]$ ]]; then
            exit 1
        fi
    fi
}

# Função para executar cenário
run_scenario() {
    local scenario=$1
    local script_path=""
    
    case $scenario in
        smoke)
            script_path="$SCRIPT_DIR/scenarios/smoke.js"
            ;;
        load)
            script_path="$SCRIPT_DIR/scenarios/load.js"
            ;;
        stress)
            script_path="$SCRIPT_DIR/scenarios/stress.js"
            ;;
        spike)
            script_path="$SCRIPT_DIR/scenarios/spike.js"
            ;;
        journey)
            script_path="$SCRIPT_DIR/scenarios/full-journey.js"
            ;;
        auth)
            script_path="$SCRIPT_DIR/tests/auth.test.js"
            ;;
        cashin)
            script_path="$SCRIPT_DIR/tests/cash-in.test.js"
            ;;
        cashout)
            script_path="$SCRIPT_DIR/tests/cash-out.test.js"
            ;;
        dashboard)
            script_path="$SCRIPT_DIR/tests/dashboard.test.js"
            ;;
        webhook)
            script_path="$SCRIPT_DIR/tests/webhook.test.js"
            ;;
        *)
            echo -e "${RED}❌ Cenário desconhecido: $scenario${NC}"
            show_help
            exit 1
            ;;
    esac
    
    if [ ! -f "$script_path" ]; then
        echo -e "${RED}❌ Script não encontrado: $script_path${NC}"
        exit 1
    fi
    
    echo ""
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${BLUE}  Executando: ${CYAN}$scenario${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
    
    # Monta comando k6 com todas as variáveis de ambiente necessárias
    local cmd="k6 run"
    cmd="$cmd -e BASE_URL=$BASE_URL"
    cmd="$cmd -e K6_DEBUG=$DEBUG"
    
    # Passa credenciais de usuário
    [ -n "$K6_TEST_USERNAME" ] && cmd="$cmd -e K6_TEST_USERNAME=$K6_TEST_USERNAME"
    [ -n "$K6_TEST_EMAIL" ] && cmd="$cmd -e K6_TEST_EMAIL=$K6_TEST_EMAIL"
    [ -n "$K6_TEST_PASSWORD" ] && cmd="$cmd -e K6_TEST_PASSWORD=$K6_TEST_PASSWORD"
    [ -n "$K6_TEST_2FA_SECRET" ] && cmd="$cmd -e K6_TEST_2FA_SECRET=$K6_TEST_2FA_SECRET"
    
    # Passa credenciais de API
    [ -n "$K6_API_TOKEN" ] && cmd="$cmd -e K6_API_TOKEN=$K6_API_TOKEN"
    [ -n "$K6_API_SECRET" ] && cmd="$cmd -e K6_API_SECRET=$K6_API_SECRET"
    
    # Outras configurações (não passa K6_VUS e K6_DURATION para não sobrescrever cenários)
    # Use --vus e --duration na linha de comando se quiser sobrescrever
    [ -n "$K6_THINK_TIME" ] && cmd="$cmd -e K6_THINK_TIME=$K6_THINK_TIME"
    
    if [ -n "$VUS" ]; then
        cmd="$cmd --vus $VUS"
    fi
    
    if [ -n "$DURATION" ]; then
        cmd="$cmd --duration $DURATION"
    fi
    
    if [ -n "$OUTPUT_FILE" ]; then
        local output_path="${OUTPUT_FILE%.*}_${scenario}.json"
        cmd="$cmd --out json=$output_path"
        echo -e "${BLUE}📊 Resultados serão salvos em: $output_path${NC}"
    fi
    
    if [ -n "$INFLUXDB_URL" ]; then
        cmd="$cmd --out influxdb=$INFLUXDB_URL"
        echo -e "${BLUE}📊 Métricas serão enviadas para: $INFLUXDB_URL${NC}"
    fi
    
    cmd="$cmd $script_path"
    
    echo -e "${YELLOW}Comando: $cmd${NC}"
    echo ""
    
    # Executa
    eval $cmd
    
    local exit_code=$?
    
    echo ""
    if [ $exit_code -eq 0 ]; then
        echo -e "${GREEN}✓ Cenário '$scenario' concluído com sucesso${NC}"
    else
        echo -e "${RED}✗ Cenário '$scenario' falhou com código: $exit_code${NC}"
    fi
    
    return $exit_code
}

# Função para executar todos os cenários
run_all() {
    local scenarios=("smoke" "load" "stress" "spike" "journey")
    local failed=0
    
    echo -e "${BLUE}Executando todos os cenários...${NC}"
    echo ""
    
    for scenario in "${scenarios[@]}"; do
        run_scenario "$scenario" || ((failed++))
        echo ""
        echo -e "${YELLOW}Aguardando 30s antes do próximo cenário...${NC}"
        sleep 30
    done
    
    echo ""
    echo -e "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "${CYAN}  RESUMO FINAL${NC}"
    echo -e "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
    
    if [ $failed -eq 0 ]; then
        echo -e "${GREEN}✓ Todos os ${#scenarios[@]} cenários passaram!${NC}"
    else
        echo -e "${RED}✗ $failed de ${#scenarios[@]} cenários falharam${NC}"
    fi
    
    return $failed
}

# Função para mostrar ajuda
show_help() {
    echo ""
    echo "Uso: $0 [cenário] [opções]"
    echo ""
    echo "Cenários disponíveis:"
    echo "  smoke      - Verificação rápida do sistema (1-2 min)"
    echo "  load       - Teste de carga normal (~20 min)"
    echo "  stress     - Teste de stress (~30 min)"
    echo "  spike      - Teste de picos (~12 min)"
    echo "  journey    - Jornada do usuário (~10 min)"
    echo "  all        - Executa todos os cenários"
    echo ""
    echo "Testes individuais:"
    echo "  auth       - Testes de autenticação"
    echo "  cashin     - Testes de Cash In (PIX)"
    echo "  cashout    - Testes de Cash Out (Saque)"
    echo "  dashboard  - Testes de consultas"
    echo "  webhook    - Testes de webhook"
    echo ""
    echo "Opções:"
    echo "  --vus N         - Override número de VUs"
    echo "  --duration T    - Override duração (ex: 5m, 30s)"
    echo "  --env ENV       - Ambiente (local, staging, production)"
    echo "  --output FILE   - Exportar resultados para JSON"
    echo "  --influxdb URL  - Enviar métricas para InfluxDB"
    echo "  --debug         - Modo debug (logs detalhados)"
    echo "  --help          - Mostra esta ajuda"
    echo ""
    echo "Exemplos:"
    echo "  $0 smoke"
    echo "  $0 load --vus 50 --duration 5m"
    echo "  $0 stress --output results.json"
    echo "  $0 all --env staging"
    echo ""
}

# ==============================================================================
# MAIN
# ==============================================================================

print_header

# Parse argumentos
SCENARIO=""
while [[ $# -gt 0 ]]; do
    case $1 in
        smoke|load|stress|spike|journey|all|auth|cashin|cashout|dashboard|webhook)
            SCENARIO=$1
            shift
            ;;
        --vus)
            VUS="$2"
            shift 2
            ;;
        --duration)
            DURATION="$2"
            shift 2
            ;;
        --env)
            ENV="$2"
            case $ENV in
                staging)
                    BASE_URL="https://staging-api.exemplo.com/api"
                    ;;
                production)
                    BASE_URL="https://api.exemplo.com/api"
                    ;;
            esac
            shift 2
            ;;
        --output)
            OUTPUT_FILE="$2"
            shift 2
            ;;
        --influxdb)
            INFLUXDB_URL="$2"
            shift 2
            ;;
        --debug)
            DEBUG="true"
            shift
            ;;
        --help|-h)
            show_help
            exit 0
            ;;
        *)
            echo -e "${RED}Opção desconhecida: $1${NC}"
            show_help
            exit 1
            ;;
    esac
done

# Verifica se cenário foi especificado
if [ -z "$SCENARIO" ]; then
    echo -e "${RED}❌ Nenhum cenário especificado${NC}"
    show_help
    exit 1
fi

# Verificações iniciais
check_k6
load_env
check_api

echo ""
echo -e "${BLUE}Configuração:${NC}"
echo "  Ambiente: $ENV"
echo "  Base URL: $BASE_URL"
[ -n "$VUS" ] && echo "  VUs Override: $VUS"
[ -n "$DURATION" ] && echo "  Duration Override: $DURATION"
[ -n "$OUTPUT_FILE" ] && echo "  Output: $OUTPUT_FILE"
[ "$DEBUG" = "true" ] && echo "  Debug: Ativado"
echo ""

# Executa
if [ "$SCENARIO" = "all" ]; then
    run_all
else
    run_scenario "$SCENARIO"
fi

exit $?
