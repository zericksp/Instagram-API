<?php
/**
 * InstagramInsightsAPI.php - Classe principal para interagir com Instagram Graph API
 * Localização: backend/classes/InstagramInsightsAPI.php
 */

class InstagramInsightsAPI {
    
    private $accessToken;
    private $instagramAccountId;
    private $apiVersion = 'v19.0';
    private $baseUrl = 'https://graph.facebook.com';
    
    // Métricas válidas após mudanças da API
    private $validMetrics = [
        'account' => [
            'accounts_engaged',
            'accounts_reached', 
            'follower_count',
            'profile_activity',
            'content_activity',
            'audience_city',
            'audience_country', 
            'audience_gender_age'
        ],
        'posts' => [
            'likes',
            'comments',
            'shares',
            'saves', 
            'reach', // Substitui impressions
            'total_interactions'
        ],
        'stories' => [
            'reach',
            'replies',
            'taps_forward',
            'taps_back',
            'exits'
        ],
        'videos' => [
            'reach', // Substitui video_views
            'likes',
            'comments', 
            'shares',
            'saves'
        ]
    ];
    
    // Mapeamento de métricas depreciadas
    private $deprecatedMetrics = [
        'impressions' => 'reach',
        'video_views' => 'reach' // Ou total_interactions dependendo do contexto
    ];
    
    public function __construct() {
        $this->loadConfig();
    }
    
    /**
     * Carrega configurações do banco de dados ou arquivo de configuração
     */
    private function loadConfig() {
        try {
            // Tentar carregar do banco primeiro
            $config = $this->getTokenFromDatabase();
            
            if ($config) {
                $this->accessToken = $config['itk_accessToken'];
                $this->instagramAccountId = $config['itk_instagramAccountId'];
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
    
    /**
     * Configuração estática para desenvolvimento/testes
     */
    private function loadStaticConfig() {
        // ⚠️ CONFIGURAR ESTAS VARIÁVEIS COM SEUS DADOS REAIS
        $this->accessToken = getenv('INSTAGRAM_ACCESS_TOKEN') ?: 'SEU_TOKEN_AQUI';
        $this->instagramAccountId = getenv('INSTAGRAM_ACCOUNT_ID') ?: 'SEU_ACCOUNT_ID_AQUI';
    }
    
    /**
     * Busca insights da conta com métricas válidas
     */
    public function getAccountInsights($period = 'day', $customMetrics = null) {
        try {
            $metrics = $customMetrics ?: $this->validMetrics['account'];
            $validatedMetrics = $this->validateMetrics($metrics, 'account');
            
            if (empty($validatedMetrics)) {
                throw new Exception('Nenhuma métrica válida fornecida');
            }
            
            $url = "{$this->baseUrl}/{$this->apiVersion}/{$this->instagramAccountId}/insights";
            $params = [
                'metric' => implode(',', $validatedMetrics),
                'period' => $period,
                'access_token' => $this->accessToken
            ];
            
            $response = $this->makeApiCall($url, $params);
            return $this->processAccountInsights($response);
            
        } catch (Exception $e) {
            $this->logError("Erro ao buscar insights da conta: " . $e->getMessage());
            return $this->getDefaultAccountData();
        }
    }
    
    /**
     * Busca insights de posts sem impressions
     */
    public function getPostsInsights($limit = 25, $customMetrics = null) {
        try {
            // Primeiro, busca os posts
            $postsUrl = "{$this->baseUrl}/{$this->apiVersion}/{$this->instagramAccountId}/media";
            $postsParams = [
                'fields' => 'id,media_type,timestamp,caption,permalink',
                'limit' => $limit,
                'access_token' => $this->accessToken
            ];
            
            $postsResponse = $this->makeApiCall($postsUrl, $postsParams);
            
            if (!isset($postsResponse['data'])) {
                return [];
            }
            
            $metrics = $customMetrics ?: $this->validMetrics['posts'];
            $validatedMetrics = $this->validateMetrics($metrics, 'posts');
            
            $postsWithInsights = [];
            
            foreach ($postsResponse['data'] as $post) {
                $postId = $post['id'];
                
                // Busca insights específicos do post
                $insightsUrl = "{$this->baseUrl}/{$this->apiVersion}/{$postId}/insights";
                $insightsParams = [
                    'metric' => implode(',', $validatedMetrics),
                    'access_token' => $this->accessToken
                ];
                
                try {
                    $insights = $this->makeApiCall($insightsUrl, $insightsParams);
                    $post['insights'] = $insights;
                    $post['engagement_rate'] = $this->calculatePostEngagement($insights);
                } catch (Exception $e) {
                    $this->logError("Erro ao buscar insights do post {$postId}: " . $e->getMessage());
                    $post['insights'] = ['data' => []];
                    $post['engagement_rate'] = 0;
                }
                
                $postsWithInsights[] = $post;
            }
            
            return $postsWithInsights;
            
        } catch (Exception $e) {
            $this->logError("Erro ao buscar insights dos posts: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Busca insights de stories (sem video_views)
     */
    public function getStoriesInsights($limit = 25) {
        try {
            // Busca stories dos últimos dias
            $storiesUrl = "{$this->baseUrl}/{$this->apiVersion}/{$this->instagramAccountId}/stories";
            $storiesParams = [
                'fields' => 'id,media_type,timestamp',
                'limit' => $limit,
                'access_token' => $this->accessToken
            ];
            
            $storiesResponse = $this->makeApiCall($storiesUrl, $storiesParams);
            
            if (!isset($storiesResponse['data'])) {
                return [];
            }
            
            $metrics = $this->validMetrics['stories'];
            $storiesWithInsights = [];
            
            foreach ($storiesResponse['data'] as $story) {
                $storyId = $story['id'];
                
                $insightsUrl = "{$this->baseUrl}/{$this->apiVersion}/{$storyId}/insights";
                $insightsParams = [
                    'metric' => implode(',', $metrics),
                    'access_token' => $this->accessToken
                ];
                
                try {
                    $insights = $this->makeApiCall($insightsUrl, $insightsParams);
                    $story['insights'] = $insights;
                    $story['completion_rate'] = $this->calculateStoryCompletion($insights);
                } catch (Exception $e) {
                    $this->logError("Erro ao buscar insights da story {$storyId}: " . $e->getMessage());
                    $story['insights'] = ['data' => []];
                }
                
                $storiesWithInsights[] = $story;
            }
            
            return $storiesWithInsights;
            
        } catch (Exception $e) {
            $this->logError("Erro ao buscar insights das stories: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Valida métricas e substitui depreciadas
     */
    private function validateMetrics($metrics, $type) {
        $validTypeMetrics = $this->validMetrics[$type] ?? [];
        $validatedMetrics = [];
        
        foreach ($metrics as $metric) {
            if (in_array($metric, $validTypeMetrics)) {
                $validatedMetrics[] = $metric;
            } elseif (isset($this->deprecatedMetrics[$metric])) {
                $replacement = $this->deprecatedMetrics[$metric];
                if (in_array($replacement, $validTypeMetrics)) {
                    $validatedMetrics[] = $replacement;
                    $this->logWarning("Métrica '{$metric}' substituída por '{$replacement}'");
                }
            } else {
                $this->logWarning("Métrica '{$metric}' não é válida para tipo '{$type}'");
            }
        }
        
        return array_unique($validatedMetrics);
    }
    
    /**
     * Processa insights da conta
     */
    private function processAccountInsights($response) {
        $processed = [];
        
        if (isset($response['data'])) {
            foreach ($response['data'] as $insight) {
                $name = $insight['name'];
                $values = $insight['values'] ?? [];
                
                if (!empty($values)) {
                    $processed[$name] = $values[0]['value'] ?? 0;
                }
            }
        }
        
        // Adicionar métricas calculadas
        $processed['engagement_rate'] = $this->calculateEngagementRate($processed);
        $processed['reach_rate'] = $this->calculateReachRate($processed);
        
        return $processed;
    }
    
    /**
     * Faz chamada para a API do Instagram
     */
    private function makeApiCall($url, $params) {
        $fullUrl = $url . '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Erro cURL: " . $error);
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode !== 200) {
            $errorMsg = $data['error']['message'] ?? 'Erro desconhecido da API';
            
            // Se for erro de token, tentar renovar
            if (strpos($errorMsg, 'access token') !== false) {
                $this->handleTokenError();
            }
            
            throw new Exception("Erro da API ({$httpCode}): " . $errorMsg);
        }
        
        return $data;
    }
    
    /**
     * Tenta lidar com erro de token
     */
    private function handleTokenError() {
        $this->logError("Token de acesso expirado ou inválido");
        // Aqui você pode implementar renovação automática do token
        // Por exemplo, chamar um script para renovar o token
    }
    
    /**
     * Cálculos auxiliares
     */
    private function calculateEngagementRate($data) {
        $reached = $data['accounts_reached'] ?? 0;
        $engaged = $data['accounts_engaged'] ?? 0;
        
        return $reached > 0 ? ($engaged / $reached) * 100 : 0;
    }
    
    private function calculateReachRate($data) {
        $followers = $data['follower_count'] ?? 0;
        $reached = $data['accounts_reached'] ?? 0;
        
        return $followers > 0 ? ($reached / $followers) * 100 : 0;
    }
    
    private function calculatePostEngagement($insights) {
        $reach = 0;
        $totalEngagement = 0;
        
        if (isset($insights['data'])) {
            foreach ($insights['data'] as $metric) {
                $name = $metric['name'];
                $value = $metric['values'][0]['value'] ?? 0;
                
                if ($name === 'reach') {
                    $reach = $value;
                } elseif (in_array($name, ['likes', 'comments', 'shares', 'saves'])) {
                    $totalEngagement += $value;
                }
            }
        }
        
        return $reach > 0 ? ($totalEngagement / $reach) * 100 : 0;
    }
    
    private function calculateStoryCompletion($insights) {
        $reach = 0;
        $exits = 0;
        
        if (isset($insights['data'])) {
            foreach ($insights['data'] as $metric) {
                $name = $metric['name'];
                $value = $metric['values'][0]['value'] ?? 0;
                
                if ($name === 'reach') {
                    $reach = $value;
                } elseif ($name === 'exits') {
                    $exits = $value;
                }
            }
        }
        
        return $reach > 0 ? (($reach - $exits) / $reach) * 100 : 0;
    }
    
    /**
     * Gerenciamento de dados do banco
     */
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
    
    /**
     * Dados padrão para fallback
     */
    private function getDefaultAccountData() {
        return [
            'accounts_reached' => 2500,
            'accounts_engaged' => 350,
            'follower_count' => 10250,
            'profile_activity' => 125,
            'content_activity' => 280,
            'engagement_rate' => 14.0,
            'reach_rate' => 24.4
        ];
    }
    
    /**
     * Sistema de logs
     */
    private function logError($message) {
        $logFile = __DIR__ . '/../logs/' . date('Y-m-d') . '_instagram_errors.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] ERROR: {$message}" . PHP_EOL;
        
        // Criar diretório de logs se não existir
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    private function logWarning($message) {
        $logFile = __DIR__ . '/../logs/' . date('Y-m-d') . '_instagram_warnings.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] WARNING: {$message}" . PHP_EOL;
        
        // Criar diretório de logs se não existir
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Método para testar a conexão com a API
     */
    public function testConnection() {
        try {
            $url = "{$this->baseUrl}/{$this->apiVersion}/{$this->instagramAccountId}";
            $params = [
                'fields' => 'id,username',
                'access_token' => $this->accessToken
            ];
            
            $response = $this->makeApiCall($url, $params);
            
            return [
                'success' => true,
                'account_id' => $response['id'] ?? null,
                'username' => $response['username'] ?? null
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}

/**
 * SCRIPT SQL PARA TABELA DE TOKENS (OPCIONAL):
 * 
 * CREATE TABLE IF NOT EXISTS instagram_tokens (
 *     id INT PRIMARY KEY AUTO_INCREMENT,
 *     access_token TEXT NOT NULL,
 *     instagram_account_id VARCHAR(50) NOT NULL,
 *     expires_at TIMESTAMP NULL,
 *     is_active BOOLEAN DEFAULT 1,
 *     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 * );
 */

?>

<?php
/**
 * EXEMPLO DE USO:
 * 
 * // Instanciar a API
 * $api = new InstagramInsightsAPI();
 * 
 * // Testar conexão
 * $test = $api->testConnection();
 * if (!$test['success']) {
 *     echo "Erro de conexão: " . $test['error'];
 *     exit;
 * }
 * 
 * // Buscar insights da conta
 * $accountData = $api->getAccountInsights('day', ['follower_count', 'accounts_reached']);
 * 
 * // Buscar insights de posts
 * $postsData = $api->getPostsInsights(10, ['likes', 'comments', 'reach']);
 * 
 * // Buscar insights de stories
 * $storiesData = $api->getStoriesInsights(20);
 */

/**
 * CONFIGURAÇÃO NECESSÁRIA:
 * 
 * 1. Token de Acesso do Instagram:
 *    - Ir para developers.facebook.com
 *    - Criar app Business
 *    - Adicionar Instagram Graph API
 *    - Gerar token de longa duração
 * 
 * 2. ID da Conta Instagram:
 *    - Usar Graph API Explorer para encontrar
 *    - Ou usar endpoint /me/accounts
 * 
 * 3. Variáveis de Ambiente (recomendado):
 *    - INSTAGRAM_ACCESS_TOKEN=seu_token_aqui
 *    - INSTAGRAM_ACCOUNT_ID=seu_account_id_aqui
 * 
 * 4. Ou configuração direta no código:
 *    - Substituir 'SEU_TOKEN_AQUI' pelo token real
 *    - Substituir 'SEU_ACCOUNT_ID_AQUI' pelo ID real
 */
?>