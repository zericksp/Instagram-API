<?php
/**
 * cron/collect_followers_data.php
 * Script para coletar dados de seguidores automaticamente
 * Executar diariamente via crontab: 0 9 * * * /usr/bin/php /caminho/collect_followers_data.php
 */

require_once '../config/database.php';
require_once '../classes/InstagramInsightsAPI.php';

class FollowersDataCollector {

    private static $instance = null;
    private $db;
    private $instagramAPI;
    private $logFile;
    private $accessToken;
    private $instagramAccountId;
    private $apiVersion = 'v23.0';
    private $baseUrl = 'https://graph.facebook.com';

    public function __construct() {
        $this->db = Database::getInstance();
        $this->instagramAPI = new InstagramInsightsAPI();
        $this->logFile = '../logs/' . date('Y-m-d') . '_followers_cron.log';
        $this->loadConfig();
    }
    
    public function collectData() {
        try {
            
            $this->log("Iniciando coleta de dados de seguidores...");
            
            // Verificar se o token está válido
            if (!$this->validateToken()) {
                throw new Exception("Token de acesso inválido ou expirado");
            }
            
            // Buscar dados atuais de seguidores
            $accountData = $this->instagramAPI->getAccountInsights('day', ['follower_count']);
            $followerCount = $accountData['follower_count'] ?? 0;
            
            if ($followerCount === 0) {
                throw new Exception("Não foi possível obter contagem de seguidores");
            }
            
            // Salvar no banco de dados
            $this->saveFollowersData($followerCount);
            
            // Limpar dados antigos (manter apenas 90 dias)
            $this->cleanOldData();
            
            $this->log("Coleta concluída com sucesso. Seguidores: {$followerCount}");
            
            return true;
            
        } catch (Exception $e) {
            $this->log("ERRO: " . $e->getMessage());
            
            // Enviar alerta por email em caso de erro crítico
            $this->sendErrorAlert($e->getMessage());
            
            return false;
        }
    }
    
    private function validateToken() {
        try {
            // Tentar fazer uma chamada simples para validar o token
            $testData = $this->instagramAPI->getAccountInsights('day', ['follower_count']);
            return isset($testData['follower_count']);
            
        } catch (Exception $e) {
            $this->log("Token inválido: " . $e->getMessage());
            return false;
        }
    }
    
    private function saveFollowersData($followerCount) {
        try {
            // Verificar se já existe registro para hoje
            $today = date('Y-m-d');
            $stmt = $this->db->prepare("
                SELECT id FROM followers_history 
                WHERE DATE(created_at) = ?
            ");
            $stmt->execute([$today]);
            
            if ($stmt->rowCount() > 0) {
                // Atualizar registro existente
                $stmt = $this->db->prepare("
                    UPDATE followers_history 
                    SET follower_count = ?, updated_at = NOW()
                    WHERE DATE(created_at) = ?
                ");
                $stmt->execute([$followerCount, $today]);
                $this->log("Registro de hoje atualizado");
            } else {
                // Criar novo registro
                $stmt = $this->db->prepare("
                    INSERT INTO followers_history (follower_count, created_at) 
                    VALUES (?, NOW())
                ");
                $stmt->execute([$followerCount]);
                $this->log("Novo registro criado");
            }
            
            // Salvar também métricas adicionais se disponíveis
            $this->saveAdditionalMetrics($followerCount);
            
        } catch (Exception $e) {
            throw new Exception("Erro ao salvar dados: " . $e->getMessage());
        }
    }
    
    private function saveAdditionalMetrics($followerCount) {
        try {
            // Calcular e salvar métricas derivadas
            $yesterdayCount = $this->getYesterdayFollowers();
            $growth = $followerCount - $yesterdayCount;
            $growthRate = $yesterdayCount > 0 ? ($growth / $yesterdayCount) * 100 : 0;
            
            $stmt = $this->db->prepare("
                INSERT INTO daily_metrics (
                    metric_date, 
                    follower_count, 
                    daily_growth, 
                    growth_rate
                ) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    follower_count = VALUES(follower_count),
                    daily_growth = VALUES(daily_growth),
                    growth_rate = VALUES(growth_rate)
            ");
            
            $stmt->execute([
                date('Y-m-d'),
                $followerCount,
                $growth,
                $growthRate
            ]);
            
            $this->log("Métricas adicionais salvas. Crescimento: {$growth}");
            
        } catch (Exception $e) {
            $this->log("Aviso: Erro ao salvar métricas adicionais - " . $e->getMessage());
        }
    }
    
    private function getYesterdayFollowers() {
        try {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $stmt = $this->db->prepare("
                SELECT follower_count 
                FROM followers_history 
                WHERE DATE(created_at) = ?
            ");
            $stmt->execute([$yesterday]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['follower_count'] : 0;
            
        } catch (Exception $e) {
            return 0;
        }
    }
    
    private function cleanOldData() {
        try {
            // Manter apenas os últimos 90 dias
            $stmt = $this->db->prepare("
                DELETE FROM followers_history 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
            ");
            $deleted = $stmt->execute();
            
            if ($deleted) {
                $this->log("Dados antigos limpos (>90 dias)");
            }
            
        } catch (Exception $e) {
            $this->log("Aviso: Erro ao limpar dados antigos - " . $e->getMessage());
        }
    }
    
    private function sendErrorAlert($error) {
        try {
            $to = 'admin@seudominio.com'; // Configurar email do administrador
            $subject = 'Erro na Coleta de Dados de Seguidores - Instagram Insights';
            $message = "
            Erro na coleta automática de dados de seguidores:
            
            Data/Hora: " . date('Y-m-d H:i:s') . "
            Erro: {$error}
            
            Por favor, verifique o sistema.
            ";
            
            $headers = 'From: sistema@seudominio.com' . "\r\n" .
                      'Reply-To: sistema@seudominio.com' . "\r\n" .
                      'X-Mailer: PHP/' . phpversion();
            
            mail($to, $subject, $message, $headers);
            
        } catch (Exception $e) {
            $this->log("Erro ao enviar alerta por email: " . $e->getMessage());
        }
    }
    
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
        
        // Escrever no arquivo de log
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        // Também imprimir no console se executado manualmente
        if (php_sapi_name() === 'cli') {
            echo $logMessage;
        }
    }
    
    /**
     * Método para executar coleta manual
     */
    public function runManual() {
        echo "=== Coleta Manual de Dados de Seguidores ===" . PHP_EOL;
        echo "Iniciando..." . PHP_EOL;
        
        $success = $this->collectData();
        
        if ($success) {
            echo "✅ Coleta concluída com sucesso!" . PHP_EOL;
        } else {
            echo "❌ Erro na coleta. Verifique os logs." . PHP_EOL;
        }
        
        echo "Log: {$this->logFile}" . PHP_EOL;
    }

        private function loadConfig() {
        try {
            // Tentar carregar do banco primeiro
            $config = $this->getTokenFromDatabase();
            
            if ($config) {
                $this->accessToken = $config['itk_accessToken'];
                $this->instagramAccountId = $config['itk_accessToken'];
            } else {
                // Fallback para configuração estática (para desenvolvimento)
                $this->loadStaticConfig();
            }
            
            if (empty($this->accessToken) || empty($this->instagramAccountId)) {
                throw new Exception('Token de acesso ou ID da conta não configurados');
            }
            
        } catch (Exception $e) {
            // Log do erro mas não quebrar (usar dados simulados)
            error_log("Erro ao carregar configuração: " . $e->getMessage());
            $this->loadStaticConfig();
        }
    }

    private function logError($message) {
        $logFile = __DIR__ . '/../logs/' . date('Y-m-d') . '_instagram_get_errors.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] ERROR: {$message}" . PHP_EOL;
        
        // Criar diretório de logs se não existir
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    private function loadStaticConfig() {
        // ⚠️ CONFIGURAR ESTAS VARIÁVEIS COM SEUS DADOS REAIS
        $this->accessToken = getenv('INSTAGRAM_ACCESS_TOKEN') ?: 'SEU_TOKEN_AQUI';
        $this->instagramAccountId = getenv('INSTAGRAM_ACCOUNT_ID') ?: 'SEU_ACCOUNT_ID_AQUI';
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

        private function getTokenFromDatabase() {
        try {
            if (!class_exists('Database')) {
                return null;
            }
            
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("
                SELECT itk_accessToken, itk_instagramAccountId 
                FROM tbl_instagramToken 
                WHERE itk_status = 1 
                ORDER BY itk_created DESC 
                LIMIT 1
            ");
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->logError("Erro ao carregar token do banco: " . $e->getMessage());
            return null;
        }
    }
}

// Executar coleta
$collector = new FollowersDataCollector();

// Verificar se é execução manual ou cron
if (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === 'manual') {
    $collector->runManual();
} else {
    $collector->collectData();
}

?>