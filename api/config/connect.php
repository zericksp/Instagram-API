<?php 

//connect.php;
$host = 'www.tiven.com.br';
$db   = 'eladec62_tbs';
$user = 'eladec62_tbs';
$pass = 'Pedimu$-2019';
$charset = 'utf8mb4';

$link = mysqli_connect($host, $user, $pass, $db);
$con = mysqli_connect($host, $user, $pass, $db);
$conn = mysqli_connect($host, $user, $pass, $db);

mysqli_set_charset($con, 'utf8');
ini_set('default_charset','UTF-8');

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die('Erro na conexão: ' . $e->getMessage());
}

?>
