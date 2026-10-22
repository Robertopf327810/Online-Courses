<?php
session_start();
include_once "conexion.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (!empty($email) && !empty($password)) {
        $stmt = $conexion->prepare("SELECT id, nombre, email, password, rol FROM usuarios WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($resultado->num_rows === 1) {
            $usuario = $resultado->fetch_assoc();

            if (password_verify($password, $usuario["password"])) {
                session_regenerate_id(true);
                $_SESSION["usuario_id"] = $usuario["id"];
                $_SESSION["nombre"] = $usuario["nombre"];
                $_SESSION["rol"] = $usuario["rol"];

                header("Location: dashboard.php");
                exit;
            } else {
                $error = "Contraseña incorrecta.";
            }
        } else {
            $error = "El correo no está registrado.";
        }
    } else {
        $error = "Por favor llena todos los campos.";
    }
}

include_once "header.php";
?>

<h1>Iniciar Sesión</h1>

<?php if (!empty($error)): ?>
    <div class="error-box"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="POST" action="login.php">
    <label for="email">Correo electrónico</label>
    <input type="email" id="email" name="email" placeholder="usuario@cursos.com" required>

    <label for="password">Contraseña</label>
    <input type="password" id="password" name="password" placeholder="••••••••" required>

    <button type="submit">Ingresar al Portal</button>
</form>

<?php include_once "footer.php"; ?>