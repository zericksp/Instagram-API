<?php
/**
 * followers.php - Endpoint para dados de seguidores
 * URL: /api/followers.php
 */

// Headers CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/InstagramInsightsAPI.php';

class FollowersEndpoint {
    
    private $db;
    private $instagramAPI;
    
    public function __construct() {
        try {
            $this->db = Database::getInstance();
            $this->instagramAPI = new InstagramInsightsAPI();
        } catch (Exception $e) {
            $this->sendError("Erro de inicialização: " . $e->getMessage(), 500);
        }
    }
    
    public function handleRequest() {
        try {
            // Buscar dados atuais de seguidores da API do Instagram
            $accountData = $this->instagramAPI->getAccountInsights('day', ['follower_count']);
            $currentFollowers = $accountData['follower_count'] ?? 0;
            
            // Buscar dados históricos do banco
            $historicalData = $this->getHistoricalData();
            
            // Calcular métricas de crescimento
            $growthMetrics = $this->calculateGrowthMetrics($currentFollowers, $historicalData);
            
            // Salvar dados atuais no histórico (se não existir hoje)
            $this->saveCurrentData($currentFollowers);
            
            $this->sendSuccess([
                'follower_count' => $currentFollowers,
                'new_followers_today' => $growthMetrics['new_followers_today'],
                'new_followers_week' => $growthMetrics['new_followers_week'],
                'new_followers_month' => $growthMetrics['new_followers_month'],
                'unfollows_today' => $growthMetrics['unfollows_today'],
                'growth_rate' => $growthMetrics['growth_rate'],
                'last_updated' => date('c')
            ]);
            
        } catch (Exception $e) {
            // Em caso de erro, retornar dados do banco ou padrão
            $this->sendSuccess($this->getFallbackData());
        }
    }
    
    private function getHistoricalData() {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    follower_count,
                    DATE(created_at) as date,
                    created_at
                FROM followers_history 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY created_at DESC
                LIMIT 30
            ");
            
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    private function calculateGrowthMetrics($currentFollowers, $historicalData) {
        $metrics = [
            'new_followers_today' => 0,
            'new_followers_week' => 0,
            'new_followers_month' => 0,
            'unfollows_today' => 0,
            'growth_rate' => 0.0
        ];
        
        if (empty($historicalData)) {
            return $metrics;
        }
        
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $lastWeek = date('Y-m-d', strtotime('-7 days'));
        $lastMonth = date('Y-m-d', strtotime('-30 days'));
        
        // Crescimento diário
        foreach ($historicalData as $record) {
            if ($record['date'] === $yesterday) {
                $yesterdayFollowers = $record['follower_count'];
                $dailyGrowth = $currentFollowers - $yesterdayFollowers;
                $metrics['new_followers_today'] = max(0, $dailyGrowth);
                $metrics['unfollows_today'] = max(0, -$dailyGrowth);
                break;
            }
        }
        
        // Crescimento semanal
        foreach ($historicalData as $record) {
            if ($record['date'] >= $lastWeek && $record['date'] <= date('Y-m-d', strtotime('-6 days'))) {
                $weekFollowers = $record['follower_count'];
                $metrics['new_followers_week'] = max(0, $currentFollowers - $weekFollowers);
                break;
            }
        }
        
        // Crescimento mensal e taxa
        if (!empty($historicalData)) {
            $oldestRecord = end($historicalData);
            $monthFollowers = $oldestRecord['follower_count'];
            $metrics['new_followers_month'] = max(0, $currentFollowers - $monthFollowers);
            
            if ($monthFollowers > 0) {
                $metrics['growth_rate'] = (($currentFollowers - $monthFollowers) / $monthFollowers) * 100;
            }
        }
        
        return $metrics;
    }
    
    private function saveCurrentData($followerCount) {
        try {
            $today = date('Y-m-d');
            
            // Verificar se já existe registro para hoje
            $stmt = $this->db->prepare("
                SELECT id FROM followers_history 
                WHERE DATE(created_at) = ?
            ");
            $stmt->execute([$today]);
            
            if ($stmt->rowCount() === 0) {
                // Criar novo registro apenas se não existir
                $stmt = $this->db->prepare("
                    INSERT INTO followers_history (follower_count, created_at) 
                    VALUES (?, NOW())
                ");
                $stmt->execute([$followerCount]);
            }
            
        } catch (Exception $e) {
            // Log do erro, mas não quebrar a aplicação
            error_log("Erro ao salvar dados de seguidores: " . $e->getMessage());
        }
    }
    
    private function getFallbackData() {
        try {
            // Tentar buscar dados mais recentes do banco
            $stmt = $this->db->prepare("
                SELECT follower_count 
                FROM followers_history 
                ORDER BY created_at DESC 
                LIMIT 1
            ");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $followerCount = $result ? $result['follower_count'] : 0;
            
            return [
                'follower_count' => $followerCount,
                'new_followers_today' => 0,
                'new_followers_week' => 0,
                'new_followers_month' => 0,
                'unfollows_today' => 0,
                'growth_rate' => 0.0,
                'last_updated' => date('c'),
                'source' => 'database_fallback'
            ];
            
        } catch (Exception $e) {
            return [
                'follower_count' => 0,
                'new_followers_today' => 0,
                'new_followers_week' => 0,
                'new_followers_month' => 0,
                'unfollows_today' => 0,
                'growth_rate' => 0.0,
                'last_updated' => date('c'),
                'source' => 'default_fallback'
            ];
        }
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
}

// Executar endpoint
try {
    $endpoint = new FollowersEndpoint();
    $endpoint->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro fatal: ' . $e->getMessage()
    ]);
}

?>

