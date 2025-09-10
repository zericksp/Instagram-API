<?php
/**
 * Instagram Insights - Classe de Setup (SEM execução automática)
 * Esta classe deve ser incluída via require, não executada diretamente
 */

class InstagramSetup 
{
    public $pdo;
    private $appId;
    private $appSecret;
    private $logFile;
    
    public function __construct() 
    {
        // Configurar log PRIMEIRO (antes de outras operações)
        $this->initializeLogging();
        
        // Carregar configurações do .env
        $this->loadEnv();
        
        $this->appId = $_ENV['INSTAGRAM_APP_ID'] ?? '';
        $this->appSecret = $_ENV['INSTAGRAM_APP_SECRET'] ?? '';
        
        // Conectar banco de dados
        $this->connectDatabase();
    }
    
    private function initializeLogging() 
    {
        // Garantir que o diretório base existe
        $baseDir = __DIR__;
        if (empty($baseDir)) {
            $baseDir = dirname(__FILE__);
        }
        
        // Configurar arquivo de log com caminho absoluto
        $logDir = $baseDir . '/logs';
        $this->logFile = $logDir . '/instagram_setup_' . date('Y-m-d') . '.log';
        
        // Garantir que o diretório de logs existe
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                // Se não conseguir criar o diretório, usar temp
                $this->logFile = sys_get_temp_dir() . '/instagram_setup_' . date('Y-m-d') . '.log';
            }
        }
        
        // Verificar se o arquivo de log é válido
        if (empty($this->logFile)) {
            $this->logFile = sys_get_temp_dir() . '/instagram_setup_' . date('Y-m-d') . '.log';
        }
    }
    
    private function loadEnv() 
    {
        if (file_exists(__DIR__ . '/.env')) {
            $lines = file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && strpos($line, '#') !== 0) {
                    [$key, $value] = explode('=', $line, 2);
                    $_ENV[trim($key)] = trim($value);
                }
            }
        }
    }
    
    private function connectDatabase() 
    {
        try {
            $dsn = "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4";
            $this->pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASS'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $this->log("Conexão com banco de dados estabelecida");
        } catch (PDOException $e) {
            $this->log("Erro na conexão com banco: " . $e->getMessage(), 'ERROR');
            throw new Exception("Erro na conexão com banco: " . $e->getMessage());
        }
    }
    
    public function log($message, $level = 'INFO') 
    {
        // Verificar se logFile está configurado
        if (empty($this->logFile)) {
            $this->initializeLogging();
        }
        
        // Verificar novamente se está configurado
        if (empty($this->logFile)) {
            // Fallback para error_log se tudo falhar
            error_log("[INSTAGRAM_SETUP] [{$level}] {$message}");
            return;
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
        
        try {
            file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            // Fallback para error_log se file_put_contents falhar
            error_log("[INSTAGRAM_SETUP] Log error: " . $e->getMessage());
            error_log("[INSTAGRAM_SETUP] [{$level}] {$message}");
        }
        
        // Para CLI, também mostrar na tela
        if (php_sapi_name() === 'cli') {
            echo $logMessage;
        }
    }
    
    // Validar token de acesso
    public function validateToken($accessToken) 
    {
        $this->log("Validando token de acesso...");
        
        $url = "https://graph.facebook.com/v19.0/me?fields=id%2Cname&access_token={$accessToken}";
        $response = @file_get_contents($url);
        
        if ($response === false) {
            $this->log("Erro de rede ao validar token", 'ERROR');
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            $this->log("Token inválido: " . $data['error']['message'], 'ERROR');
            return false;
        }
        
        $this->log("Token válido para usuário: " . $data['name']);
        return $data;
    }
    
    // Descobrir contas Instagram conectadas
    public function discoverInstagramAccounts($userToken) 
{
    $this->log("Buscando contas Instagram conectadas...");
    
    // Obter páginas do Facebook
    $pagesUrl = "https://graph.facebook.com/v23.0/me/accounts?access_token={$userToken}";
    $pagesResponse = @file_get_contents($pagesUrl);
    
    if ($pagesResponse === false) {
        $this->log("Erro ao buscar páginas do Facebook", 'ERROR');
        return [];
    }
    
    $pagesData = json_decode($pagesResponse, true);
    $instagramAccounts = [];
    
    if (!isset($pagesData['data'])) {
        $this->log("Nenhuma página encontrada", 'WARNING');
        return [];
    }
    
    foreach ($pagesData['data'] as $page) {
        $pageId = $page['id'];
        $pageName = $page['name'];
        $pageToken = $page['access_token'];
        
        $this->log("Verificando página: {$pageName}");
        
        // Verificar conta Instagram conectada à página
        $igUrl = "https://graph.facebook.com/v23.0/{$pageId}?fields=instagram_business_account&access_token={$pageToken}";
        $igResponse = @file_get_contents($igUrl);
        
        if ($igResponse === false) {
            $this->log("Erro ao verificar Instagram para página: {$pageName}", 'WARNING');
            continue;
        }
        
        $igData = json_decode($igResponse, true);
        
        if (isset($igData['instagram_business_account'])) {
            // Instagram Business Account ID
            $igUserId = $igData['instagram_business_account']['id'];
            
            // Obter detalhes da conta Instagram
            $profileUrl = "https://graph.facebook.com/v23.0/{$igUserId}?fields=username,name,followers_count,media_count,account_type&access_token={$pageToken}";
            $profileResponse = @file_get_contents($profileUrl);

            // ========================================
            // MÉTRICAS CORRIGIDAS - SETEMBRO 2025
            // ========================================

            // ❌ REMOVIDO: impressions,reach,follower_count (depreciados)
            // ✅ ADICIONADO: views,profile_views,accounts_engaged,total_interactions

            // Métricas válidas para period=day (histórico)
            $metricsDaily = "views,reach,profile_views,accounts_engaged,total_interactions";

            // Métricas válidas para metric_type=total_value (snapshot)
            $metricsSnapshot = "views,reach,profile_views,accounts_engaged,total_interactions,likes,comments,shares,saves,replies,follower_count";

            // Datas para o histórico
            $since = "2025-08-27";
            $until = "2025-09-02";

            // 1. Coletar métricas históricas (CORRIGIDO)
            $urlDaily = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" . 
                       http_build_query([
                           'metric' => $metricsDaily,
                           'period' => 'day',
                           'since' => $since,
                           'until' => $until,
                           'access_token' => $pageToken
                       ]);
            $dataDaily = $this->callGraphAPI($urlDaily);

            // 2. Coletar métricas snapshot (CORRIGIDO)
            $urlSnapshot = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" . 
                          http_build_query([
                              'metric' => $metricsSnapshot,
                              'metric_type' => 'total_value',
                              'access_token' => $pageToken
                          ]);
            $dataSnapshot = $this->callGraphAPI($urlSnapshot);

            // 3. Nova métrica VIEWS com breakdown (ADICIONADO)
            $urlViewsBreakdown = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" . 
                                http_build_query([
                                    'metric' => 'views',
                                    'period' => 'day',
                                    'breakdown' => 'media_product_type', // FEED, STORY, REELS
                                    'since' => $since,
                                    'until' => $until,
                                    'access_token' => $pageToken
                                ]);
            $dataViewsBreakdown = $this->callGraphAPI($urlViewsBreakdown);

            // 4. Juntar os resultados
            $result = [
                "historico" => $dataDaily,
                "snapshot" => $dataSnapshot,
                "views_breakdown" => $dataViewsBreakdown
            ];

            // ❌ REMOVIDA a chamada antiga com campos depreciados:
            // $url = "https://graph.facebook.com/v23.0/{$igUserId}/insights?metric=reach,profile_views,follower_count,content_views&period=day&since=2025-08-27&until=2025-09-02&access_token={$pageToken}";
            
            // ✅ NOVA chamada com campos válidos:
            $urlCorrected = "https://graph.facebook.com/v23.0/{$igUserId}/insights?metric=views,reach,profile_views,accounts_engaged&period=day&since={$since}&until={$until}&access_token={$pageToken}";
            $response = file_get_contents($urlCorrected);
            $data = json_decode($response, true);

            print_r($data);

            if ($profileResponse !== false) {
                $profileData = json_decode($profileResponse, true);
                
                if (!isset($profileData['error'])) {
                    $instagramAccounts[] = [
                        'page_id' => $pageId,
                        'page_name' => $pageName,
                        'page_token' => $pageToken,
                        'instagram_user_id' => $igUserId,
                        'username' => $profileData['username'] ?? $pageName,
                        'name' => $profileData['name'] ?? $pageName,
                        'followers_count' => $profileData['followers_count'] ?? 0,
                        'media_count' => $profileData['media_count'] ?? 0,
                        'account_type' => strtolower($profileData['account_type'] ?? 'business'),
                        'insights' => $result
                    ];
                    
                    $this->log("✅ Conta Instagram encontrada: @{$profileData['username']} ({$profileData['followers_count']} seguidores)");
                }
            }
        }
    }
    
    $this->log("Total de contas Instagram encontradas: " . count($instagramAccounts));
    return $instagramAccounts;
}
    
    // Obter token de longa duração
    public function getLongLivedToken($shortToken) 
    {
        $this->log("Obtendo token de longa duração...");
        
        $url = "https://graph.facebook.com/v19.0/oauth/access_token?" . http_build_query([
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'fb_exchange_token' => $shortToken
        ]);
        
        $response = @file_get_contents($url);
        
        if ($response === false) {
            $this->log("Erro ao obter token de longa duração", 'ERROR');
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            $this->log("Erro ao obter token de longa duração: " . $data['error']['message'], 'ERROR');
            return false;
        }
        
        $expiresIn = $data['expires_in'] ?? 5184000; // Default 60 dias
        $this->log("Token de longa duração obtido (expira em {$expiresIn} segundos)");
        
        return $data['access_token'];
    }
    
    // Inserir conta no banco de dados
    public function insertAccount($accountData, $accessToken) 
    {
        $this->log("Inserindo conta no banco: @{$accountData['username']}");
        
        try {
            // Calcular expiração do token (60 dias por padrão)
            $tokenExpiry = date('Y-m-d H:i:s', strtotime('+60 days'));
            
            $stmt = $this->pdo->prepare("
                INSERT INTO instagram_accounts 
                (user_id, account_name, access_token, token_expires_at, account_type, 
                 collection_priority, is_active, follower_count) 
                VALUES (?, ?, ?, ?, ?, ?, TRUE, ?)
                ON DUPLICATE KEY UPDATE
                    account_name = VALUES(account_name),
                    access_token = VALUES(access_token),
                    token_expires_at = VALUES(token_expires_at),
                    account_type = VALUES(account_type),
                    follower_count = VALUES(follower_count),
                    updated_at = CURRENT_TIMESTAMP
            ");
            
            $priority = $accountData['followers_count'] > 10000 ? 3 : 2; // Prioridade baseada em seguidores
            
            $stmt->execute([
                $accountData['instagram_user_id'],
                $accountData['username'],
                $accessToken,
                $tokenExpiry,
                $accountData['account_type'],
                $priority,
                $accountData['followers_count']
            ]);
            
            $this->log("✅ Conta inserida/atualizada com sucesso: @{$accountData['username']}");
            return true;
            
        } catch (PDOException $e) {
            $this->log("Erro ao inserir conta no banco: " . $e->getMessage(), 'ERROR');
            return false;
        }
    }
    
    // Testar coleta de dados
    public function testDataCollection($userId, $accessToken) 
    {
        $this->log("Testando coleta de dados para user_id: {$userId}");
        
        // Testar insights de perfil
        $metricsUrl = "https://graph.facebook.com/v19.0/{$userId}/insights?" . http_build_query([
            'metric' => 'impressions,reach,profile_views,follower_count',
            'period' => 'day',
            'since' => date('Y-m-d', strtotime('-1 day')),
            'until' => date('Y-m-d'),
            'access_token' => $accessToken
        ]);
        
        $response = @file_get_contents($metricsUrl);
        
        if ($response === false) {
            $this->log("Erro ao testar coleta de insights", 'ERROR');
            return false;
        }
        
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            $this->log("Erro na API de insights: " . $data['error']['message'], 'ERROR');
            return false;
        }
        
        $this->log("✅ Teste de coleta bem-sucedido. Métricas disponíveis: " . count($data['data']));
        return true;
    }
    
    // Configuração completa automatizada
    public function setupAccounts($userToken) 
    {
        $this->log("=== INICIANDO CONFIGURAÇÃO AUTOMÁTICA ===");
        
        // 1. Validar token
        if (!$this->validateToken($userToken)) {
            $this->log("Configuração abortada devido a token inválido", 'ERROR');
            return false;
        }
        
        // 2. Obter token de longa duração
        $longToken = $this->getLongLivedToken($userToken);
        if (!$longToken) {
            $this->log("Usando token original (não foi possível obter token de longa duração)", 'WARNING');
            $longToken = $userToken;
        }
        
        // 3. Descobrir contas Instagram
        $accounts = $this->discoverInstagramAccounts($longToken);
        
        if (empty($accounts)) {
            $this->log("Nenhuma conta Instagram encontrada", 'ERROR');
            return false;
        }
        
        // 4. Configurar cada conta
        $successCount = 0;
        foreach ($accounts as $account) {
            $this->log("--- Configurando conta: @{$account['username']} ---");
            
            // Inserir no banco
            if ($this->insertAccount($account, $longToken)) {
                // Testar coleta
                if ($this->testDataCollection($account['instagram_user_id'], $longToken)) {
                    $successCount++;
                    $this->log("✅ Conta @{$account['username']} configurada com sucesso");
                } else {
                    $this->log("⚠️ Conta inserida mas teste de coleta falhou", 'WARNING');
                }
            }
        }
        
        $this->log("=== CONFIGURAÇÃO CONCLUÍDA ===");
        $this->log("Contas processadas: " . count($accounts));
        $this->log("Configuradas com sucesso: {$successCount}");
        
        // 5. Executar primeira coleta
        if ($successCount > 0) {
            $this->log("Executando primeira coleta de dados...");
            $this->runFirstCollection();
        }
        
        return $successCount > 0;
    }
    
    // Executar primeira coleta de teste
    private function runFirstCollection() 
    {
        try {
            // Executar script de coleta
            $output = [];
            $returnCode = 0;
            
            exec('php ' . __DIR__ . '/instagram_insights_cron.php 2>&1', $output, $returnCode);
            
            if ($returnCode === 0) {
                $this->log("✅ Primeira coleta executada com sucesso");
                $this->log("Saída: " . implode("\n", $output));
            } else {
                $this->log("⚠️ Primeira coleta com problemas (código: {$returnCode})", 'WARNING');
                $this->log("Saída: " . implode("\n", $output));
            }
            
        } catch (Exception $e) {
            $this->log("Erro ao executar primeira coleta: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // Mostrar status do sistema
    public function showSystemStatus() 
    {
        $this->log("=== STATUS DO SISTEMA ===");
        
        // Contas ativas
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
        $accounts = $stmt->fetchAll();
        
        $this->log("Contas ativas no sistema:");
        foreach ($accounts as $account) {
            $this->log("  @{$account['account_name']} ({$account['follower_count']} seguidores) - {$account['status']}");
        }
        
        // Dados coletados hoje
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as metricas_hoje,
                COUNT(DISTINCT user_id) as contas_com_dados
            FROM instagram_profile_insights 
            WHERE period_end >= CURDATE()
        ");
        $stmt->execute();
        $todayStats = $stmt->fetch();
        
        $this->log("Dados coletados hoje: {$todayStats['metricas_hoje']} métricas de {$todayStats['contas_com_dados']} contas");
        
        // Alertas ativos
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as alertas_ativos
            FROM instagram_alerts 
            WHERE is_processed = FALSE
        ");
        $stmt->execute();
        $alerts = $stmt->fetch();
        
        $this->log("Alertas ativos: {$alerts['alertas_ativos']}");
    }
}
?>