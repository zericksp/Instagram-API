#!/usr/bin/env php
<?php
/**
 * Instagram Insights - Script de Configuração CLI
 * Execute: php instagram_setup_cli.php [USER_ACCESS_TOKEN]
 */

// Incluir apenas a classe (sem execução automática)
require_once __DIR__ . '/instagram_setup_class.php';

// Verificar se está sendo executado via CLI
if (php_sapi_name() !== 'cli') {
    echo "Este script deve ser executado via linha de comando.\n";
    exit(1);
}

// Verificar argumentos
if ($argc < 2) {
    echo "📱 Instagram Insights - Configuração via CLI\n";
    echo "==========================================\n\n";
    echo "Uso: php instagram_setup_cli.php [USER_ACCESS_TOKEN]\n\n";
    echo "Para obter o token:\n";
    echo "1. Acesse: https://developers.facebook.com/tools/explorer/\n";
    echo "2. Selecione seu app\n";
    echo "3. Get User Access Token com permissões:\n";
    echo "   • instagram_basic\n";
    echo "   • instagram_manage_insights\n";
    echo "   • pages_show_list\n";
    echo "   • pages_read_engagement\n\n";
    echo "4. Execute: php instagram_setup_cli.php [TOKEN]\n\n";
    echo "💡 Alternativamente, use a interface web:\n";
    echo "   http://seu-servidor.com/instagram_web_setup.html\n\n";
    exit(1);
}

$userToken = $argv[1];

if (empty($userToken)) {
    echo "❌ Token não pode estar vazio.\n";
    exit(1);
}

try {
    echo "🚀 CONFIGURAÇÃO DO INSTAGRAM INSIGHTS\n";
    echo "====================================\n\n";
    
    echo "📋 Inicializando sistema...\n";
    $setup = new InstagramSetup();
    
    echo "🔧 Configurando contas com token fornecido...\n\n";
    
    if ($setup->setupAccounts($userToken)) {
        echo "\n🎉 CONFIGURAÇÃO CONCLUÍDA COM SUCESSO!\n";
        echo "=====================================\n\n";
        
        echo "📋 Próximos passos:\n";
        echo "1. Configure o cron para coleta automática:\n";
        echo "   crontab -e\n";
        echo "   Adicione: */10 * * * * php " . __DIR__ . "/instagram_insights_cron.php\n\n";
        
        echo "2. Configure detecção de anomalias:\n";
        echo "   Adicione: 0 * * * * php " . __DIR__ . "/analyze_anomalies.php\n\n";
        
        echo "3. Monitore os logs:\n";
        echo "   tail -f logs/instagram_insights_" . date('Y-m-d') . ".log\n\n";
        
        echo "4. Teste a coleta manualmente:\n";
        echo "   php instagram_insights_cron.php\n\n";
        
        echo "📊 Exibindo status atual do sistema:\n";
        echo "===================================\n";
        $setup->showSystemStatus();
        
        echo "\n✅ Sistema pronto para uso!\n";
        echo "💡 Para interface web: http://seu-servidor.com/instagram_web_setup.html\n\n";
        
    } else {
        echo "\n❌ CONFIGURAÇÃO FALHOU\n";
        echo "=====================\n\n";
        echo "Possíveis causas:\n";
        echo "• Token inválido ou expirado\n";
        echo "• Permissões insuficientes\n";
        echo "• Conta Instagram não é Business/Creator\n";
        echo "• Conta não conectada a página Facebook\n\n";
        echo "Verifique os logs para mais detalhes:\n";
        echo "tail -f logs/instagram_setup_" . date('Y-m-d') . ".log\n\n";
        exit(1);
    }
    
} catch (Exception $e) {
    echo "\n💥 ERRO CRÍTICO\n";
    echo "===============\n";
    echo "Erro: " . $e->getMessage() . "\n\n";
    echo "Verifique:\n";
    echo "• Arquivo .env está configurado corretamente\n";
    echo "• Banco de dados foi criado e está acessível\n";
    echo "• Usuário MySQL tem permissões adequadas\n";
    echo "• Extensões PHP estão instaladas (pdo, pdo_mysql, curl, json)\n\n";
    exit(1);
}
?>