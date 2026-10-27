<?php
session_start();
include_once "conexion.php";
require_once "csrf.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!validar_csrf()) {
        $error = "El formulario expiró. Recarga la página e inténtalo de nuevo.";
    }

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($error === "" && !empty($email) && !empty($password)) {
        $stmt = $conexion->prepare("SELECT id, nombre, apellidos, email, password, rol FROM usuarios WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($resultado->num_rows === 1) {
            $usuario = $resultado->fetch_assoc();

            if (password_verify($password, $usuario["password"])) {
                session_regenerate_id(true);
                $_SESSION["usuario_id"] = $usuario["id"];
                $_SESSION["nombre"] = trim($usuario["nombre"] . " " . ($usuario["apellidos"] ?? ""));
                $_SESSION["apellidos"] = $usuario["apellidos"] ?? "";
                $_SESSION["rol"] = $usuario["rol"];

                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Contraseña incorrecta.";
            }
        } else {
            $error = "El correo no está registrado.";
        }
    } elseif ($error === "") {
        $error = "Por favor ingresa tu correo y contraseña.";
    }
}

include_once "header.php";
?>

<div class="main-wrapper">
    <div class="auth-card">
        <h2>Bienvenido(a)</h2>
        <p class="auth-card-desc">Inicia sesión para continuar en AprendePlus</p>

        <?php if (!empty($error)): ?>
            <div class="error-banner"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label for="email">Correo electrónico</label>
                <input type="email" class="input-field" id="email" name="email" placeholder="usuario@cursos.com" required>
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" class="input-field" id="password" name="password" placeholder="••••••••" required>
            </div>

            <div class="form-options">
                <label class="remember-me">
                    <input type="checkbox" name="remember"> Recordarme
                </label>
                <a href="#" class="forgot-link">¿Olvidaste tu contraseña?</a>
            </div>

            <button type="submit" class="btn-primary-glow" style="width: 100%; border: none; padding: 12px; font-size: 0.95rem; cursor: pointer;">Iniciar sesión &rarr;</button>
        </form>

        <div class="divider">
            <span>o</span>
        </div>

        <a href="registro.php" class="btn-secondary-outline">Crear una cuenta</a>
    </div>
</div>

<?php include_once "footer.php"; ?>