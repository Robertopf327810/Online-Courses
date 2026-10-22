<?php
session_start();
if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

include_once "header.php";
?>

<h1>Bienvenido, <?php echo htmlspecialchars($_SESSION["nombre"]); ?></h1>

<div style="text-align: center; margin-top: 15px;">
    <p style="justify-content: center; font-size: 1rem;">
        Rol asignado: <strong style="text-transform: uppercase; color: #4f46e5;"><?php echo htmlspecialchars($_SESSION["rol"]); ?></strong>
    </p>
</div>

<?php include_once "footer.php"; ?>