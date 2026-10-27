<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "csrf.php";
require_once "helpers.php";

// Obtener el nombre de la página actual
$pagina_actual = basename($_SERVER['PHP_SELF']);
$es_pagina_auth = in_array($pagina_actual, ['login.php', 'registro.php']);
$mostrar_busqueda_cursos = $pagina_actual === "dashboard.php" && ($_GET["seccion"] ?? "") === "cursos";

$user_avatar = null;
$nav_avatar_src = null;

if (isset($_SESSION["usuario_id"])) {
    include_once "conexion.php";
    $stmt_nav = $conexion->prepare("SELECT foto, nombre, apellidos, rol FROM usuarios WHERE id = ?");
    $stmt_nav->bind_param("i", $_SESSION["usuario_id"]);
    $stmt_nav->execute();
    $res_nav = $stmt_nav->get_result();
    if ($row_nav = $res_nav->fetch_assoc()) {
        $user_avatar = $row_nav;
        $foto_val = $user_avatar['foto'] ?? '';

        if (url_https_valida($foto_val)) {
            $nav_avatar_src = $foto_val;
        } elseif (!empty($foto_val) && basename($foto_val) === $foto_val && file_exists(__DIR__ . "/uploads/" . $foto_val)) {
            $nav_avatar_src = "uploads/" . $foto_val;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AprendePlus - Plataforma de Cursos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="top-navbar">
        <div class="nav-left">
            <a href="dashboard.php" class="nav-brand">
                <div class="logo-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                        <path d="M6 12v5c3 3 9 3 12 0v-5"/>
                    </svg>
                </div>
                <span>Aprende<span class="brand-accent">Plus</span></span>
            </a>

            <?php if ($mostrar_busqueda_cursos): ?>
                <div class="nav-search">
                    <span class="search-icon">🔍</span>
                    <input type="text" id="globalSearch" placeholder="Buscar cursos o lecciones..." onkeyup="filterGlobalCourses()">
                </div>
            <?php endif; ?>
        </div>

        <div class="nav-right">
            <?php if (isset($_SESSION["usuario_id"])): ?>
                <div class="user-dropdown-container">
                    <button class="user-profile-btn" onclick="toggleUserDropdown()">
                        <?php if ($nav_avatar_src): ?>
                            <img src="<?php echo htmlspecialchars($nav_avatar_src); ?>" alt="Perfil" class="nav-avatar">
                        <?php else: ?>
                            <div class="nav-avatar-initial">
                                <?php echo strtoupper(substr($user_avatar['nombre'] ?? $_SESSION['nombre'] ?? 'U', 0, 1)); ?>
                            </div>
                        <?php endif; ?>
                        <div class="user-meta">
                            <span class="user-name"><?php echo htmlspecialchars(trim(($user_avatar['nombre'] ?? $_SESSION['nombre'] ?? '') . ' ' . ($user_avatar['apellidos'] ?? $_SESSION['apellidos'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="user-role-badge"><?php echo ucfirst($_SESSION['rol'] ?? 'Alumno'); ?></span>
                        </div>
                        <span class="arrow-down">▾</span>
                    </button>

                    <div id="userDropdown" class="dropdown-menu">
                        <a href="dashboard.php?seccion=configuracion" class="dropdown-item">⚙️ Configuración de Perfil</a>
                        <div class="dropdown-divider"></div>
                        <form method="POST" action="logout.php">
                            <?php echo csrf_field(); ?>
                            <button type="submit" class="dropdown-item logout">🚪 Cerrar Sesión</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <!-- Ocultar también el botón "Iniciar sesión" cuando se esté dentro de login o registro -->
                <?php if (!$es_pagina_auth): ?>
                    <a href="login.php" class="btn-header-access">Iniciar sesión</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </header>

    <script>
    function toggleUserDropdown() {
        var menu = document.getElementById('userDropdown');
        if (menu) {
            menu.classList.toggle('active');
        }
    }

    window.onclick = function(event) {
        if (!event.target.closest('.user-dropdown-container')) {
            var dropdowns = document.getElementsByClassName("dropdown-menu");
            for (var i = 0; i < dropdowns.length; i++) {
                dropdowns[i].classList.remove('active');
            }
        }
    }

    function filterGlobalCourses() {
        var input = document.getElementById('globalSearch');
        if (!input) return;
        var query = input.value.toLowerCase();
        var cards = document.querySelectorAll('.course-card-interactive');

        cards.forEach(function(card) {
            var title = card.innerText.toLowerCase();
            if (title.includes(query)) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
    }
    </script>

    <main style="flex: 1; display: flex; flex-direction: column;">