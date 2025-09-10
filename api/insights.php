<?php
/**
 * insights.php - Endpoint principal para Instagram Insights
 * URL: /api/insights.php?type=account&metrics=accounts_reached,accounts_engaged
 */

// Headers CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Responder a preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Incluir dependências
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/InstagramInsightsAPI.php';

class InsightsEndpoint {
    
    private $instagramAPI;
    
    public function __construct() {
        try {
            $this->instagramAPI = new InstagramInsightsAPI();
        } catch (Exception $e) {
            $this->sendError("Erro de inicialização: " . $e->getMessage(), 500);
        }
    }
    
    public function handleRequest() {
        try {
            $type = $_GET['type'] ?? '';
            $metrics = $_GET['metrics'] ?? '';
            $period = $_GET['period'] ?? 'day';
            $limit = intval($_GET['limit'] ?? 25);
            
            // Log da requisição
            $this->logRequest($type, $metrics);
            
            switch ($type) {
                case 'account':
                    $this->handleAccountInsights($metrics, $period);
                    break;
                    
                case 'posts':
                    $this->handlePostsInsights($metrics, $limit);
                    break;
                    
                case 'stories':
                    $this->handleStoriesInsights($limit);
                    break;
                    
                case 'audience':
                    $this->handleAudienceInsights();
                    break;
                    
                default:
                    $this->sendError("Tipo de insight não especificado ou inválido. Use: account, posts, stories, audience", 400);
            }
            
        } catch (Exception $e) {
            $this->sendError("Erro no processamento: " . $e->getMessage(), 500);
        }
    }
    
    private function handleAccountInsights($metrics, $period) {
        try {
            $customMetrics = $metrics ? explode(',', $metrics) : null;
            $data = $this->instagramAPI->getAccountInsights($period, $customMetrics);
            
            $this->sendSuccess([
                'type' => 'account',
                'insights' => $this->formatAccountData($data),
                'period' => $period,
                'timestamp' => date('c')
            ]);
            
        } catch (Exception $e) {
            $this->sendError("Erro ao buscar insights da conta: " . $e->getMessage(), 500);
        }
    }
    
    private function handlePostsInsights($metrics, $limit) {
        try {
            $customMetrics = $metrics ? explode(',', $metrics) : null;
            $data = $this->instagramAPI->getPostsInsights($limit, $customMetrics);
            
            $this->sendSuccess([
                'type' => 'posts',
                'posts' => $data,
                'count' => count($data),
                'limit' => $limit,
                'timestamp' => date('c')
            ]);
            
        } catch (Exception $e) {
            $this->sendError("Erro ao buscar insights dos posts: " . $e->getMessage(), 500);
        }
    }
    
    private function handleStoriesInsights($limit) {
        try {
            $data = $this->instagramAPI->getStoriesInsights($limit);
            
            $this->sendSuccess([
                'type' => 'stories',
                'stories' => $data,
                'count' => count($data),
                'limit' => $limit,
                'timestamp' => date('c')
            ]);
            
        } catch (Exception $e) {
            $this->sendError("Erro ao buscar insights das stories: " . $e->getMessage(), 500);
        }
    }
    
    private function handleAudienceInsights() {
        try {
            // Buscar dados demográficos
            $audienceData = $this->instagramAPI->getAccountInsights('day', [
                'audience_city',
                'audience_country', 
                'audience_gender_age'
            ]);
            
            $this->sendSuccess([
                'type' => 'audience',
                'insights' => $this->formatAudienceData($audienceData),
                'timestamp' => date('c')
            ]);
            
        } catch (Exception $e) {
            $this->sendError("Erro ao buscar dados da audiência: " . $e->getMessage(), 500);
        }
    }
    
    private function formatAccountData($data) {
        // Formatar dados da conta para o formato esperado pelo Flutter
        $formatted = [];
        
        foreach ($data as $metric => $value) {
            $formatted[] = [
                'name' => $metric,
                'values' => [
                    [
                        'value' => $value,
                        'end_time' => date('c')
                    ]
                ]
            ];
        }
        
        return $formatted;
    }
    
    private function formatAudienceData($data) {
        // Formatar dados demográficos
        $formatted = [];
        
        $demographicMetrics = ['audience_city', 'audience_country', 'audience_gender_age'];
        
        foreach ($demographicMetrics as $metric) {
            if (isset($data[$metric])) {
                $formatted[] = [
                    'name' => $metric,
                    'values' => [
                        [
                            'value' => $data[$metric],
                            'end_time' => date('c')
                        ]
                    ]
                ];
            }
        }
        
        return $formatted;
    }
    
    private function sendSuccess($data) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        exit;
    }
    
    private function sendError($message, $code = 400) {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ]);
        exit;
    }
    
    private function logRequest($type, $metrics) {
        $logFile = __DIR__ . '/logs/' . date('Y-m-d') . '_api_requests.log';
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        $logMessage = "[{$timestamp}] IP: {$ip} | Type: {$type} | Metrics: {$metrics} | UA: {$userAgent}" . PHP_EOL;
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// Verificar se as dependências existem
if (!file_exists(__DIR__ . '/config/database.php')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Arquivo de configuração do banco não encontrado'
    ]);
    exit;
}

if (!file_exists(__DIR__ . '/classes/InstagramInsightsAPI.php')) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Classe InstagramInsightsAPI não encontrada'
    ]);
    exit;
}

// Executar endpoint
try {
    $endpoint = new InsightsEndpoint();
    $endpoint->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro fatal: ' . $e->getMessage()
    ]);
}

?>