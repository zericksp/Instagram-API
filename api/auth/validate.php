// ========================================
// api/auth/validate.php
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

require_once '../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['valid' => false, 'error' => 'Método não permitido']);
    exit;
}

// Obter token do header Authorization
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'error' => 'Token não fornecido']);
    exit;
}

$token = substr($authHeader, 7); // Remove "Bearer "

try {
    $payload = validateJWT($token);
    
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['valid' => false, 'error' => 'Token inválido']);
        exit;
    }
    
    // Verificar se usuário ainda está ativo
    $pdo = getDatabaseConnection();
    $stmt = $pdo->prepare("
        SELECT u.usr_status, c.cmp_status 
        FROM tbl_inst_users u
        JOIN tbl_inst_companies c ON u.usr_companyId = c.cmp_id
        WHERE u.usr_id = ?
    ");
    $stmt->execute([$payload['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || $user['usr_status'] != 1 || $user['cmp_status'] != 1) {
        http_response_code(401);
        echo json_encode(['valid' => false, 'error' => 'Usuário inativo']);
        exit;
    }
    
    echo json_encode([
        'valid' => true,
        'user_id' => $payload['user_id'],
        'company_id' => $payload['company_id'],
        'cnpj' => $payload['cnpj'],
        'role' => $payload['role']
    ]);
    
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'error' => 'Token inválido']);
}