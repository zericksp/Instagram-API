<?php
/**
 * Instagram Insights - Handler para Interface Web
 * Processa requisições da interface de configuração
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Tratar preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Carregar configurações
require_once __DIR__ . '/instagram_setup_script.php';

class SetupHandler 
{
    private $setup;
    
    public function __construct() 
    {
        try {
            $this->setup = new InstagramSetup();
        } catch (Exception $e) {
            $this->sendError('Erro na inicialização: ' . $e->getMessage());
        }
    }
    
    public function handleRequest() 
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendError('Método não permitido');
            return;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input || !isset($input['action'])) {
            $this->sendError('Ação não especificada');
            return;
        }
        
        try {
            switch ($input['action']) {
                case 'setup':
                    $this->handleSetup($input);
                    break;
                    
                case 'status':
                    $this->handleStatus();
                    break;
                    
                case 'test_collection':
                    $this->handleTestCollection($input);
                    break;
                    
                default:
                    $this->sendError('Ação não reconhecida');
            }
            
        } catch (Exception $e) {
            $this->sendError('Erro no processamento: ' . $e->getMessage());
        }
    }
    
    private function handleSetup($input) 
    {
        if (!isset($input['userToken']) || empty($input['userToken'])) {
            $this->sendError('Token de acesso é obrigatório');
            return;
        }
        
        $userToken = trim($input['userToken']);
        
        // Validar token
        $tokenValidation = $this->setup->validateToken($userToken);
        if (!$tokenValidation) {
            $this->sendError('Token inválido. Verifique se o token está correto e tem as permissões necessárias.');
            return;
        }
        
        // Obter token de longa duração
        $longToken = $this->setup->getLongLivedToken($userToken);
        if (!$longToken) {
            $longToken = $userToken; // Usar token original se não conseguir obter longa duração
        }
        
        // Descobrir contas Instagram
        $accounts = $this->setup->discoverInstagramAccounts($longToken);
        
        if (empty($accounts)) {
            $this->sendError('Nenhuma conta Instagram Business encontrada. Certifique-se de que sua conta Instagram está conectada a uma página do Facebook.');
            return;
        }
        
        $configuredAccounts = [];
        $errors = [];
        
        // Configurar cada conta
        foreach ($accounts as $account) {
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
            } else {
                $errors[] = "Falha ao configurar conta @{$account['username']}";
            }
        }
        
        if (empty($configuredAccounts)) {
            $this->sendError('Não foi possível configurar nenhuma conta. Verifique os logs para mais detalhes.');
            return;
        }
        
        // Executar primeira coleta
        $this->runFirstCollection();
        
        $this->sendSuccess([
            'message' => 'Configuração concluída com sucesso!',
            'accounts' => $configuredAccounts,
            'errors' => $errors,
            'total_configured' => count($configuredAccounts)
        ]);
    }
    
    private function handleStatus() 
    {
        try {
            // Buscar contas ativas
            $accounts = $this->getActiveAccounts();
            
            // Buscar estatísticas
            $stats = $this->getSystemStats();
            
            $this->sendSuccess([
                'accounts' => $accounts,
                'stats' => $stats,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Erro ao obter status: ' . $e->getMessage());
        }
    }
    
    private function handleTestCollection($input) 
    {
        if (!isset($input['userId'])) {
            $this->sendError('ID do usuário é obrigatório');
            return;
        }
        
        try {
            // Executar coleta de teste
            $output = [];
            $returnCode = 0;
            
            exec('php ' . __DIR__ . '/instagram_insights_cron.php 2>&1', $output, $returnCode);
            
            $this->sendSuccess([
                'success' => $returnCode === 0,
                'output' => implode("\n", $output),
                'return_code' => $returnCode
            ]);
            
        } catch (Exception $e) {
            $this->sendError('Erro ao executar coleta de teste: ' . $e->getMessage());
        }
    }
    
    private function getActiveAccounts() 
    {
        $pdo = $this->setup->pdo ?? $this->getDatabaseConnection();
        
        $stmt = $pdo->prepare("
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
        $pdo = $this->setup->pdo ?? $this->getDatabaseConnection();
        
        // Dados coletados hoje
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as metricas_hoje,
                COUNT(DISTINCT user_id) as contas_com_dados
            FROM instagram_profile_insights 
            WHERE period_end >= CURDATE()
        ");
        $stmt->execute();
        $todayStats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Alertas ativos
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as alertas_ativos
            FROM instagram_alerts 
            WHERE is_processed = FALSE
        ");
        $stmt->execute();
        $alerts = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Rate limits
        $stmt = $pdo->prepare("
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
    
    private function getDatabaseConnection() 
    {
        $dbConfig = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'database' => $_ENV['DB_NAME'] ?? 'instagram_insights',
            'username' => $_ENV['DB_USER'] ?? 'root',
            'password' => $_ENV['DB_PASS'] ?? ''
        ];
        
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
        return new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    }
    
    private function runFirstCollection() 
    {
        try {
            // Executar coleta em background para não travar a interface
            if (function_exists('exec')) {
                exec('php ' . __DIR__ . '/instagram_insights_cron.php > /dev/null 2>&1 &');
            }
        } catch (Exception $e) {
            // Ignorar erros na primeira coleta, pois é opcional
        }
    }
    
    private function sendSuccess($data) 
    {
        echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    private function sendError($message) 
    {
        echo json_encode([
            'success' => false,
            'message' => $message
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Executar handler
try {
    $handler = new SetupHandler();
    $handler->handleRequest();
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Erro crítico: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>