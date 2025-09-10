<?php
/**
 * Instagram Insights - Handler para Interface Web
 * Processa requisições AJAX da interface de configuração
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Tratar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Capturar e log de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Função de log para debug
function logDebug($message, $data = null) {
    $logFile = __DIR__ . '/logs/web_setup_' . date('Y-m-d') . '.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}";
    if ($data !== null) {
        $logMessage .= " | Data: " . json_encode($data);
    }
    $logMessage .= PHP_EOL;
    
    file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
}

// Carregar a classe de setup (CORREÇÃO: arquivo correto)
require_once __DIR__ . '/instagram_setup_class.php';

class SetupHandler 
{
    private $setup;
    private $pdo;
    
    public function __construct() 
    {
        logDebug("Inicializando SetupHandler");
        
        try {
            $this->setup = new InstagramSetup();
            $this->pdo = $this->setup->pdo;
            logDebug("InstagramSetup inicializado com sucesso");
        } catch (Exception $e) {
            logDebug("Erro na inicialização", ['error' => $e->getMessage()]);
            $this->sendError('Erro na inicialização: ' . $e->getMessage());
        }
    }
    
    public function handleRequest() 
    {
        logDebug("Processando requisição", [
            'method' => $_SERVER['REQUEST_METHOD'],
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'not set'
        ]);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendError('Método não permitido');
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        logDebug("Input recebido", $input);
        
        if (!$input || !isset($input['action'])) {
            $this->sendError('Ação não especificada ou JSON inválido');
            return;
        }
        
        try {
            switch ($input['action']) {
                case 'setup':
                    logDebug("Executando ação: setup");
                    $this->handleSetup($input);
                    break;
                    
                case 'status':
                    logDebug("Executando ação: status");
                    $this->handleStatus();
                    break;
                    
                case 'test_collection':
                    logDebug("Executando ação: test_collection");
                    $this->handleTestCollection($input);
                    break;
                    
                default:
                    logDebug("Ação não reconhecida", ['action' => $input['action']]);
                    $this->sendError('Ação não reconhecida: ' . $input['action']);
            }
            
        } catch (Exception $e) {
            logDebug("Erro no processamento", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            $this->sendError('Erro no processamento: ' . $e->getMessage());
        }
    }
    
    private function handleSetup($input) 
    {
        logDebug("Iniciando handleSetup");
        
        if (!isset($input['userToken']) || empty($input['userToken'])) {
            logDebug("Token não fornecido");
            $this->sendError('Token de acesso é obrigatório');
            return;
        }
        
        $userToken = trim($input['userToken']);
        logDebug("Token recebido", ['token_length' => strlen($userToken)]);
        
        // Validar token
        logDebug("Validando token...");
        $tokenValidation = $this->setup->validateToken($userToken);
        if (!$tokenValidation) {
            logDebug("Token inválido");
            $this->sendError('Token inválido. Verifique se o token está correto e tem as permissões necessárias.');
            return;
        }
        
        logDebug("Token válido", ['user' => $tokenValidation['name'] ?? 'unknown']);
        
        // Obter token de longa duração
        logDebug("Obtendo token de longa duração...");
        $longToken = $this->setup->getLongLivedToken($userToken);
        if (!$longToken) {
            logDebug("Não foi possível obter token de longa duração, usando original");
            $longToken = $userToken; // Usar token original se não conseguir obter longa duração
        }
        
        // Descobrir contas Instagram
        logDebug("Descobrindo contas Instagram...");
        $accounts = $this->setup->discoverInstagramAccounts($longToken);
        logDebug("Contas encontradas", ['count' => count($accounts)]);
        
        if (empty($accounts)) {
            logDebug("Nenhuma conta encontrada");
            $this->sendError('Nenhuma conta Instagram Business encontrada. Certifique-se de que sua conta Instagram está conectada a uma página do Facebook.');
            return;
        }
        
        $configuredAccounts = [];
        $errors = [];
        
        // Configurar cada conta
        logDebug("Configurando contas...");
        foreach ($accounts as $account) {
            logDebug("Configurando conta", ['username' => $account['username']]);
            
            if ($this->setup->insertAccount($account, $longToken)) {
                // Testar coleta de dados
                $testResult = $this->setup->testDataCollection(
                    $account['instagram_user_id'], 
                    $longToken
                );
                
                $configuredAccounts[] = [
                    'username' => $account['username'],
                    'name' => $account['name'],
                    'followers_count' => $account['followers_count'],
                    'account_type' => ucfirst($account['account_type']),
                    'test_successful' => $testResult
                ];
                
                if (!$testResult) {
                    $errors[] = "Conta @{$account['username']}: configurada mas teste de coleta falhou";
                }
                
                logDebug("Conta configurada", ['username' => $account['username'], 'test_result' => $testResult]);
            } else {
                $error = "Falha ao configurar conta @{$account['username']}";
                $errors[] = $error;
                logDebug("Erro ao configurar conta", ['username' => $account['username']]);
            }
        }
        
        if (empty($configuredAccounts)) {
            logDebug("Nenhuma conta foi configurada");
            $this->sendError('Não foi possível configurar nenhuma conta. Verifique os logs para mais detalhes.');
            return;
        }
        
        // Executar primeira coleta
        logDebug("Executando primeira coleta...");
        $this->runFirstCollection();
        
        logDebug("Setup concluído com sucesso", [
            'configured_accounts' => count($configuredAccounts),
            'errors' => count($errors)
        ]);
        
        $this->sendSuccess([
            'message' => 'Configuração concluída com sucesso!',
            'accounts' => $configuredAccounts,
            'errors' => $errors,
            'total_configured' => count($configuredAccounts)
        ]);
    }
    
    private function handleStatus() 
    {
        logDebug("Obtendo status do sistema");
        
        try {
            // Buscar contas ativas
            $accounts = $this->getActiveAccounts();
            
            // Buscar estatísticas
            $stats = $this->getSystemStats();
            
            logDebug("Status obtido", ['accounts' => count($accounts), 'stats' => $stats]);
            
            $this->sendSuccess([
                'accounts' => $accounts,
                'stats' => $stats,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            logDebug("Erro ao obter status", ['error' => $e->getMessage()]);
            $this->sendError('Erro ao obter status: ' . $e->getMessage());
        }
    }
    
    private function handleTestCollection($input) 
    {
        logDebug("Executando teste de coleta");
        
        try {
            // Executar coleta de teste
            $output = [];
            $returnCode = 0;
            
            $command = 'php ' . __DIR__ . '/instagram_insights_cron.php 2>&1';
            exec($command, $output, $returnCode);
            
            logDebug("Teste de coleta executado", [
                'return_code' => $returnCode,
                'output_lines' => count($output)
            ]);
            
            $this->sendSuccess([
                'success' => $returnCode === 0,
                'output' => implode("\n", $output),
                'return_code' => $returnCode
            ]);
            
        } catch (Exception $e) {
            logDebug("Erro no teste de coleta", ['error' => $e->getMessage()]);
            $this->sendError('Erro ao executar coleta de teste: ' . $e->getMessage());
        }
    }
    
    private function getActiveAccounts() 
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                account_name,
                user_id,
                account_type,
                follower_count,
                last_collected,
                CASE 
                    WHEN last_collected IS NULL THEN '❌ Nunca coletado'
                    WHEN last_collected >= DATE_SUB(NOW(), INTERVAL 30 MINUTE) THEN '✅ Atual'
                    WHEN last_collected >= DATE_SUB(NOW(), INTERVAL 2 HOUR) THEN '⚠️ Atrasado'
                    ELSE '🚨 Muito atrasado'
                END as status
            FROM instagram_accounts 
            WHERE is_active = TRUE
            ORDER BY last_collected DESC
        ");
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    private function getSystemStats() 
    {
        // Dados coletados hoje
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as metricas_hoje,
                COUNT(DISTINCT user_id) as contas_com_dados
            FROM instagram_profile_insights 
            WHERE period_end >= CURDATE()
        ");
        $stmt->execute();
        $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Alertas ativos
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as alertas_ativos
            FROM instagram_alerts 
            WHERE is_processed = FALSE
        ");
        $stmt->execute();
        $alerts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Rate limits
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as calls_ultima_hora,
                COUNT(DISTINCT user_id) as contas_ativas
            FROM instagram_api_calls 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute();
        $rateLimits = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'metricas_hoje' => $todayStats['metricas_hoje'],
            'contas_com_dados' => $todayStats['contas_com_dados'],
            'alertas_ativos' => $alerts['alertas_ativos'],
            'api_calls_ultima_hora' => $rateLimits['calls_ultima_hora'],
            'contas_ativas' => $rateLimits['contas_ativas']
        ];
    }
    
    private function runFirstCollection() 
    {
        try {
            // Executar coleta em background para não travar a interface
            if (function_exists('exec')) {
                $command = 'php ' . __DIR__ . '/instagram_insights_cron.php > /dev/null 2>&1 &';
                exec($command);
                logDebug("Primeira coleta disparada em background");
            }
        } catch (Exception $e) {
            logDebug("Erro ao executar primeira coleta", ['error' => $e->getMessage()]);
            // Ignorar erros na primeira coleta, pois é opcional
        }
    }
    
    private function sendSuccess($data) 
    {
        logDebug("Enviando resposta de sucesso", $data);
        
        $response = array_merge(['success' => true], $data);
        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    private function sendError($message) 
    {
        logDebug("Enviando resposta de erro", ['message' => $message]);
        
        echo json_encode([
            'success' => false,
            'message' => $message
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Executar handler
try {
    logDebug("=== INICIANDO PROCESSAMENTO ===");
    
    $handler = new SetupHandler();
    $handler->handleRequest();
    
    logDebug("=== PROCESSAMENTO CONCLUÍDO ===");
    
} catch (Exception $e) {
    logDebug("Erro crítico no handler", ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    
    echo json_encode([
        'success' => false,
        'message' => 'Erro crítico: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>