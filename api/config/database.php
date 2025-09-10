<?php
// backend/config/database.php

class Database {
    private static $instance = null;
    private $pdo;
    
    // Configurações do banco - ALTERAR CONFORME SEU AMBIENTE
    private $host = 'www.tiven.com.br';
    private $dbname = 'eladec62_tbs';
    private $username = 'eladec62_tbs';
    private $password = 'Pedimu$-2019';
    private $charset = 'utf8';
    
    private function __construct() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch (PDOException $e) {
            throw new Exception("Erro de conexão com o banco: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }
}
?>
*/
<?php

/**
 * ESTRUTURA DE DIRETÓRIOS NECESSÁRIA:
 * 
 * backend/
 * ├── api/
 * │   ├── insights.php          ← Este arquivo
 * │   ├── followers.php         ← Arquivo criado anteriormente
 * │   └── followers_growth.php  ← Criar se necessário
 * ├── config/
 * │   └── database.php          ← Configuração do banco
 * ├── classes/
 * │   └── InstagramInsightsAPI.php ← Classe principal
 * ├── logs/                     ← Diretório para logs (dar permissão 755)
 * └── cron/
 *     └── collect_followers_data.php ← Script cron
 */

/**
 * EXEMPLOS DE USO:
 * 
 * Account insights:
 * GET /api/insights.php?type=account&metrics=accounts_reached,accounts_engaged,follower_count
 * 
 * Posts insights:
 * GET /api/insights.php?type=posts&metrics=likes,comments,reach&limit=10
 * 
 * Stories insights:
 * GET /api/insights.php?type=stories&limit=20
 * 
 * Audience data:
 * GET /api/insights.php?type=audience
 */

/**
 * FORMATO DE RESPOSTA:
 * 
 * {
 *   "success": true,
 *   "data": {
 *     "type": "account",
 *     "insights": [
 *       {
 *         "name": "accounts_reached",
 *         "values": [
 *           {
 *             "value": 1250,
 *             "end_time": "2025-01-15T10:30:00+00:00"
 *           }
 *         ]
 *       }
 *     ],
 *     "timestamp": "2025-01-15T10:30:00+00:00"
 *   }
 * }
 */

?>