<?php
/**
 * Script para corrigir includes automaticamente
 */

echo "🔧 CORRETOR AUTOMÁTICO DE INCLUDES\n";
echo str_repeat("=", 50) . "\n\n";

// Lista de arquivos que podem ter o include incorreto
$filesToCheck = [
    'setup_handler.php',
    'test_setup.php',
    'test_web_setup.php',
    'instagram_setup_cli.php'
];

$incorrectInclude = 'instagram_setup_script.php';
$correctInclude = 'instagram_setup_class.php';

$fixedFiles = [];
$alreadyCorrect = [];
$notFound = [];

foreach ($filesToCheck as $file) {
    if (!file_exists($file)) {
        $notFound[] = $file;
        continue;
    }
    
    echo "📄 Verificando {$file}...\n";
    
    $content = file_get_contents($file);
    
    // Verificar se tem o include incorreto
    if (strpos($content, $incorrectInclude) !== false) {
        echo "   ❌ Include incorreto encontrado\n";
        
        // Fazer backup
        $backupFile = $file . '.backup.' . date('YmdHis');
        copy($file, $backupFile);
        echo "   💾 Backup criado: {$backupFile}\n";
        
        // Corrigir o include
        $newContent = str_replace($incorrectInclude, $correctInclude, $content);
        file_put_contents($file, $newContent);
        
        echo "   ✅ Include corrigido!\n";
        $fixedFiles[] = $file;
        
    } elseif (strpos($content, $correctInclude) !== false) {
        echo "   ✅ Include já está correto\n";
        $alreadyCorrect[] = $file;
        
    } else {
        echo "   ⚠️  Nenhum include da classe encontrado\n";
    }
    
    echo "\n";
}

// Resumo
echo str_repeat("=", 50) . "\n";
echo "📋 RESUMO\n";
echo str_repeat("=", 50) . "\n";

if (!empty($fixedFiles)) {
    echo "✅ Arquivos corrigidos:\n";
    foreach ($fixedFiles as $file) {
        echo "   - {$file}\n";
    }
    echo "\n";
}

if (!empty($alreadyCorrect)) {
    echo "✅ Arquivos já corretos:\n";
    foreach ($alreadyCorrect as $file) {
        echo "   - {$file}\n";
    }
    echo "\n";
}

if (!empty($notFound)) {
    echo "⚠️  Arquivos não encontrados:\n";
    foreach ($notFound as $file) {
        echo "   - {$file}\n";
    }
    echo "\n";
}

// Verificar se o arquivo principal existe
echo "📋 Verificando arquivo principal da classe...\n";
if (file_exists($correctInclude)) {
    echo "   ✅ {$correctInclude} encontrado\n";
    
    // Verificar se a classe existe no arquivo
    $classContent = file_get_contents($correctInclude);
    if (strpos($classContent, 'class InstagramSetup') !== false) {
        echo "   ✅ Classe InstagramSetup encontrada no arquivo\n";
    } else {
        echo "   ❌ Classe InstagramSetup NÃO encontrada no arquivo\n";
    }
} else {
    echo "   ❌ {$correctInclude} NÃO encontrado\n";
    echo "   👉 Este arquivo é necessário para o funcionamento do sistema\n";
}

echo "\n";

// Teste rápido da classe
echo "🧪 Teste rápido da classe...\n";
try {
    if (file_exists($correctInclude)) {
        require_once $correctInclude;
        
        if (class_exists('InstagramSetup')) {
            echo "   ✅ Classe pode ser carregada sem erros\n";
            
            // Tentar inicializar (apenas se .env existir)
            if (file_exists('.env')) {
                try {
                    $setup = new InstagramSetup();
                    echo "   ✅ Classe pode ser inicializada\n";
                    
                    // Testar um log
                    $setup->log("Teste automático do corretor", "TEST");
                    echo "   ✅ Sistema de log funcionando\n";
                    
                } catch (Exception $e) {
                    echo "   ⚠️  Erro na inicialização: " . $e->getMessage() . "\n";
                    echo "   👉 Verifique o arquivo .env e conexão com banco\n";
                }
            } else {
                echo "   ⚠️  Arquivo .env não encontrado (necessário para inicialização)\n";
            }
        } else {
            echo "   ❌ Classe InstagramSetup não foi definida no arquivo\n";
        }
    }
} catch (Exception $e) {
    echo "   ❌ Erro ao testar classe: " . $e->getMessage() . "\n";
}

echo "\n";

// Instruções finais
if (!empty($fixedFiles)) {
    echo "🎉 CORREÇÕES CONCLUÍDAS!\n\n";
    echo "📝 Próximos passos:\n";
    echo "   1. Teste a interface web: http://localhost/instagram_web_setup.html\n";
    echo "   2. Execute: php test_fixes.php\n";
    echo "   3. Se tudo OK, exclua os arquivos .backup criados\n\n";
} else if (!empty($alreadyCorrect)) {
    echo "✅ TUDO JÁ ESTÁ CORRETO!\n\n";
    echo "📝 Sistema pronto para uso:\n";
    echo "   1. Interface web: http://localhost/instagram_web_setup.html\n";
    echo "   2. Teste: php test_fixes.php\n\n";
} else {
    echo "⚠️  ATENÇÃO: Problemas encontrados\n\n";
    echo "📝 Verifique:\n";
    echo "   1. Se o arquivo instagram_setup_class.php existe\n";
    echo "   2. Se os arquivos PHP estão no diretório correto\n";
    echo "   3. Execute: php test_fixes.php para diagnóstico completo\n\n";
}

echo "🔧 Corretor de includes concluído!\n";
?>
