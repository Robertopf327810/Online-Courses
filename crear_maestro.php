<?php
if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

if ($argc < 4) {
    fwrite(STDERR, "Uso: php crear_maestro.php <email> <nombre> <contraseña>\n");
    exit(1);
}

include_once "conexion.php";

$email = filter_var($argv[1], FILTER_VALIDATE_EMAIL);
$nombre = trim($argv[2]);
$password_plana = $argv[3];
$rol = "ponente";

if (!$email || $nombre === "" || strlen($password_plana) < 12) {
    fwrite(STDERR, "Proporciona un correo válido, un nombre y una contraseña de al menos 12 caracteres.\n");
    exit(1);
}

$password_hash = password_hash($password_plana, PASSWORD_BCRYPT);

// Verificar si la cuenta ya existe
$stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt_check->bind_param("s", $email);
$stmt_check->execute();
$res = $stmt_check->get_result();

if ($res->num_rows > 0) {
    // Actualizar clave y rol
    $stmt_update = $conexion->prepare("UPDATE usuarios SET password = ?, rol = ? WHERE email = ?");
    $stmt_update->bind_param("sss", $password_hash, $rol, $email);
    $stmt_update->execute();
    echo "La cuenta fue actualizada correctamente.\n";
} else {
    $stmt_insert = $conexion->prepare("INSERT INTO usuarios (nombre, email, password, rol, foto) VALUES (?, ?, ?, ?, 'default.png')");
    $stmt_insert->bind_param("ssss", $nombre, $email, $password_hash, $rol);
    $stmt_insert->execute();
    echo "La cuenta fue creada correctamente.\n";
}
?>