<?php
session_start();
include_once "conexion.php";
require_once "csrf.php";

$error = "";
$exito = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $nombre = trim($_POST["nombre"] ?? "");
    $apellidos = trim($_POST["apellidos"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (!validar_csrf()) {
        $error = "El formulario expiró. Recarga la página e inténtalo de nuevo.";
    } elseif (!empty($nombre) && !empty($apellidos) && !empty($email) && !empty($password) && !empty($confirm_password)) {
        if ($password !== $confirm_password) {
            $error = "Las contraseñas no coinciden.";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Ingresa un correo electrónico válido.";
        } elseif (strlen($password) < 12) {
            $error = "La contraseña debe tener al menos 12 caracteres.";
        } else {
            $stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE email = ?");
            $stmt_check->bind_param("s", $email);
            $stmt_check->execute();
            $res_check = $stmt_check->get_result();

            if ($res_check->num_rows > 0) {
                $error = "Este correo ya está registrado.";
            } else {
                $password_hashed = password_hash($password, PASSWORD_BCRYPT);
                $rol_default = "alumno";

                $stmt_insert = $conexion->prepare("INSERT INTO usuarios (nombre, apellidos, email, password, rol) VALUES (?, ?, ?, ?, ?)");
                $stmt_insert->bind_param("sssss", $nombre, $apellidos, $email, $password_hashed, $rol_default);

                if ($stmt_insert->execute()) {
                    $exito = "¡Cuenta creada con éxito! Ya puedes iniciar sesión.";
                } else {
                    $error = "Error al registrar la cuenta. Inténtalo de nuevo.";
                }
            }
        }
    } else {
        $error = "Por favor completa todos los campos.";
    }
}

include_once "header.php";
?>

<div class="main-wrapper">
    <div class="auth-card">
        <h2>Crear Cuenta</h2>
        <p class="auth-card-desc">Únete a la plataforma para acceder a tus cursos</p>

        <?php if (!empty($error)): ?>
            <div class="error-banner"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if (!empty($exito)): ?>
            <div class="error-banner" style="background-color: #ecfdf5; color: #047857; border-color: #a7f3d0;">
                <?php echo htmlspecialchars($exito); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="registro.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="nombre">Nombre</label>
                <input type="text" class="input-field" id="nombre" name="nombre" autocomplete="given-name" required>
            </div>

            <div class="form-group">
                <label for="apellidos">Apellidos</label>
                <input type="text" class="input-field" id="apellidos" name="apellidos" autocomplete="family-name" required>
            </div>

            <div class="form-group">
                <label for="email">Correo electrónico</label>
                <input type="email" class="input-field" id="email" name="email" placeholder="usuario@cursos.com" required>
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" class="input-field" id="password" name="password" placeholder="••••••••" required>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirmar contraseña</label>
                <input type="password" class="input-field" id="confirm_password" name="confirm_password" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn-primary" style="margin-top: 0.5rem;">Registrarse &rarr;</button>
        </form>

        <div class="divider">
            <span>o</span>
        </div>

        <a href="login.php" class="btn-secondary-outline">¿Ya tienes cuenta? Inicia sesión</a>
    </div>
</div>

<?php include_once "footer.php"; ?>