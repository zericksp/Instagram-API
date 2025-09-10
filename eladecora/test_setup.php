<?php
/**
 * Instagram Insights - Teste de Setup
 * Verifica se o sistema está configurado corretamente
 */

// Incluir classe de setup (arquivo correto)
require_once __DIR__ . '/instagram_setup_class.php';

echo "🧪 TESTANDO CONFIGURAÇÃO DO INSTAGRAM INSIGHTS\n";
echo str_repeat("=", 60) . "\n\n";

try {
    // 1. Testar conexão com banco
    echo "1. Testando conexão com banco de dados...\n";
    
    $setup = new InstagramSetup();
    echo "   ✅ Conexão com banco estabelecida com sucesso\n\n";
    
    // 2. Verificar estrutura do banco
    echo "2. Verificando estrutura do banco...\n";
    
    $stmt = $setup->pdo->prepare("
        SELECT table_name, table_rows 
        FROM information_schema.tables 
        WHERE table_schema = ? 
        AND table_name LIKE 'instagram_%'
        ORDER BY table_name
    ");
    $stmt->execute([$_ENV['DB_NAME'] ?? 'instagram_insights']);
    $tables = $stmt->fetchAll();
    
    if (count($tables) >= 7) {
        echo "   ✅ Estrutura do banco OK (" . count($tables) . " tabelas encontradas)\n";
        foreach ($tables as $table) {
            echo "      - {$table['table_name']} ({$table['table_rows']} registros)\n";
        }
    } else {
        echo "   ❌ Estrutura do banco incompleta\n";
        echo "      Execute: mysql -u usuario -p database < instagram_insights_database.sql\n";
    }
    echo "\n";
    
    // 3. Testar token (se fornecido)
    if (isset($argv[1]) && !empty($argv[1])) {
        echo "3. Testando token de acesso fornecido...\n";
        $token = $argv[1];
        
        $validation = $setup->validateToken($token);
        if ($validation) {
            echo "   ✅ Token válido para usuário: {$validation['name']}\n";
            echo "   📧 Email: " . ($validation['email'] ?? 'N/A') . "\n";
            
            // Buscar contas Instagram
            echo "\n   Buscando contas Instagram conectadas...\n";
            $accounts = $setup->discoverInstagramAccounts($token);
            
            if (!empty($accounts)) {
                echo "   ✅ " . count($accounts) . " conta(s) Instagram encontrada(s):\n";
                foreach ($accounts as $account) {
                    echo "      - @{$account['username']} ({$account['followers_count']} seguidores)\n";
                    echo "        Tipo: {$account['account_type']} | ID: {$account['instagram_user_id']}\n";
                }
            } else {
                echo "   ⚠️  Nenhuma conta Instagram Business encontrada\n";
                echo "      Certifique-se que sua conta Instagram está conectada a uma página Facebook\n";
            }
        } else {
            echo "   ❌ Token inválido ou sem permissões adequadas\n";
            echo "      Permissões necessárias:\n";
            echo "      - instagram_basic\n";
            echo "      - instagram_manage_insights\n";
            echo "      - pages_show_list\n";
            echo "      - pages_read_engagement\n";
        }
    } else {
        echo "3. Para testar token, execute:\n";
        echo "   php test_setup.php SEU_TOKEN_AQUI\n";
    }
    echo "\n";
    
    // 4. Verificar contas já configuradas
    echo "4. Verificando contas já configuradas...\n";
    
    $stmt = $setup->pdo->prepare("
        SELECT 
            account_name,
            user_id,
            account_type,
            follower_count,
            is_active,
            last_collected,
            CASE 
                WHEN last_collected IS NULL THEN 'Nunca coletado'
                WHEN last_collected >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 'Atual'
                WHEN last_collected >= DATE_SUB(NOW(), INTERVAL 6 HOUR) THEN 'Recente'
                ELSE 'Desatualizado'
            END as status
        FROM instagram_accounts 
        ORDER BY is_active DESC, last_collected DESC
    ");
    $stmt->execute();
    $configuredAccounts = $stmt->fetchAll();
    
    if (!empty($configuredAccounts)) {
        echo "   ✅ " . count($configuredAccounts) . " conta(s) configurada(s):\n";
        foreach ($configuredAccounts as $account) {
            $activeIcon = $account['is_active'] ? '🟢' : '🔴';
            echo "      {$activeIcon} @{$account['account_name']} ({$account['follower_count']} seguidores)\n";
            echo "         ID: {$account['user_id']} | Status: {$account['status']}\n";
        }
    } else {
        echo "   ⚠️  Nenhuma conta configurada ainda\n";
        echo "      Use a interface web ou execute:\n";
        echo "      php instagram_setup.php SEU_TOKEN\n";
    }
    echo "\n";
    
    // 5. Verificar dados coletados
    echo "5. Verificando dados coletados recentemente...\n";
    
    $stmt = $setup->pdo->prepare("
        SELECT 
            COUNT(*) as total_insights,
            COUNT(DISTINCT user_id) as contas_com_dados,
            MAX(collected_at) as ultima_coleta
        FROM instagram_profile_insights 
        WHERE collected_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
    $insights = $stmt->fetch();
    
    if ($insights['total_insights'] > 0) {
        echo "   ✅ {$insights['total_insights']} insights coletados nas últimas 24h\n";
        echo "      {$insights['contas_com_dados']} conta(s) com dados\n";
        echo "      Última coleta: {$insights['ultima_coleta']}\n";
    } else {
        echo "   ⚠️  Nenhum insight coletado nas últimas 24h\n";
        echo "      Execute manualmente: php instagram_insights_cron.php\n";
    }
    echo "\n";
    
    // 6. Verificar procedures
    echo "6. Verificando procedures instaladas...\n";
    
    $stmt = $setup->pdo->prepare("
        SELECT routine_name 
        FROM information_schema.routines 
        WHERE routine_schema = ? 
        AND routine_type = 'PROCEDURE'
        AND routine_name LIKE '%nstagram%'
        ORDER BY routine_name
    ");
    $stmt->execute([$_ENV['DB_NAME'] ?? 'instagram_insights']);
    $procedures = $stmt->fetchAll();
    
    if (count($procedures) >= 5) {
        echo "   ✅ " . count($procedures) . " procedures instaladas:\n";
        foreach ($procedures as $proc) {
            echo "      - {$proc['routine_name']}\n";
        }
    } else {
        echo "   ⚠️  Procedures não instaladas ou incompletas\n";
        echo "      Execute: mysql -u usuario -p database < mysql57_procedures.sql\n";
    }
    echo "\n";
    
    // 7. Teste de saúde do sistema
    echo "7. Executando teste de saúde do sistema...\n";
    
    try {
        $stmt = $setup->pdo->prepare("CALL TestSystemHealth()");
        $stmt->execute();
        echo "   ✅ Procedures funcionando corretamente\n";
    } catch (Exception $e) {
        echo "   ⚠️  Erro ao executar procedures: " . $e->getMessage() . "\n";
    }
    echo "\n";
    
    // Resumo final
    echo str_repeat("=", 60) . "\n";
    echo "📋 RESUMO DO TESTE\n";
    echo str_repeat("=", 60) . "\n";
    
    $score = 0;
    $maxScore = 6;
    
    if (count($tables) >= 7) $score++;
    if (!empty($configuredAccounts)) $score++;
    if ($insights['total_insights'] > 0) $score++;
    if (count($procedures) >= 5) $score++;
    
    $percentage = round(($score / $maxScore) * 100);
    
    if ($percentage >= 80) {
        echo "✅ Sistema funcionando bem ({$percentage}%)\n";
        echo "   Pronto para coletar dados automaticamente!\n";
    } elseif ($percentage >= 50) {
        echo "⚠️  Sistema parcialmente configurado ({$percentage}%)\n";
        echo "   Alguns ajustes podem ser necessários.\n";
    } else {
        echo "❌ Sistema precisa de configuração ({$percentage}%)\n";
        echo "   Execute os passos de instalação primeiro.\n";
    }
    
    echo "\n📚 PRÓXIMOS PASSOS:\n";
    if (empty($configuredAccounts)) {
        echo "1. Configure uma conta: php instagram_setup.php TOKEN\n";
    }
    echo "2. Configure o cron: */10 * * * * php instagram_insights_cron.php\n";
    echo "3. Monitore logs: tail -f logs/instagram_insights_*.log\n";
    echo "4. Interface web: http://seu-servidor/instagram_web_setup.html\n\n";
    
} catch (Exception $e) {
    echo "❌ ERRO CRÍTICO: " . $e->getMessage() . "\n";
    echo "\nVerifique:\n";
    echo "- Arquivo .env está configurado corretamente\n";
    echo "- Banco de dados foi criado\n";
    echo "- Usuário tem permissões no MySQL\n";
    exit(1);
}

echo "🎉 Teste concluído!\n";
?>
