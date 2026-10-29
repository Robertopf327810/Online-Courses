<?php
require_once __DIR__ . '/helpers.php';

$host = getenv("DB_HOST") ?: "localhost";
$user = getenv("DB_USER") ?: "root";
$password = getenv("DB_PASSWORD");
if ($password === false) {
    $password = "Sistemas";
}
$baseDatos = getenv("DB_NAME") ?: "cursos_online";

try {
    $conexion = new mysqli($host, $user, $password, $baseDatos);
    $conexion->set_charset("utf8mb4");
} catch (mysqli_sql_exception $error) {
    error_log("Error de conexión a la base de datos: " . $error->getMessage());
    render_error_page(
        "Looks like there's a problem with this site",
        'The server at ' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ' sent an error: 500 Internal Server Error',
        'No fue posible conectar con la base de datos. Revisa la configuración del servidor.',
        'Try Again',
        $_SERVER['REQUEST_URI'] ?: 'index.php'
    );
}
?>