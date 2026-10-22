<?php
$host = "localhost";
$user = "root";
$password = ""; // Déjalo vacío si usas XAMPP/default, o pon tu clave de MySQL
$baseDatos = "cursos_online";

$conexion = new mysqli($host, $user, $password, $baseDatos);

if ($conexion->connect_error) {
    die("Error de conexión: " . $conexion->connect_error);
}
?>