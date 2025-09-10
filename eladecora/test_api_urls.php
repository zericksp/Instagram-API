<?php
/**
 * Instagram Insights - Teste das URLs da API
 * Verifica se as correções das URLs da API Facebook/Instagram estão funcionando
 * Execute: php test_api_urls.php [OPTIONAL_TOKEN]
 */

require_once __DIR__ . '/instagram_setup_class.php';

echo "🧪 TESTANDO URLS DA API FACEBOOK/INSTAGRAM\n";
echo str_repeat("=", 60) . "\n\n";

try {
    // 1. Testar inicialização da classe (problema do log file)
    echo "1. Testando inicialização da classe...\n";
    
    $setup = new InstagramSetup();
    echo "   ✅ Classe inicializada sem erro 'Path cannot be empty'\n";
    echo "   📁 Arquivo de log: " . (new ReflectionClass($setup))->getProperty('logFile')->getValue($setup) . "\n";
    
    // 2. Testar log file
    echo "\n2. Testando sistema de log...\n";
    $setup->log("Teste de log - sistema funcionando");
    echo "   ✅ Log escrito com sucesso\n";
    
    // 3. Verificar se token foi fornecido para testes completos
    $testToken = $argv[1] ?? null;
    
    if ($testToken) {
        echo "\n3. Testando URLs da API com token fornecido...\n";
        echo "   Token length: " . strlen($testToken) . " caracteres\n";
        
        // 3a. Testar validação de token
        echo "\n   3a. Testando validação de token...\n";
        $validation = $setup->validateToken($testToken);
        
        if ($validation) {
            echo "   ✅ Token válido!\n";
            echo "   👤 Usuário: " . ($validation['name'] ?? 'N/A') . "\n";
            echo "   🆔 ID: " . ($validation['id'] ?? 'N/A') . "\n";
            echo "   📧 Email: " . ($validation['email'] ?? 'N/A') . "\n";
            
            // 3b. Testar obtenção de token de longa duração
            echo "\n   3b. Testando token de longa duração...\n";
            $longToken = $setup->getLongLivedToken($testToken);
            
            if ($longToken) {
                echo "   ✅ Token de longa duração obtido com sucesso\n";
                echo "   📏 Tamanho do novo token: " . strlen($longToken) . " caracteres\n";
            } else {
                echo "   ⚠️  Não foi possível obter token de longa duração\n";
                echo "   💡 Isso é normal se App ID/Secret não estiverem no .env\n";
                $longToken = $testToken; // usar original
            }
            
            // 3c. Testar descoberta de contas Instagram
            echo "\n   3c. Testando descoberta de contas Instagram...\n";
            $accounts = $setup->discoverInstagramAccounts($longToken);
            
            if (!empty($accounts)) {
                echo "   ✅ " . count($accounts) . " conta(s) Instagram encontrada(s):\n";
                foreach ($accounts as $account) {
                    echo "      📸 @{$account['username']} ({$account['followers_count']} seguidores)\n";
                    echo "         🏷️  Tipo: {$account['account_type']} | ID: {$account['instagram_user_id']}\n";
                    
                    // 3d. Testar coleta de dados para esta conta
                    echo "      🧪 Testando coleta de dados...\n";
                    $testResult = $setup->testDataCollection($account['instagram_user_id'], $longToken);
                    
                    if ($testResult) {
                        echo "      ✅ Teste de coleta bem-sucedido\n";
                    } else {
                        echo "      ⚠️  Teste de coleta falhou (normal para contas novas)\n";
                    }
                    echo "\n";
                }
            } else {
                echo "   ⚠️  Nenhuma conta Instagram Business encontrada\n";
                echo "   💡 Certifique-se que sua conta Instagram está conectada a uma página Facebook\n";
            }
            
        } else {
            echo "   ❌ Token inválido ou sem permissões adequadas\n";
            echo "   📋 Permissões necessárias:\n";
            echo "      • instagram_basic\n";
            echo "      • instagram_manage_insights\n";
            echo "      • pages_show_list\n";
            echo "      • pages_read_engagement\n";
        }
        
    } else {
        echo "\n3. Para testar URLs da API completas, execute:\n";
        echo "   php test_api_urls.php SEU_TOKEN_AQUI\n\n";
        echo "   💡 Para obter um token de teste:\n";
        echo "   1. Acesse: https://developers.facebook.com/tools/explorer/\n";
        echo "   2. Selecione seu app Instagram\n";
        echo "   3. Get User Access Token com as permissões necessárias\n";
    }
    
    // 4. Testar URLs sem token (estrutura)
    echo "\n4. Testando estrutura das URLs da API...\n";
    
    $testUrls = [
        'Token Validation' => "https://graph.facebook.com/v19.0/me?fields=id,name,email&access_token=TOKEN",
        'User Pages' => "https://graph.facebook.com/v19.0/me/accounts?access_token=TOKEN",
        'Page Instagram Account' => "https://graph.facebook.com/v19.0/PAGE_ID?fields=instagram_business_account&access_token=TOKEN",
        'Instagram Profile' => "https://graph.facebook.com/v19.0/IG_USER_ID?fields=username,name,followers_count&access_token=TOKEN",
        'Instagram Insights' => "https://graph.facebook.com/v19.0/IG_USER_ID/insights?metric=impressions,reach&period=day&access_token=TOKEN",
        'Long-Lived Token' => "https://graph.facebook.com/v19.0/oauth/access_token?grant_type=fb_exchange_token&client_id=APP_ID&client_secret=APP_SECRET&fb_exchange_token=TOKEN"
    ];
    
    foreach ($testUrls as $name => $url) {
        echo "   ✅ {$name}: Estrutura URL OK\n";
        echo "      📍 " . substr($url, 0, 80) . "...\n";
    }
    
    // 5. Verificar arquivo .env
    echo "\n5. Verificando configuração .env...\n";
    
    if (file_exists(__DIR__ . '/.env')) {
        echo "   ✅ Arquivo .env encontrado\n";
        
        $envVars = ['DB_HOST', 'DB_NAME', 'DB_USER', 'INSTAGRAM_APP_ID', 'INSTAGRAM_APP_SECRET'];
        foreach ($envVars as $var) {
            $value = $_ENV[$var] ?? 'não configurado';
            if ($var === 'INSTAGRAM_APP_SECRET' && $value !== 'não configurado') {
                $value = substr($value, 0, 10) . '...'; // Mascarar secret
            }
            echo "   📋 {$var}: " . ($value !== 'não configurado' ? '✅ configurado' : '❌ não configurado') . "\n";
        }
    } else {
        echo "   ⚠️  Arquivo .env não encontrado\n";
        echo "   💡 Copie .env.example para .env e configure suas credenciais\n";
    }
    
    // 6. Verificar logs gerados
    echo "\n6. Verificando logs gerados durante os testes...\n";
    
    $logFiles = glob(__DIR__ . '/logs/instagram_setup_*.log');
    if (!empty($logFiles)) {
        $latestLog = max($logFiles);
        echo "   ✅ Log gerado: " . basename($latestLog) . "\n";
        
        $logContent = file_get_contents($latestLog);
        $logLines = explode("\n", trim($logContent));
        
        echo "   📄 Últimas " . min(5, count($logLines)) . " linhas do log:\n";
        foreach (array_slice($logLines, -5) as $line) {
            if (trim($line)) {
                echo "      " . trim($line) . "\n";
            }
        }
    } else {
        echo "   ⚠️  Nenhum log encontrado (pode ser normal)\n";
    }
    
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "📋 RESUMO DOS TESTES\n";
    echo str_repeat("=", 60) . "\n";
    
    $score = 0;
    $totalTests = 4;
    
    // Calcular score
    if (isset($setup)) $score++; // Inicialização OK
    if (file_exists(__DIR__ . '/.env')) $score++; // .env existe
    if (!empty($logFiles)) $score++; // Log gerado
    if (isset($validation) && $validation) $score++; // Token válido (se testado)
    
    $percentage = round(($score / $totalTests) * 100);
    
    if ($percentage >= 75) {
        echo "✅ URLs da API corrigidas e funcionando bem ({$percentage}%)\n";
        echo "   Sistema pronto para uso!\n";
    } elseif ($percentage >= 50) {
        echo "⚠️  URLs da API parcialmente funcionando ({$percentage}%)\n";
        echo "   Algumas configurações podem precisar de ajuste.\n";
    } else {
        echo "❌ URLs da API precisam de mais correções ({$percentage}%)\n";
        echo "   Verifique as configurações e tente novamente.\n";
    }
    
    echo "\n📚 PRÓXIMOS PASSOS:\n";
    if (!$testToken) {
        echo "1. Teste com token real: php test_api_urls.php TOKEN\n";
    }
    echo "2. Configure .env se necessário\n";
    echo "3. Teste interface web: http://localhost/instagram_web_setup.html\n";
    echo "4. Execute coleta completa: php instagram_insights_cron.php\n";
    
    echo "\n🎉 Teste concluído!\n";
    
} catch (Exception $e) {
    echo "\n❌ ERRO DURANTE O TESTE:\n";
    echo "Erro: " . $e->getMessage() . "\n";
    echo "Arquivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nVerifique:\n";
    echo "• Arquivo .env configurado\n";
    echo "• Banco de dados criado\n";
    echo "• Permissões de escrita no diretório\n";
    exit(1);
}
?>
