<?php
// ========================================
// api/auth/login.php
// ========================================

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Obter dados do POST
$input = json_decode(file_get_contents('php://input'), true);

$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email e senha são obrigatórios']);
    exit;
}

try {
    require '../config/connect_pdo.php';
        
    // Buscar usuário com dados da empresa
    $stmt = $pdo->prepare("
        SELECT 
            u.usr_id,
            u.usr_name,
            u.usr_email,
            u.usr_passwordHash,
            u.usr_role,
            u.usr_status,
            c.cmp_id,
            c.cmp_cnpj,
            c.cmp_companyName,
            c.cmp_fantasyName,
            c.cmp_email as company_email,
            c.cmp_plan,
            c.cmp_status as company_status
        FROM tbl_inst_users u
        JOIN tbl_inst_companies c ON u.usr_companyId = c.cmp_id
        WHERE u.usr_email = ? AND u.usr_status = 1 AND c.cmp_status = 1
    ");
    
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Email ou senha incorretos']);
        exit;
    }
    
    // Verificar senha
    if (!password_verify($password, $user['usr_passwordHash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Email ou senha incorretos']);
        exit;
    }
    
    // Gerar token JWT (simplificado)
    $token = generateJWT([
        'user_id' => $user['usr_id'],
        'company_id' => $user['cmp_id'],
        'cnpj' => $user['cmp_cnpj'],
        'role' => $user['usr_role'],
        'exp' => time() + (7 * 24 * 60 * 60) // 7 dias
    ]);
    
    // Atualizar último login
    $updateStmt = $pdo->prepare("UPDATE tbl_inst_users SET usr_lastLogin = NOW() WHERE usr_id = ?");
    $updateStmt->execute([$user['usr_id']]);
    
    // Log da ação
    logUserAction($pdo, $user['usr_id'], $user['cmp_id'], 'login', 'Login realizado com sucesso');
    
    // Preparar resposta
    $response = [
        'success' => true,
        'token' => $token,
        'user' => [
            'id' => intval($user['usr_id']),
            'name' => $user['usr_name'],
            'email' => $user['usr_email'],
            'role' => $user['usr_role'],
        ],
        'company' => [
            'id' => intval($user['cmp_id']),
            'cmp_cnpj' => $user['cmp_cnpj'],
            'cmp_companyName' => $user['cmp_companyName'],
            'cmp_fantasyName' => $user['cmp_fantasyName'],
            'cmp_plan' => $user['cmp_plan'],
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    error_log("Login Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
}