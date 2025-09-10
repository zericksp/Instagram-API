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
        // Carregar configurações do .env
        $this->loadEnv();

        $this->appId = $_ENV['INSTAGRAM_APP_ID'] ?? '';
        $this->appSecret = $_ENV['INSTAGRAM_APP_SECRET'] ?? '';

        // Conectar banco de dados
        $this->connectDatabase();

        // Configurar log
        $this->logFile = __DIR__ . '/logs/instagram_setup_' . date('Y-m-d') . '.log';
        $this->ensureLogDirectory();
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

    private function ensureLogDirectory()
    {
        // Garantir que o logFile não seja vazio
        if (empty($this->logFile)) {
            $this->logFile = __DIR__ . '/logs/instagram_setup_' . date('Y-m-d') . '.log';
        }

        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                // Fallback para diretório atual se não conseguir criar logs/
                $this->logFile = __DIR__ . '/instagram_setup_' . date('Y-m-d') . '.log';
            }
        }

        // Verificar se consegue escrever no arquivo
        if (!is_writable(dirname($this->logFile))) {
            $this->logFile = sys_get_temp_dir() . '/instagram_setup_' . date('Y-m-d') . '.log';
        }
    }

    public function log($message, $level = 'INFO')
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;

        // Garantir que logFile não está vazio
        if (empty($this->logFile)) {
            $this->logFile = __DIR__ . '/logs/instagram_setup_' . date('Y-m-d') . '.log';
            $this->ensureLogDirectory();
        }

        try {
            file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            // Fallback para error_log se falhar
            error_log("Instagram Setup Log: {$message}");
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

        $url = "https://graph.facebook.com/v23.0/me?fields=id,name,email&access_token={$accessToken}";
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

        $this->log("Token válido para usuário: " . ($data['name'] ?? 'ID: ' . $data['id']));
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

                // Obter detalhes da conta Instagram (sem account_type)
                $profileUrl = "https://graph.facebook.com/v23.0/{$igUserId}?fields=username,name,followers_count,media_count&access_token={$pageToken}";
                $profileResponse = @file_get_contents($profileUrl);

                // ========================================
                // MÉTRICAS SEPARADAS POR TIPO (2025)
                // ========================================

                // ✅ Métricas compatíveis com period=day (time_series)
                $metricsTimeSeries = "reach,follower_count,views";

                // ✅ Métricas que SÓ funcionam com metric_type=total_value
                $metricsTotalValue = "profile_views,accounts_engaged,total_interactions,likes,comments,shares,saves,replies";

                // Datas para o histórico
                $since = "2025-08-27";
                $until = "2025-09-02";

                try {
                    // 1. ✅ Métricas históricas (TIME_SERIES com period=day)
                    $urlTimeSeries = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" .
                        http_build_query([
                            'metric' => $metricsTimeSeries,
                            'period' => 'total_value',
                            'since' => $since,
                            'until' => $until,
                            'access_token' => $pageToken
                        ]);
                    $dataTimeSeries = $this->callGraphAPI($urlTimeSeries);

                    // 2. ✅ Métricas snapshot (TOTAL_VALUE)
                    $urlTotalValue = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" .
                        http_build_query([
                            'metric' => $metricsTotalValue,
                            'metric_type' => 'total_value',
                            'access_token' => $pageToken
                        ]);
                    $dataTotalValue = $this->callGraphAPI($urlTotalValue);

                    // 3. ✅ Views com breakdown (TIME_SERIES)
                    $urlViewsBreakdown = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" .
                        http_build_query([
                            'metric' => 'views',
                            'period' => 'day',
                            'breakdown' => 'media_product_type',
                            'since' => $since,
                            'until' => $until,
                            'access_token' => $pageToken
                        ]);
                    $dataViewsBreakdown = $this->callGraphAPI($urlViewsBreakdown);

                    // 4. ✅ Demographic insights (TOTAL_VALUE com timeframe)
                    $urlDemographics = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" .
                        http_build_query([
                            'metric' => 'follower_demographics,engaged_audience_demographics',
                            'metric_type' => 'total_value',
                            'timeframe' => 'last_30_days',
                            'breakdown' => 'country,city,age,gender',
                            'access_token' => $pageToken
                        ]);
                    $dataDemographics = $this->callGraphAPI($urlDemographics);

                    // Consolidar resultados
                    $insights = [
                        "time_series" => $dataTimeSeries,      // Dados históricos
                        "total_value" => $dataTotalValue,      // Snapshot atual
                        "views_breakdown" => $dataViewsBreakdown, // Views por tipo
                        "demographics" => $dataDemographics,   // Demografia
                        "periodo" => [
                            "desde" => $since,
                            "ate" => $until,
                            "coletado_em" => date('Y-m-d H:i:s')
                        ]
                    ];
                } catch (Exception $e) {
                    $this->log("Erro ao coletar insights: " . $e->getMessage(), 'ERROR');
                    $insights = ['error' => $e->getMessage()];
                }

                // ❌ REMOVIDA - chamada antiga que causava erro:
                // $url = "https://graph.facebook.com/v23.0/{$igUserId}/insights?metric=reach,profile_views,follower_count,accounts_engaged,total_interactions&period=day&since=2025-08-27&until=2025-09-02&access_token={$pageToken}";

                // ✅ EXEMPLO de chamadas corretas separadas:

                // Para dados históricos:
                $urlCorretoHistorico = "https://graph.facebook.com/v23.0/{$igUserId}/insights?metric=reach,follower_count,views&period=day&since={$since}&until={$until}&access_token={$pageToken}";
                $responseHistorico = file_get_contents($urlCorretoHistorico);
                $dataHistorico = json_decode($responseHistorico, true);

                // Para snapshot atual:
                $urlCorretoSnapshot = "https://graph.facebook.com/v23.0/{$igUserId}/insights?metric=profile_views,accounts_engaged,total_interactions&metric_type=total_value&access_token={$pageToken}";
                $responseSnapshot = file_get_contents($urlCorretoSnapshot);
                $dataSnapshot = json_decode($responseSnapshot, true);

                echo "<h3>Dados Históricos (Time Series):</h3>";
                print_r($dataHistorico);

                echo "<h3>Dados Snapshot (Total Value):</h3>";
                print_r($dataSnapshot);

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
                            'account_type' => 'business',
                            'insights' => $insights
                        ];

                        $this->log("✅ Conta Instagram encontrada: @{$profileData['username']} ({$profileData['followers_count']} seguidores)");
                    }
                }
            }
        }

        $this->log("Total de contas Instagram encontradas: " . count($instagramAccounts));
        return $instagramAccounts;
    }

    /**
     * Método auxiliar simplificado para obter insights específicos
     */
    public function getInstagramInsightsSimple($igUserId, $pageToken, $since = null, $until = null)
    {
        if (!$since) $since = date('Y-m-d', strtotime('-7 days'));
        if (!$until) $until = date('Y-m-d');

        $insights = [];

        try {
            // 1. ✅ Métricas históricas (funcionam com period=day)
            $historicalMetrics = "reach,follower_count,views";

            $urlHistorical = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" .
                http_build_query([
                    'metric' => $historicalMetrics,
                    'period' => 'day',
                    'since' => $since,
                    'until' => $until,
                    'access_token' => $pageToken
                ]);

            $insights['historical'] = $this->callGraphAPI($urlHistorical);

            // 2. ✅ Métricas snapshot (só funcionam com metric_type=total_value)
            $snapshotMetrics = "profile_views,accounts_engaged,total_interactions,likes,comments,shares,saves";

            $urlSnapshot = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" .
                http_build_query([
                    'metric' => $snapshotMetrics,
                    'metric_type' => 'total_value',
                    'access_token' => $pageToken
                ]);

            $insights['snapshot'] = $this->callGraphAPI($urlSnapshot);

            // 3. ✅ Views detalhado com breakdown
            $urlViewsBreakdown = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" .
                http_build_query([
                    'metric' => 'views',
                    'period' => 'day',
                    'breakdown' => 'media_product_type',
                    'since' => $since,
                    'until' => $until,
                    'access_token' => $pageToken
                ]);

            $insights['views_breakdown'] = $this->callGraphAPI($urlViewsBreakdown);
        } catch (Exception $e) {
            $insights['error'] = $e->getMessage();
        }

        return $insights;
    }
    /**
     * Método auxiliar para chamadas à Graph API com tratamento de erro
     */
    private function callGraphAPI($url)
    {
        $response = @file_get_contents($url);

        if ($response === false) {
            throw new Exception("Falha na chamada da API: {$url}");
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            throw new Exception("Erro da API: {$data['error']['message']} (Código: {$data['error']['code']})");
        }

        return $data;
    }

    /**
     * Método para obter insights específicos de uma conta Instagram
     * Implementação separada para maior flexibilidade
     */
    public function getInstagramInsights($igUserId, $pageToken, $options = [])
    {
        // Configurações padrão
        $defaults = [
            'period' => 'day',
            'since' => date('Y-m-d', strtotime('-7 days')),
            'until' => date('Y-m-d'),
            'include_demographics' => true,
            'include_views_breakdown' => true
        ];

        $options = array_merge($defaults, $options);
        $insights = [];

        try {
            // ========================================
            // MÉTRICAS BÁSICAS ATUALIZADAS (2025)
            // ========================================

            // Métricas principais - compatíveis com period=day
            $basicMetrics = "reach,profile_views,accounts_engaged,total_interactions,likes,comments,shares,saves";

            $basicUrl = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" .
                http_build_query([
                    'metric' => $basicMetrics,
                    'period' => $options['period'],
                    'since' => $options['since'],
                    'until' => $options['until'],
                    'access_token' => $pageToken
                ]);

            $insights['metricas_basicas'] = $this->callGraphAPI($basicUrl);

            // ========================================
            // NOVA MÉTRICA VIEWS (PRINCIPAL EM 2025)
            // ========================================

            if ($options['include_views_breakdown']) {
                $viewsUrl = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" .
                    http_build_query([
                        'metric' => 'views',
                        'period' => $options['period'],
                        'since' => $options['since'],
                        'until' => $options['until'],
                        'breakdown' => 'media_product_type',
                        'metric_type' => 'time_series',
                        'access_token' => $pageToken
                    ]);

                $insights['views_detalhado'] = $this->callGraphAPI($viewsUrl);
            }

            // ========================================
            // DADOS DEMOGRÁFICOS
            // ========================================

            if ($options['include_demographics']) {
                $demographicsUrl = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" .
                    http_build_query([
                        'metric' => 'follower_demographics,engaged_audience_demographics',
                        'period' => 'lifetime',
                        'timeframe' => 'last_30_days',
                        'metric_type' => 'total_value',
                        'breakdown' => 'country,city,age,gender',
                        'access_token' => $pageToken
                    ]);

                $insights['demograficos'] = $this->callGraphAPI($demographicsUrl);
            }

            // ========================================
            // MÉTRICAS DE FOLLOWER COUNT
            // ========================================

            // Observação: follower_count não está disponível para contas com menos de 100 seguidores
            $followerUrl = "https://graph.facebook.com/v23.0/{$igUserId}/insights?" .
                http_build_query([
                    'metric' => 'follower_count,follows_and_unfollows',
                    'period' => $options['period'],
                    'since' => $options['since'],
                    'until' => $options['until'],
                    'access_token' => $pageToken
                ]);

            $insights['seguidores'] = $this->callGraphAPI($followerUrl);
        } catch (Exception $e) {
            $this->log("Erro ao obter insights detalhados: " . $e->getMessage(), 'ERROR');
            $insights['erro'] = $e->getMessage();
        }

        return $insights;
    }


    // Obter token de longa duração
    public function getLongLivedToken($shortToken)
    {
        $this->log("Obtendo token de longa duração...");

        if (empty($this->appId) || empty($this->appSecret)) {
            $this->log("App ID ou App Secret não configurados no .env", 'WARNING');
            return false;
        }
        // curl -X GET 
        // "https://graph.facebook.com/v23.0/oauth/access_token?grant_type=fb_exchange_token&client_id=540109221671620&client_secret=SEU_APP_SECRET&fb_exchange_token=SEU_TOKEN_CURTO"


        $url = "https://graph.facebook.com/v23.0/oauth/access_token?" . http_build_query([
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'fb_exchange_token' => $shortToken
        ]);

        $response = @file_get_contents($url);

        if ($response === false) {
            $this->log("Erro de rede ao obter token de longa duração", 'ERROR');
            return false;
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            $this->log("Erro ao obter token de longa duração: " . $data['error']['message'], 'ERROR');
            return false;
        }

        if (!isset($data['access_token'])) {
            $this->log("Resposta da API não contém access_token", 'ERROR');
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

        // Testar insights de perfil com período mais amplo
        $metricsUrl = "https://graph.facebook.com/v19.0/{$userId}/insights?" . http_build_query([
            'metric' => 'impressions,reach,profile_views',
            'period' => 'day',
            'since' => date('Y-m-d', strtotime('-7 days')),
            'until' => date('Y-m-d', strtotime('-1 day')),
            'access_token' => $accessToken
        ]);

        $response = @file_get_contents($metricsUrl);

        if ($response === false) {
            $this->log("Erro de rede ao testar coleta de insights", 'ERROR');
            return false;
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            $this->log("Erro na API de insights: " . $data['error']['message'], 'ERROR');

            // Se erro for por falta de dados, tentar teste mais simples
            if (strpos($data['error']['message'], 'Insufficient data') !== false) {
                return $this->testBasicProfile($userId, $accessToken);
            }

            return false;
        }

        $metricsCount = isset($data['data']) ? count($data['data']) : 0;
        $this->log("✅ Teste de coleta bem-sucedido. Métricas disponíveis: {$metricsCount}");
        return true;
    }

    // Teste básico de perfil (fallback)
    private function testBasicProfile($userId, $accessToken)
    {
        $this->log("Executando teste básico de perfil...");

        $profileUrl = "https://graph.facebook.com/v19.0/{$userId}?" . http_build_query([
            'fields' => 'username,followers_count,account_type',
            'access_token' => $accessToken
        ]);

        $response = @file_get_contents($profileUrl);

        if ($response === false) {
            $this->log("Erro no teste básico de perfil", 'ERROR');
            return false;
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            $this->log("Erro no teste básico: " . $data['error']['message'], 'ERROR');
            return false;
        }

        $this->log("✅ Teste básico de perfil bem-sucedido");
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
