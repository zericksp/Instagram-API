<?php
/**
 * Endpoints PHP para dados de seguidores
 * followers.php - Endpoint principal para dados de seguidores
 */

require_once 'config/database.php';
require_once 'classes/InstagramInsightsAPI.php';

class FollowersAPI {
    
    private $db;
    private $instagramAPI;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->instagramAPI = new InstagramInsightsAPI();
    }
    
    /**
     * followers.php - Dados gerais de seguidores
     */
    public function getFollowersData() {
        try {
            // Buscar dados atuais de seguidores
            $currentData = $this->instagramAPI->getAccountInsights('day', ['follower_count']);
            $followerCount = $currentData['follower_count'] ?? 0;
            
            // Buscar dados históricos do banco
            $historicalData = $this->getHistoricalFollowersData();
            
            // Calcular crescimento
            $growthData = $this->calculateGrowthMetrics($followerCount, $historicalData);
            
            return [
                'success' => true,
                'data' => array_merge([
                    'total_followers' => $followerCount,
                    'last_updated' => date('c')
                ], $growthData)
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'data' => $this->getDefaultFollowersData()
            ];
        }
    }
    
    /**
     * followers_growth.php - Dados de crescimento histórico
     */
    public function getFollowersGrowth($days = 30) {
        try {
            $stmt = $this->db->prepare("
                SELECT 
                    DATE(created_at) as date,
                    follower_count,
                    (follower_count - LAG(follower_count, 1) OVER (ORDER BY created_at)) as growth
                FROM followers_history 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY created_at ASC
            ");
            
            $stmt->execute([$days]);
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Se não há dados históricos, gerar dados simulados
            if (empty($results)) {
                $results = $this->generateSimulatedGrowthData($days);
            }
            
            return [
                'success' => true,
                'growth_data' => $results
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'growth_data' => $this->generateSimulatedGrowthData($days)
            ];
        }
    }
    
    /**
     * Busca dados históricos de seguidores
     */
    private function getHistoricalFollowersData() {
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
            error_log("Erro ao buscar dados históricos: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Calcula métricas de crescimento
     */
    private function calculateGrowthMetrics($currentFollowers, $historicalData) {
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $lastWeek = date('Y-m-d', strtotime('-7 days'));
        $lastMonth = date('Y-m-d', strtotime('-30 days'));
        
        // Valores padrão
        $metrics = [
            'new_followers_today' => 0,
            'new_followers_week' => 0,
            'new_followers_month' => 0,
            'unfollows_today' => 0,
            'growth_rate' => 0.0
        ];
        
        if (!empty($historicalData)) {
            // Crescimento diário
            $yesterdayData = array_filter($historicalData, function($item) use ($yesterday) {
                return substr($item['date'], 0, 10) === $yesterday;
            });
            
            if (!empty($yesterdayData)) {
                $yesterdayFollowers = reset($yesterdayData)['follower_count'];
                $metrics['new_followers_today'] = max(0, $currentFollowers - $yesterdayFollowers);
            }
            
            // Crescimento semanal
            $weekData = array_filter($historicalData, function($item) use ($lastWeek) {
                return substr($item['date'], 0, 10) === $lastWeek;
            });
            
            if (!empty($weekData)) {
                $weekFollowers = reset($weekData)['follower_count'];
                $metrics['new_followers_week'] = max(0, $currentFollowers - $weekFollowers);
            }
            
            // Crescimento mensal
            $monthData = array_filter($historicalData, function($item) use ($lastMonth) {
                return substr($item['date'], 0, 10) >= $lastMonth;
            });
            
            if (!empty($monthData)) {
                $oldestRecord = end($monthData);
                $monthFollowers = $oldestRecord['follower_count'];
                $metrics['new_followers_month'] = max(0, $currentFollowers - $monthFollowers);
                
                // Taxa de crescimento mensal
                if ($monthFollowers > 0) {
                    $metrics['growth_rate'] = (($currentFollowers - $monthFollowers) / $monthFollowers) * 100;
                }
            }
        }
        
        return $metrics;
    }
    
    /**
     * Salva dados atuais de seguidores no histórico
     */
    public function saveFollowersHistory($followerCount) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO followers_history (follower_count, created_at) 
                VALUES (?, NOW())
                ON DUPLICATE KEY UPDATE 
                follower_count = VALUES(follower_count)
            ");
            
            return $stmt->execute([$followerCount]);
            
        } catch (Exception $e) {
            error_log("Erro ao salvar histórico de seguidores: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Gera dados simulados para demonstração
     */
    private function generateSimulatedGrowthData($days) {
        $data = [];
        $baseFollowers = 1000;
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            $growth = rand(-5, 15); // Crescimento aleatório entre -5 e +15
            $baseFollowers += $growth;
            
            $data[] = [
                'date' => $date,
                'followers' => $baseFollowers,
                'growth' => $growth
            ];
        }
        
        return $data;
    }
    
    /**
     * Dados padrão para fallback
     */
    private function getDefaultFollowersData() {
        return [
            'total_followers' => 0,
            'new_followers_today' => 0,
            'new_followers_week' => 0,
            'new_followers_month' => 0,
            'unfollows_today' => 0,
            'growth_rate' => 0.0
        ];
    }
    
    /**
     * Handler principal para requisições
     */
    public function handleRequest() {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
        
        try {
            $endpoint = $_GET['endpoint'] ?? '';
            
            switch ($endpoint) {
                case 'growth':
                    $days = intval($_GET['days'] ?? 30);
                    $result = $this->getFollowersGrowth($days);
                    break;
                    
                default:
                    $result = $this->getFollowersData();
                    break;
            }
            
            echo json_encode($result);
            
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }
}

// Executar se chamado diretamente
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    $api = new FollowersAPI();
    $api->handleRequest();
}

// ==========================================
// Script SQL para criar tabela necessária
// ==========================================

/*
CREATE TABLE IF NOT EXISTS followers_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    follower_count INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_daily_record (DATE(created_at))
);

-- Índices para performance
CREATE INDEX idx_created_at ON followers_history(created_at);
CREATE INDEX idx_date ON followers_history(DATE(created_at));

-- Job cron para coletar dados diários (adicionar ao crontab)
-- 0 9 * * * /usr/bin/php /caminho/para/projeto/backend/cron/collect_followers_data.php

*/

?>