<?php
session_start();
require_once "csrf.php";
if ($_SERVER["REQUEST_METHOD"] !== "POST" || !validar_csrf()) {
    http_response_code(400);
    exit("La solicitud de cierre de sesión no es válida.");
}

$_SESSION = [];
session_destroy();
header("Location: login.php");
exit;