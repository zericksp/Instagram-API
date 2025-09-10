
    $userToken = $argv[1] ?? '';

    try {
        $setup = new InstagramSetup();
        
        if ($setup->setupAccounts($userToken)) {
            echo "\n🎉 Configuração concluída com sucesso!\n";
            echo "Próximos passos:\n";
            echo "1. Configure o cron: crontab -e\n";
            echo "2. Adicione: */10 * * * * php " . __DIR__ . "/instagram_insights_cron.php\n";
            echo "3. Monitore os logs em: logs/\n\n";
            
            $setup->showSystemStatus();
        } else {
            echo "\n❌ Configuração falhou. Verifique os logs para detalhes.\n";
            exit(1);
        }
        
    } catch (Exception $e) {
        echo "\n💥 Erro crítico: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>