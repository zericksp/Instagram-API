<?php
/**
 * Instagram Insights - Teste da Interface Web
 * Execute: php test_web_setup.php
 */

echo "🧪 TESTANDO INTERFACE WEB DO INSTAGRAM INSIGHTS\n";
echo str_repeat("=", 60) . "\n\n";

// 1. Verificar se arquivos existem
echo "1. Verificando arquivos necessários...\n";

$requiredFiles = [
    'instagram_setup_class.php',
    'setup_handler.php',
    'instagram_web_setup.html',
    '.env'
];

foreach ($requiredFiles as $file) {
    if (file_exists($file)) {
        echo "   ✅ {$file} encontrado\n";
    } else {
        echo "   ❌ {$file} NÃO encontrado\n";
        if ($file === '.env') {
            echo "      Copie .env.example para .env e configure suas credenciais\n";
        }
    }
}

echo "\n";

// 2. Testar sintaxe dos arquivos PHP
echo "2. Testando sintaxe dos arquivos PHP...\n";

$phpFiles = [
    'instagram_setup_class.php',
    'setup_handler.php'
];

foreach ($phpFiles as $file) {
    if (file_exists($file)) {
        $output = [];
        $returnCode = 0;
        
        exec("php -l {$file} 2>&1", $output, $returnCode);
        
        if ($returnCode === 0) {
            echo "   ✅ {$file} - sintaxe OK\n";
        } else {
            echo "   ❌ {$file} - erro de sintaxe:\n";
            foreach ($output as $line) {
                echo "      {$line}\n";
            }
        }
    }
}

echo "\n";

// 3. Testar setup_handler.php diretamente
echo "3. Testando setup_handler.php diretamente...\n";

// Simular requisição POST para teste
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

// Capturar output
ob_start();

// Simular input JSON para ação de status
$testInput = json_encode(['action' => 'status']);
file_put_contents('php://input', $testInput);

try {
    // Incluir o handler (mas não executá-lo automaticamente)
    include_once 'setup_handler.php';
    echo "   ✅ setup_handler.php carregado sem erros\n";
} catch (Exception $e) {
    echo "   ❌ Erro ao carregar setup_handler.php: " . $e->getMessage() . "\n";
}

$output = ob_get_clean();

echo "\n";

// 4. Testar conexão com banco via classe
echo "4. Testando conexão com banco via InstagramSetup...\n";

try {
    require_once 'instagram_setup_class.php';
    
    $setup = new InstagramSetup();
    echo "   ✅ Classe InstagramSetup inicializada com sucesso\n";
    echo "   ✅ Conexão com banco estabelecida\n";
    
    // Testar uma consulta simples
    $stmt = $setup->pdo->prepare("SELECT COUNT(*) as total FROM instagram_accounts");
    $stmt->execute();
    $result = $stmt->fetch();
    echo "   ✅ Consulta ao banco OK - {$result['total']} contas cadastradas\n";
    
} catch (Exception $e) {
    echo "   ❌ Erro na classe InstagramSetup: " . $e->getMessage() . "\n";
    echo "      Verifique o arquivo .env e se o banco foi criado\n";
}

echo "\n";

// 5. Simular requisição AJAX
echo "5. Simulando requisição AJAX...\n";

function simulateAjaxRequest($action, $data = []) {
    $postData = array_merge(['action' => $action], $data);
    $jsonData = json_encode($postData);
    
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => $jsonData
        ]
    ]);
    
    // Se estiver rodando via servidor web, usar URL completa
    $url = 'setup_handler.php';
    if (isset($_SERVER['SERVER_NAME'])) {
        $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
        $url = $protocol . $_SERVER['SERVER_NAME'] . dirname($_SERVER['REQUEST_URI']) . '/setup_handler.php';
    }
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return ['success' => false, 'error' => 'Falha na requisição'];
    }
    
    $decoded = json_decode($response, true);
    if ($decoded === null) {
        return ['success' => false, 'error' => 'Resposta não é JSON válido', 'raw' => $response];
    }
    
    return $decoded;
}

// Testar ação de status
echo "   Testando ação 'status'...\n";
$statusResult = simulateAjaxRequest('status');

if ($statusResult['success'] ?? false) {
    echo "   ✅ Ação 'status' funcionando\n";
    if (isset($statusResult['accounts'])) {
        echo "   📊 " . count($statusResult['accounts']) . " conta(s) encontrada(s)\n";
    }
    if (isset($statusResult['stats'])) {
        echo "   📈 Estatísticas: {$statusResult['stats']['metricas_hoje']} métricas hoje\n";
    }
} else {
    echo "   ❌ Erro na ação 'status': " . ($statusResult['message'] ?? 'Erro desconhecido') . "\n";
    if (isset($statusResult['raw'])) {
        echo "   📄 Resposta raw: " . substr($statusResult['raw'], 0, 200) . "...\n";
    }
}

echo "\n";

// 6. Verificar logs
echo "6. Verificando logs...\n";

$logDir = __DIR__ . '/logs/';
if (is_dir($logDir)) {
    $logFiles = glob($logDir . 'web_setup_*.log');
    if (!empty($logFiles)) {
        $latestLog = max($logFiles);
        echo "   ✅ Logs encontrados: " . basename($latestLog) . "\n";
        
        $logContent = file_get_contents($latestLog);
        $lines = explode("\n", $logContent);
        $lastLines = array_slice($lines, -5);
        
        echo "   📄 Últimas linhas do log:\n";
        foreach ($lastLines as $line) {
            if (trim($line)) {
                echo "      " . trim($line) . "\n";
            }
        }
    } else {
        echo "   ⚠️  Nenhum log de setup web encontrado\n";
    }
} else {
    echo "   ⚠️  Diretório de logs não existe\n";
}

echo "\n";

// Resumo
echo str_repeat("=", 60) . "\n";
echo "📋 RESUMO DO TESTE\n";
echo str_repeat("=", 60) . "\n";

if (isset($setup) && ($statusResult['success'] ?? false)) {
    echo "✅ Interface web está funcionando corretamente!\n\n";
    echo "🌐 Para testar no browser:\n";
    echo "   1. Acesse: http://localhost/instagram_web_setup.html\n";
    echo "   2. Cole um token válido do Instagram\n";
    echo "   3. Configure suas contas\n\n";
    echo "🔧 Para obter token de teste:\n";
    echo "   1. Acesse: https://developers.facebook.com/tools/explorer/\n";
    echo "   2. Selecione seu app Instagram\n";
    echo "   3. Get User Access Token com permissões necessárias\n\n";
} else {
    echo "⚠️  Interface web tem alguns problemas.\n\n";
    echo "🔧 Passos para corrigir:\n";
    echo "   1. Verifique se todos os arquivos estão presentes\n";
    echo "   2. Configure o arquivo .env corretamente\n";
    echo "   3. Certifique-se que o banco foi criado\n";
    echo "   4. Execute: php test_setup.php\n\n";
}

echo "📚 Arquivos de log para debug:\n";
echo "   • logs/web_setup_" . date('Y-m-d') . ".log\n";
echo "   • logs/instagram_setup_" . date('Y-m-d') . ".log\n\n";

echo "🎉 Teste concluído!\n";
?>
