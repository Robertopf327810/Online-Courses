<?php
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
    http_response_code(500);
    exit("No fue posible conectar con la base de datos. Revisa la configuración del servidor.");
}
?>