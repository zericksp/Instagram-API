// ========================================
// api/instagram/get_token.php
// ========================================

<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/connect_pdo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Validar autenticação
$authPayload = validateAuthToken();
if (!$authPayload) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token de autenticação inválido']);
    exit;
}

$cnpj = $_GET['cnpj'] ?? $authPayload['cnpj'];

if (!$cnpj) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'CNPJ não fornecido']);
    exit;
}

try {
    require '../config/connect_pdo.php';
    // Buscar tokens ativos para a empresa
    $stmt = $pdo->prepare("
        SELECT 
            itk_id,
            itk_companyName,
            itk_instagramAccountId,
            itk_instagramUsername,
            itk_accessToken,
            itk_userAccessToken,
            itk_expiresIn,
            itk_lastRefresh,
            DATEDIFF(itk_expiresIn, NOW()) as days_until_expiry
        FROM tbl_inst_instagramToken
        WHERE itk_cnpj = ? AND itk_status = 1 AND itk_expiresIn > NOW()
        ORDER BY itk_lastRefresh DESC
    ");
    
    $stmt->execute([$cnpj]);
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($tokens)) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Nenhum token ativo encontrado para esta empresa',
            'cnpj' => $cnpj
        ]);
        exit;
    }
    
    // Log do acesso
    logUserAction($pdo, $authPayload['user_id'], $authPayload['company_id'], 'token_access', "Token acessado para CNPJ: $cnpj");
    
    echo json_encode([
        'success' => true,
        'data' => $tokens,
        'count' => count($tokens)
    ]);
    
} catch (Exception $e) {
    error_log("Get Token Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}