<?php
/**
 * Instagram Insights Collector - Cron Job
 * Execução via cron: 10 * * * * php /path/to/instagram_insights_cron.php
 */

class InstagramInsightsCollector 
{
    private $pdo;
    private $baseUrl = 'https://graph.facebook.com/v19.0';
    private $maxCallsPerHour = 200;
    private $logFile;
    
    public function __construct($dbConfig) 
    {
        // Conexão com banco
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['database']};charset=utf8mb4";
        $this->pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]);
        
        $this->logFile = __DIR__ . '/logs/instagram_insights_' . date('Y-m-d') . '.log';
        $this->ensureLogDirectory();
    }
    
    private function ensureLogDirectory() 
    {
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }
    
    private function log($message, $level = 'INFO') 
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        
        if ($level === 'ERROR') {
            error_log($logMessage);
        }
    }
    
    // Verifica rate limit por usuário
    private function canMakeApiCall($userId) 
    {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as call_count 
            FROM instagram_api_calls 
            WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        return $result['call_count'] < $this->maxCallsPerHour;
    }
    
    // Registra chamada API para rate limiting
    private function recordApiCall($userId) 
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO instagram_api_calls (user_id, created_at) 
            VALUES (?, NOW())
        ");
        $stmt->execute([$userId]);
        
        // Limpa registros antigos (>1 hora)
        $this->pdo->exec("
            DELETE FROM instagram_api_calls 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)
        ");
    }
    
    // Faz chamada para API do Instagram
    private function makeApiCall($url) 
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Instagram Insights Collector/1.0'
            ]
        ]);
        
        $response = file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception('Failed to fetch data from Instagram API');
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            throw new Exception('Instagram API Error: ' . $data['error']['message']);
        }
        
        return $data;
    }
    
    // Coleta insights do perfil
    public function collectProfileInsights($userId, $accessToken) 
    {
        if (!$this->canMakeApiCall($userId)) {
            $this->log("Rate limit exceeded for user: {$userId}", 'WARNING');
            return false;
        }
        
        try {
            $metrics = ['impressions', 'reach', 'profile_views', 'follower_count'];
            $since = date('Y-m-d', strtotime('-7 days'));
            $until = date('Y-m-d');
            
            $url = "{$this->baseUrl}/{$userId}/insights?" . http_build_query([
                'metric' => implode(',', $metrics),
                'period' => 'day',
                'since' => $since,
                'until' => $until,
                'access_token' => $accessToken
            ]);
            
            $this->log("Collecting profile insights for user: {$userId}");
            $data = $this->makeApiCall($url);
            $this->recordApiCall($userId);
            
            // Salva dados no banco
            $this->saveProfileInsights($userId, $data);
            
            $this->log("Profile insights collected successfully for user: {$userId}");
            return true;
            
        } catch (Exception $e) {
            $this->log("Error collecting profile insights for user {$userId}: " . $e->getMessage(), 'ERROR');
            
            // Registra erro no banco
            $this->recordError($userId, 'profile_insights', $e->getMessage());
            return false;
        }
    }
    
    // Coleta insights de posts recentes
    public function collectRecentPostsInsights($userId, $accessToken) 
    {
        try {
            // Busca posts recentes
            $url = "{$this->baseUrl}/{$userId}/media?" . http_build_query([
                'fields' => 'id,media_type,timestamp',
                'limit' => 10,
                'access_token' => $accessToken
            ]);
            
            $mediaData = $this->makeApiCall($url);
            $this->recordApiCall($userId);
            
            if (!isset($mediaData['data'])) {
                return true;
            }
            
            foreach ($mediaData['data'] as $media) {
                if (!$this->canMakeApiCall($userId)) {
                    $this->log("Rate limit reached, stopping post insights collection for user: {$userId}", 'WARNING');
                    break;
                }
                
                $this->collectPostInsights($media['id'], $userId, $accessToken);
                sleep(1); // Prevent rate limiting
            }
            
            return true;
            
        } catch (Exception $e) {
            $this->log("Error collecting posts insights for user {$userId}: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    // Coleta insights de um post específico
    private function collectPostInsights($mediaId, $userId, $accessToken) 
    {
        try {
            $metrics = ['impressions', 'reach', 'engagement', 'likes', 'comments', 'saves'];
            
            $url = "{$this->baseUrl}/{$mediaId}/insights?" . http_build_query([
                'metric' => implode(',', $metrics),
                'access_token' => $accessToken
            ]);
            
            $data = $this->makeApiCall($url);
            $this->recordApiCall($userId);
            
            $this->savePostInsights($mediaId, $userId, $data);
            
            $this->log("Post insights collected for media: {$mediaId}");
            
        } catch (Exception $e) {
            $this->log("Error collecting post insights for media {$mediaId}: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // Coleta demografia da audiência (com delay de 48h)
    public function collectAudienceDemographics($userId, $accessToken) 
    {
        if (!$this->canMakeApiCall($userId)) {
            return false;
        }
        
        try {
            $metrics = ['audience_gender_age', 'audience_locale', 'audience_country'];
            
            $url = "{$this->baseUrl}/{$userId}/insights?" . http_build_query([
                'metric' => implode(',', $metrics),
                'period' => 'lifetime',
                'access_token' => $accessToken
            ]);
            
            $this->log("Collecting demographics for user: {$userId}");
            $data = $this->makeApiCall($url);
            $this->recordApiCall($userId);
            
            $this->saveAudienceDemographics($userId, $data);
            
            $this->log("Demographics collected successfully for user: {$userId}");
            return true;
            
        } catch (Exception $e) {
            $this->log("Error collecting demographics for user {$userId}: " . $e->getMessage(), 'ERROR');
            
            // Demografia pode falhar para contas pequenas
            if (strpos($e->getMessage(), 'Insufficient data') !== false) {
                $this->log("User {$userId} has insufficient data for demographics (likely <100 followers)", 'INFO');
            }
            
            return false;
        }
    }
    
    // Salva insights do perfil no banco
    private function saveProfileInsights($userId, $data) 
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO instagram_profile_insights 
            (user_id, metric_name, metric_value, period_start, period_end, collected_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
            metric_value = VALUES(metric_value), 
            collected_at = VALUES(collected_at)
        ");
        
        foreach ($data['data'] as $metric) {
            foreach ($metric['values'] as $value) {
                $periodStart = isset($value['end_time']) ? 
                    date('Y-m-d', strtotime($value['end_time'] . ' -1 day')) : 
                    date('Y-m-d');
                $periodEnd = isset($value['end_time']) ? 
                    date('Y-m-d', strtotime($value['end_time'])) : 
                    date('Y-m-d');
                
                $stmt->execute([
                    $userId,
                    $metric['name'],
                    $value['value'],
                    $periodStart,
                    $periodEnd
                ]);
            }
        }
    }
    
    // Salva insights de posts no banco
    private function savePostInsights($mediaId, $userId, $data) 
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO instagram_post_insights 
            (media_id, user_id, metric_name, metric_value, collected_at) 
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
            metric_value = VALUES(metric_value), 
            collected_at = VALUES(collected_at)
        ");
        
        foreach ($data['data'] as $metric) {
            $stmt->execute([
                $mediaId,
                $userId,
                $metric['name'],
                $metric['values'][0]['value'] ?? 0
            ]);
        }
    }
    
    // Salva demografia da audiência
    private function saveAudienceDemographics($userId, $data) 
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO instagram_audience_demographics 
            (user_id, metric_name, demographic_data, collected_at) 
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
            demographic_data = VALUES(demographic_data), 
            collected_at = VALUES(collected_at)
        ");
        
        foreach ($data['data'] as $metric) {
            $stmt->execute([
                $userId,
                $metric['name'],
                json_encode($metric['values'][0]['value'] ?? [])
            ]);
        }
    }
    
    // Registra erros
    private function recordError($userId, $operation, $errorMessage) 
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO instagram_collection_errors 
            (user_id, operation, error_message, created_at) 
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $operation, $errorMessage]);
    }
    
    // Busca contas ativas para coleta
    public function getActiveAccounts() 
    {
        $stmt = $this->pdo->prepare("
            SELECT user_id, access_token, account_name, collection_priority
            FROM instagram_accounts 
            WHERE is_active = 1 AND access_token IS NOT NULL
            ORDER BY collection_priority DESC, last_collected ASC
            LIMIT 50
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    // Atualiza timestamp da última coleta
    private function updateLastCollected($userId) 
    {
        $stmt = $this->pdo->prepare("
            UPDATE instagram_accounts 
            SET last_collected = NOW() 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
    }
    
    // Execução principal do cron
    public function run() 
    {
        $this->log("Starting Instagram Insights collection");
        
        $accounts = $this->getActiveAccounts();
        $totalAccounts = count($accounts);
        $successCount = 0;
        $errorCount = 0;
        
        $this->log("Found {$totalAccounts} accounts to process");
        
        foreach ($accounts as $account) {
            $userId = $account['user_id'];
            $accessToken = $account['access_token'];
            $accountName = $account['account_name'];
            
            $this->log("Processing account: {$accountName} (ID: {$userId})");
            
            $success = true;
            
            // Coleta insights do perfil (prioridade alta)
            if (!$this->collectProfileInsights($userId, $accessToken)) {
                $success = false;
            }
            
            // Delay para evitar rate limit
            sleep(2);
            
            // Coleta insights de posts (prioridade média)
            if ($this->canMakeApiCall($userId)) {
                if (!$this->collectRecentPostsInsights($userId, $accessToken)) {
                    $success = false;
                }
            }
            
            // Delay maior antes da demografia
            sleep(3);
            
            // Coleta demografia (prioridade baixa, apenas uma vez por semana)
            $lastDemographicsCollection = $this->getLastDemographicsCollection($userId);
            if ($lastDemographicsCollection < strtotime('-7 days') && $this->canMakeApiCall($userId)) {
                $this->collectAudienceDemographics($userId, $accessToken);
            }
            
            if ($success) {
                $successCount++;
                $this->updateLastCollected($userId);
            } else {
                $errorCount++;
            }
            
            // Delay entre contas
            sleep(5);
        }
        
        $this->log("Instagram Insights collection completed. Success: {$successCount}, Errors: {$errorCount}");
        
        // Limpeza de logs antigos (manter apenas 30 dias)
        $this->cleanupOldLogs();
        
        return [
            'total' => $totalAccounts,
            'success' => $successCount,
            'errors' => $errorCount
        ];
    }
    
    private function getLastDemographicsCollection($userId) 
    {
        $stmt = $this->pdo->prepare("
            SELECT UNIX_TIMESTAMP(MAX(collected_at)) as last_collection
            FROM instagram_audience_demographics 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return $result['last_collection'] ?? 0;
    }
    
    private function cleanupOldLogs() 
    {
        $logDir = dirname($this->logFile);
        $files = glob($logDir . '/instagram_insights_*.log');
        
        foreach ($files as $file) {
            if (filemtime($file) < strtotime('-30 days')) {
                unlink($file);
            }
        }
    }
}

// Configuração do banco de dados
// require_once '../../bling/connect.php';

$dbConfig = [
    'host' => $_ENV['DB_HOST'] ?? 'www.tiven.com.br',
    'database' => $_ENV['DB_NAME'] ?? 'eladec62_tbs',
    'username' => $_ENV['DB_USER'] ?? 'eladec62_tbs',
    'password' => $_ENV['DB_PASS'] ?? 'Pedimu$-2019'
];

// Execução
try {
    $collector = new InstagramInsightsCollector($dbConfig);
    $result = $collector->run();
    
    echo "Instagram Insights Collection Summary:\n";
    echo "Total accounts: {$result['total']}\n";
    echo "Successful: {$result['success']}\n";
    echo "Errors: {$result['errors']}\n";
    
} catch (Exception $e) {
    error_log("Critical error in Instagram Insights Cron: " . $e->getMessage());
    echo "Critical error: " . $e->getMessage() . "\n";
    exit(1);
}
?>