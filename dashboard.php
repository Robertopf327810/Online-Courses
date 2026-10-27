<?php
session_start();
include_once "conexion.php";
require_once "csrf.php";
require_once "helpers.php";

// 1. Protección de sesión
if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

$rol_usuario = $_SESSION["rol"] ?? "alumno";
$nombre_usuario = $_SESSION["nombre"] ?? "Usuario";
$apellidos_usuario = $_SESSION["apellidos"] ?? "";
$id_usuario = $_SESSION["usuario_id"];
$stmt_profile = $conexion->prepare("SELECT nombre, apellidos, descripcion FROM usuarios WHERE id = ?");
$stmt_profile->bind_param("i", $id_usuario);
$stmt_profile->execute();
$perfil_usuario = $stmt_profile->get_result()->fetch_assoc();
if ($perfil_usuario) {
    $nombre_usuario = $perfil_usuario["nombre"];
    $apellidos_usuario = $perfil_usuario["apellidos"] ?? "";
    $descripcion_perfil = $perfil_usuario["descripcion"] ?? "";
    $_SESSION["nombre"] = trim($nombre_usuario . " " . $apellidos_usuario);
    $_SESSION["apellidos"] = $apellidos_usuario;
}

$seccion = $_GET['seccion'] ?? 'dashboard';
$mensaje_exito = "";
$error_operacion = "";

// Función para procesar archivo subido o URL de foto
function procesar_foto_input($file_key, $url_key, $user_id, &$error) {
    if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] !== UPLOAD_ERR_NO_FILE) {
        $archivo = $_FILES[$file_key];
        if ($archivo['error'] !== UPLOAD_ERR_OK || $archivo['size'] > 3 * 1024 * 1024) {
            $error = "La imagen no se pudo subir o supera el límite de 3 MB.";
            return null;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        $extensiones = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensiones[$mime])) {
            $error = "El archivo debe ser una imagen JPG, PNG o WebP válida.";
            return null;
        }

        $directorio = __DIR__ . "/uploads";
        if (!is_dir($directorio) && !mkdir($directorio, 0755, true) && !is_dir($directorio)) {
            $error = "No se pudo preparar el directorio para guardar imágenes.";
            return null;
        }

        $nombre_foto = "user_" . (int) $user_id . "_" . bin2hex(random_bytes(8)) . "." . $extensiones[$mime];
        if (!move_uploaded_file($archivo['tmp_name'], $directorio . "/" . $nombre_foto)) {
            $error = "No se pudo guardar la imagen subida.";
            return null;
        }

        return $nombre_foto;
    }

    $url_foto = trim($_POST[$url_key] ?? "");
    if ($url_foto !== "") {
        $es_url_segura = filter_var($url_foto, FILTER_VALIDATE_URL) &&
            strtolower((string) parse_url($url_foto, PHP_URL_SCHEME)) === "https";
        if (!$es_url_segura) {
            $error = "La URL de la imagen debe ser una dirección HTTPS válida.";
            return null;
        }
        return $url_foto;
    }

    return null;
}

function render_course_hover_details($curso, $instructor) {
    ?>
    <aside class="course-hover-panel" aria-label="Detalles del curso">
        <h3><?php echo htmlspecialchars($curso["titulo"] ?? "", ENT_QUOTES, "UTF-8"); ?></h3>
        <p><?php echo htmlspecialchars(trim($curso["descripcion"] ?? "") ?: "Este curso aún no tiene descripción.", ENT_QUOTES, "UTF-8"); ?></p>
        <dl>
            <div>
                <dt>Categoría</dt>
                <dd><?php echo htmlspecialchars($curso["categoria"] ?? "Sin categoría", ENT_QUOTES, "UTF-8"); ?></dd>
            </div>
            <div>
                <dt>Nivel</dt>
                <dd><?php echo htmlspecialchars($curso["nivel"] ?? "No especificado", ENT_QUOTES, "UTF-8"); ?></dd>
            </div>
            <div>
                <dt>Docente</dt>
                <dd><?php echo htmlspecialchars(trim($instructor) ?: "Sin docente asignado", ENT_QUOTES, "UTF-8"); ?></dd>
            </div>
        </dl>
    </aside>
    <?php
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && !validar_csrf()) {
    http_response_code(400);
    exit("La solicitud expiró o no es válida. Recarga la página e inténtalo de nuevo.");
}

// 2. PROCESAR: SuperAdmin edita usuario
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "update_user_admin") {
    if ($rol_usuario !== "superadmin") {
        http_response_code(403);
        exit("No tienes permiso para realizar esta acción.");
    } else {
        $target_id = filter_var($_POST["user_id"] ?? null, FILTER_VALIDATE_INT);
        $nuevo_rol = $_POST["nuevo_rol"] ?? "";
        $roles_permitidos = ["superadmin", "director", "ponente", "alumno"];

        if (!$target_id || !in_array($nuevo_rol, $roles_permitidos, true)) {
            $error_operacion = "El usuario o el rol seleccionado no es válido.";
        } else {
            $stmt_check = $conexion->prepare("SELECT id FROM usuarios WHERE id = ?");
            $stmt_check->bind_param("i", $target_id);
            $stmt_check->execute();
            if (!$stmt_check->get_result()->fetch_assoc()) {
                $error_operacion = "No se encontró el usuario que intentas actualizar.";
            } else {
                $stmt = $conexion->prepare("UPDATE usuarios SET rol = ? WHERE id = ?");
                $stmt->bind_param("si", $nuevo_rol, $target_id);
                $stmt->execute();
            }
        }

        if ($error_operacion === "") {
            $foto_result = procesar_foto_input('foto_admin', 'foto_admin_url', $target_id, $error_operacion);
            if ($foto_result !== null) {
                $stmt_f = $conexion->prepare("UPDATE usuarios SET foto = ? WHERE id = ?");
                $stmt_f->bind_param("si", $foto_result, $target_id);
                $stmt_f->execute();
            }
        }

        if ($error_operacion === "") {
            $mensaje_exito = "Usuario actualizado correctamente.";
        }
    }
}

// 3. PROCESAR: Actualizar perfil propio
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "update_config") {
    $nuevo_nombre = trim($_POST["nombre"] ?? "");
    $nuevos_apellidos = trim($_POST["apellidos"] ?? "");
    $nueva_descripcion = trim($_POST["descripcion"] ?? "");
    $password_nueva = $_POST["password_nueva"] ?? "";
    $confirmar_password = $_POST["confirmar_password"] ?? "";

    if (strlen($password_nueva) > 0 && strlen($password_nueva) < 12) {
        $error_operacion = "La nueva contraseña debe tener al menos 12 caracteres.";
    } elseif ($password_nueva !== $confirmar_password) {
        $error_operacion = "La confirmación de contraseña no coincide.";
    } elseif (empty($nuevo_nombre)) {
        $error_operacion = "El nombre no puede estar vacío.";
    } elseif (strlen($nuevo_nombre) > 120 || strlen($nuevos_apellidos) > 120) {
        $error_operacion = "El nombre y los apellidos no pueden superar 120 caracteres.";
    } elseif (strlen($nueva_descripcion) > 2000) {
        $error_operacion = "La descripción no puede superar 2000 caracteres.";
    } else {
        $stmt = $conexion->prepare("UPDATE usuarios SET nombre = ?, apellidos = ?, descripcion = ? WHERE id = ?");
        $stmt->bind_param("sssi", $nuevo_nombre, $nuevos_apellidos, $nueva_descripcion, $id_usuario);
        $stmt->execute();
        $_SESSION["nombre"] = trim($nuevo_nombre . " " . $nuevos_apellidos);
        $_SESSION["apellidos"] = $nuevos_apellidos;
        $nombre_usuario = $nuevo_nombre;
        $apellidos_usuario = $nuevos_apellidos;
        $descripcion_perfil = $nueva_descripcion;

        if (!empty($password_nueva)) {
            $pass_hash = password_hash($password_nueva, PASSWORD_BCRYPT);
            $stmt_p = $conexion->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
            $stmt_p->bind_param("si", $pass_hash, $id_usuario);
            $stmt_p->execute();
        }

        $foto_result = procesar_foto_input('foto', 'foto_url', $id_usuario, $error_operacion);
        if ($foto_result !== null) {
            $stmt_f = $conexion->prepare("UPDATE usuarios SET foto = ? WHERE id = ?");
            $stmt_f->bind_param("si", $foto_result, $id_usuario);
            $stmt_f->execute();
        }

        if ($error_operacion === "") {
            $mensaje_exito = "Perfil actualizado con éxito.";
        }
    }
}

// 4. PROCESAR: Guardar/Editar Lección
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "save_lesson") {
    if ($rol_usuario !== "ponente" && $rol_usuario !== "superadmin") {
        http_response_code(403);
        exit("No tienes permiso para realizar esta acción.");
    } else {
        $curso_id = filter_input(INPUT_POST, "curso_id", FILTER_VALIDATE_INT);
        $titulo_leccion = trim($_POST["titulo_leccion"] ?? "");
        $contenido_leccion = trim($_POST["contenido_leccion"] ?? "");
        $material_url = trim($_POST["material_url"] ?? "");

        if ($curso_id && $titulo_leccion !== "") {
            if ($material_url !== "" && !url_https_valida($material_url)) {
                $error_operacion = "El material debe tener una URL HTTPS válida.";
            } elseif ($rol_usuario === "ponente") {
                $stmt = $conexion->prepare("SELECT id FROM cursos WHERE id = ? AND instructor_id = ?");
                $stmt->bind_param("ii", $curso_id, $id_usuario);
            } else {
                $stmt = $conexion->prepare("SELECT id FROM cursos WHERE id = ?");
                $stmt->bind_param("i", $curso_id);
            }

            if ($error_operacion === "") {
                $stmt->execute();
                $curso_autorizado = $stmt->get_result()->fetch_assoc();

                if ($curso_autorizado) {
                    $stmt_l = $conexion->prepare(
                        "INSERT INTO lecciones (curso_id, titulo, contenido, video_url) VALUES (?, ?, ?, ?)"
                    );
                    $stmt_l->bind_param("isss", $curso_id, $titulo_leccion, $contenido_leccion, $material_url);
                    $stmt_l->execute();
                    $mensaje_exito = "Lección guardada correctamente.";
                } else {
                    $error_operacion = "No se encontró el curso o no tienes permiso para editarlo.";
                }
            }
        } else {
            $error_operacion = "Selecciona un curso y escribe el título de la lección.";
        }
    }
}

include_once "header.php";
?>

<div class="dashboard-layout">
    <aside class="sidebar">
        <ul class="sidebar-nav">
            <li><a href="dashboard.php?seccion=dashboard" class="<?php echo ($seccion === 'dashboard') ? 'active' : ''; ?>">📊 Dashboard</a></li>

            <?php if ($rol_usuario === "superadmin"): ?>
                <li><a href="dashboard.php?seccion=usuarios" class="<?php echo ($seccion === 'usuarios') ? 'active' : ''; ?>">👥 Usuarios</a></li>
                <li><a href="dashboard.php?seccion=roles" class="<?php echo ($seccion === 'roles') ? 'active' : ''; ?>">🛡️ Roles</a></li>
            <?php endif; ?>

            <?php if ($rol_usuario === "director"): ?>
                <li><a href="dashboard.php?seccion=docentes" class="<?php echo ($seccion === 'docentes') ? 'active' : ''; ?>">👨‍🏫 Cuerpo Docente</a></li>
            <?php endif; ?>

            <?php if ($rol_usuario === "ponente"): ?>
                <li><a href="dashboard.php?seccion=mis_alumnos" class="<?php echo ($seccion === 'mis_alumnos') ? 'active' : ''; ?>">👨‍🎓 Mis Alumnos</a></li>
            <?php endif; ?>

            <li><a href="dashboard.php?seccion=cursos" class="<?php echo ($seccion === 'cursos') ? 'active' : ''; ?>">📚 <?php
                if ($rol_usuario === 'director') echo 'Todos los Cursos';
                elseif ($rol_usuario === 'ponente') echo 'Gestión de Cursos';
                else echo 'Mis Cursos';
            ?></a></li>

            <li><a href="dashboard.php?seccion=reportes" class="<?php echo ($seccion === 'reportes') ? 'active' : ''; ?>">📈 Reportes</a></li>
        </ul>
    </aside>

    <main class="content-area">

        <?php if (!empty($mensaje_exito)): ?>
            <div style="background: #ecfdf5; color: #047857; padding: 12px 16px; border-radius: 10px; margin-bottom: 1.5rem; font-weight: 600; border: 1px solid #a7f3d0;">
                <?php echo htmlspecialchars($mensaje_exito); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error_operacion)): ?>
            <div class="error-banner"><?php echo htmlspecialchars($error_operacion, ENT_QUOTES, "UTF-8"); ?></div>
        <?php endif; ?>

        <!-- VISTA SUPERADMIN -->
        <?php if ($rol_usuario === "superadmin" && $seccion === 'dashboard'): ?>
            <?php
            $admin_resumen = $conexion->query(
                "SELECT
                    (SELECT COUNT(*) FROM usuarios) AS usuarios,
                    (SELECT COUNT(*) FROM usuarios WHERE rol = 'alumno') AS alumnos,
                    (SELECT COUNT(*) FROM cursos) AS cursos,
                    (SELECT COUNT(*) FROM lecciones) AS lecciones"
            )->fetch_assoc();
            $admin_actividad = $conexion->query(
                "SELECT c.id, c.titulo, c.descripcion, c.categoria, c.nivel, COUNT(l.id) AS lecciones,
                        TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellidos, ''))) AS instructor
                 FROM cursos c
                 LEFT JOIN usuarios u ON c.instructor_id = u.id
                 LEFT JOIN lecciones l ON l.curso_id = c.id
                 GROUP BY c.id, c.titulo, c.descripcion, c.categoria, c.nivel, u.nombre, u.apellidos
                 ORDER BY c.id DESC
                 LIMIT 5"
            );
            ?>
            <section class="admin-dashboard">
                <div class="admin-welcome">
                    <div>
                        <span class="section-eyebrow">ADMINISTRACIÓN DE APRENDEPLUS</span>
                        <h1>Panel de administración</h1>
                        <p>Consulta el estado de la plataforma y accede rápidamente a su gestión.</p>
                    </div>
                    <span class="admin-welcome-mark" aria-hidden="true">🛡️</span>
                </div>

                <div class="report-stats-grid admin-quick-stats">
                    <article class="report-stat-card">
                        <span class="report-stat-icon">👥</span>
                        <div><span>Usuarios</span><strong><?php echo number_format((int) $admin_resumen["usuarios"]); ?></strong></div>
                    </article>
                    <article class="report-stat-card">
                        <span class="report-stat-icon">🎓</span>
                        <div><span>Alumnos</span><strong><?php echo number_format((int) $admin_resumen["alumnos"]); ?></strong></div>
                    </article>
                    <article class="report-stat-card">
                        <span class="report-stat-icon">📚</span>
                        <div><span>Cursos</span><strong><?php echo number_format((int) $admin_resumen["cursos"]); ?></strong></div>
                    </article>
                    <article class="report-stat-card">
                        <span class="report-stat-icon">📝</span>
                        <div><span>Lecciones</span><strong><?php echo number_format((int) $admin_resumen["lecciones"]); ?></strong></div>
                    </article>
                </div>

                <div class="admin-shortcuts">
                    <a href="dashboard.php?seccion=usuarios" class="admin-shortcut-card">
                        <span class="report-stat-icon">👤</span>
                        <span><strong>Gestionar usuarios</strong><small>Revisa cuentas, fotos y permisos.</small></span>
                        <span class="report-course-arrow" aria-hidden="true">&rarr;</span>
                    </a>
                    <a href="dashboard.php?seccion=roles" class="admin-shortcut-card">
                        <span class="report-stat-icon">🛡️</span>
                        <span><strong>Ver roles</strong><small>Consulta los perfiles de acceso.</small></span>
                        <span class="report-course-arrow" aria-hidden="true">&rarr;</span>
                    </a>
                    <a href="dashboard.php?seccion=cursos" class="admin-shortcut-card">
                        <span class="report-stat-icon">📖</span>
                        <span><strong>Explorar cursos</strong><small>Revisa cursos y contenidos publicados.</small></span>
                        <span class="report-course-arrow" aria-hidden="true">&rarr;</span>
                    </a>
                </div>

                <div class="reports-content-card admin-activity-card">
                    <div class="reports-content-heading">
                        <div>
                            <h2>Cursos recientes</h2>
                            <p>Resumen del contenido disponible.</p>
                        </div>
                        <a class="reports-link" href="dashboard.php?seccion=cursos">Ver catálogo &rarr;</a>
                    </div>
                    <?php if ($admin_actividad->num_rows > 0): ?>
                        <div class="report-course-list">
                            <?php while ($curso_admin = $admin_actividad->fetch_assoc()): ?>
                                <div class="report-course-item">
                                    <a class="report-course-row" href="ver_curso.php?id=<?php echo (int) $curso_admin["id"]; ?>">
                                        <span class="report-course-icon">📚</span>
                                        <span class="report-course-name">
                                            <strong><?php echo htmlspecialchars($curso_admin["titulo"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            <small><?php echo htmlspecialchars($curso_admin["categoria"], ENT_QUOTES, "UTF-8"); ?> · <?php echo htmlspecialchars(trim($curso_admin["instructor"]) ?: "Sin docente asignado", ENT_QUOTES, "UTF-8"); ?></small>
                                        </span>
                                        <span class="report-course-lessons"><?php echo number_format((int) $curso_admin["lecciones"]); ?> lecciones</span>
                                        <span class="report-course-arrow" aria-hidden="true">&rarr;</span>
                                    </a>
                                    <?php render_course_hover_details($curso_admin, $curso_admin["instructor"]); ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="reports-empty-state">
                            <span aria-hidden="true">📚</span>
                            <h3>Aún no hay cursos publicados</h3>
                            <p>Cuando se publiquen cursos, su actividad aparecerá aquí.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

        <?php elseif ($rol_usuario === "superadmin" && $seccion === 'usuarios'): ?>
            <?php
            $total_usuarios = $conexion->query("SELECT COUNT(*) as total FROM usuarios")->fetch_assoc()['total'];
            $total_alumnos = $conexion->query("SELECT COUNT(*) as total FROM usuarios WHERE rol = 'alumno'")->fetch_assoc()['total'];
            $total_ponentes = $conexion->query("SELECT COUNT(*) as total FROM usuarios WHERE rol = 'ponente'")->fetch_assoc()['total'];
            $total_directores = $conexion->query("SELECT COUNT(*) as total FROM usuarios WHERE rol = 'director'")->fetch_assoc()['total'];
            $query_users = $conexion->query("SELECT id, nombre, apellidos, email, rol, foto FROM usuarios ORDER BY id DESC");
            ?>

            <div class="page-header">
                <h1>Gestión de usuarios y roles</h1>
                <p>Administra los usuarios de la plataforma, asigna fotos, roles y permisos.</p>
            </div>

            <div class="stats-grid">
                <div class="stat-card"><div class="stat-icon total">👥</div><div class="stat-data"><span>Total usuarios</span><h3><?php echo number_format($total_usuarios); ?></h3></div></div>
                <div class="stat-card"><div class="stat-icon alumnos">🎓</div><div class="stat-data"><span>Alumnos</span><h3><?php echo number_format($total_alumnos); ?></h3></div></div>
                <div class="stat-card"><div class="stat-icon docentes">👨‍🏫</div><div class="stat-data"><span>Docentes</span><h3><?php echo number_format($total_ponentes); ?></h3></div></div>
                <div class="stat-card"><div class="stat-icon directores">📋</div><div class="stat-data"><span>Directores</span><h3><?php echo number_format($total_directores); ?></h3></div></div>
            </div>

            <div class="table-card">
                <div class="table-toolbar">
                    <div class="search-bar">🔍 <input type="text" placeholder="Buscar usuario..."></div>
                    <a href="registro.php" class="btn-primary-glow" style="text-decoration:none;">+ Nuevo usuario</a>
                </div>

                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Email</th>
                            <th>Rol actual</th>
                            <th style="text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($u = $query_users->fetch_assoc()): ?>
                            <?php
                                $foto_src = url_https_valida($u['foto'] ?? null)
                                    ? $u['foto']
                                    : ((!empty($u['foto']) && basename($u['foto']) === $u['foto'] && file_exists(__DIR__ . "/uploads/" . $u['foto'])) ? "uploads/" . $u['foto'] : null);
                            ?>
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <?php if ($foto_src): ?>
                                            <img src="<?php echo htmlspecialchars($foto_src); ?>" class="avatar" style="object-fit: cover;">
                                        <?php else: ?>
                                            <div class="avatar"><?php echo strtoupper(substr($u['nombre'], 0, 1)); ?></div>
                                        <?php endif; ?>
                                        <strong><?php echo htmlspecialchars(trim($u['nombre'] . ' ' . ($u['apellidos'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($u['email']); ?></td>
                                <td><span class="badge-role <?php echo $u['rol']; ?>">● <?php echo ucfirst($u['rol']); ?></span></td>
                                <td style="text-align: right;">
                                    <button onclick="openDrawer(<?php echo $u['id']; ?>, <?php echo htmlspecialchars(json_encode(trim($u['nombre'] . ' ' . ($u['apellidos'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>, '<?php echo $u['rol']; ?>')" style="background:none; border:none; cursor:pointer; font-size:1.1rem;">✏️</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($rol_usuario === "superadmin" && $seccion === 'roles'): ?>
            <?php
            $conteos_roles = [];
            $resultado_roles = $conexion->query("SELECT rol, COUNT(*) AS total FROM usuarios GROUP BY rol");
            while ($fila_rol = $resultado_roles->fetch_assoc()) {
                $conteos_roles[$fila_rol["rol"]] = (int) $fila_rol["total"];
            }
            $roles_plataforma = [
                "superadmin" => ["nombre" => "Superadministrador", "descripcion" => "Administra usuarios y consulta la configuración general de la plataforma.", "icono" => "🛡️"],
                "director" => ["nombre" => "Director", "descripcion" => "Accede a las herramientas de dirección y supervisión.", "icono" => "📋"],
                "ponente" => ["nombre" => "Docente", "descripcion" => "Gestiona sus cursos y el contenido de sus lecciones.", "icono" => "👨‍🏫"],
                "alumno" => ["nombre" => "Alumno", "descripcion" => "Explora el catálogo y accede a las experiencias de aprendizaje.", "icono" => "🎓"],
            ];
            ?>
            <section class="admin-dashboard">
                <div class="page-header">
                    <h1>Roles de la plataforma</h1>
                    <p>Consulta los perfiles de acceso disponibles y cuántas cuentas tiene cada uno.</p>
                </div>
                <div class="admin-role-grid">
                    <?php foreach ($roles_plataforma as $clave_rol => $datos_rol): ?>
                        <article class="admin-role-card">
                            <span class="report-stat-icon" aria-hidden="true"><?php echo $datos_rol["icono"]; ?></span>
                            <div>
                                <span class="admin-role-count"><?php echo number_format($conteos_roles[$clave_rol] ?? 0); ?> usuarios</span>
                                <h2><?php echo htmlspecialchars($datos_rol["nombre"], ENT_QUOTES, "UTF-8"); ?></h2>
                                <p><?php echo htmlspecialchars($datos_rol["descripcion"], ENT_QUOTES, "UTF-8"); ?></p>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <div class="admin-role-note">
                    <strong>¿Necesitas cambiar un acceso?</strong>
                    <span>Abre la gestión de usuarios para asignar el rol adecuado a una cuenta.</span>
                    <a class="reports-link" href="dashboard.php?seccion=usuarios">Gestionar usuarios &rarr;</a>
                </div>
            </section>

        <?php elseif ($rol_usuario === "alumno" && $seccion === 'dashboard'): ?>
            <?php
            $total_cursos_alumno = (int) $conexion->query("SELECT COUNT(*) AS total FROM cursos")->fetch_assoc()["total"];
            $total_lecciones_alumno = (int) $conexion->query("SELECT COUNT(*) AS total FROM lecciones")->fetch_assoc()["total"];
            $cursos_alumno = $conexion->query(
                "SELECT c.id, c.titulo, c.descripcion, c.categoria, c.nivel, c.foto_curso, c.banner_curso,
                        TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellidos, ''))) AS instructor_nombre,
                        COUNT(l.id) AS total_lecciones
                 FROM cursos c
                 LEFT JOIN usuarios u ON c.instructor_id = u.id
                 LEFT JOIN lecciones l ON l.curso_id = c.id
                 GROUP BY c.id, c.titulo, c.descripcion, c.categoria, c.nivel,
                          c.foto_curso, c.banner_curso, u.nombre, u.apellidos
                 ORDER BY c.id DESC
                 LIMIT 3"
            );
            ?>
            <section class="student-dashboard">
                <div class="student-welcome">
                    <div>
                        <span class="section-eyebrow">TU ESPACIO DE APRENDIZAJE</span>
                        <h1>¡Hola, <?php echo htmlspecialchars($nombre_usuario, ENT_QUOTES, "UTF-8"); ?>!</h1>
                        <p>Continúa aprendiendo a tu ritmo. Explora tus cursos y encuentra tu próximo tema.</p>
                        <a class="btn-primary-glow" href="dashboard.php?seccion=cursos">Explorar todos los cursos &rarr;</a>
                    </div>
                    <div class="student-welcome-mark" aria-hidden="true">🎓</div>
                </div>

                <div class="report-stats-grid student-quick-stats">
                    <article class="report-stat-card">
                        <span class="report-stat-icon">📚</span>
                        <div>
                            <span>Cursos disponibles</span>
                            <strong><?php echo number_format($total_cursos_alumno); ?></strong>
                        </div>
                    </article>
                    <article class="report-stat-card">
                        <span class="report-stat-icon">📝</span>
                        <div>
                            <span>Lecciones para aprender</span>
                            <strong><?php echo number_format($total_lecciones_alumno); ?></strong>
                        </div>
                    </article>
                </div>

                <div class="student-courses-heading">
                    <div>
                        <h2>Cursos para ti</h2>
                        <p>Empieza o continúa con uno de estos cursos.</p>
                    </div>
                    <a class="reports-link" href="dashboard.php?seccion=cursos">Ver catálogo &rarr;</a>
                </div>

                <?php if ($cursos_alumno->num_rows > 0): ?>
                    <div class="interactive-courses-grid">
                        <?php while ($curso = $cursos_alumno->fetch_assoc()): ?>
                            <?php
                            $banner_url = url_https_valida($curso["banner_curso"] ?? null) ? $curso["banner_curso"] : "";
                            $foto_url = url_https_valida($curso["foto_curso"] ?? null) ? $curso["foto_curso"] : "";
                            ?>
                            <article class="course-card-interactive">
                                <?php render_course_hover_details($curso, $curso["instructor_nombre"]); ?>
                                <div class="course-card-banner" style="background-image: url('<?php echo htmlspecialchars($banner_url, ENT_QUOTES, "UTF-8"); ?>');">
                                    <span class="course-tag"><?php echo htmlspecialchars($curso["categoria"], ENT_QUOTES, "UTF-8"); ?></span>
                                    <?php if ($foto_url !== ""): ?>
                                        <img src="<?php echo htmlspecialchars($foto_url, ENT_QUOTES, "UTF-8"); ?>" class="course-card-avatar" alt="">
                                    <?php endif; ?>
                                </div>
                                <div class="course-card-body">
                                    <h3><?php echo htmlspecialchars($curso["titulo"], ENT_QUOTES, "UTF-8"); ?></h3>
                                    <p><?php echo htmlspecialchars($curso["descripcion"] ?? "", ENT_QUOTES, "UTF-8"); ?></p>
                                    <div class="course-card-footer">
                                        <span class="level-badge"><?php echo number_format((int) $curso["total_lecciones"]); ?> lecciones · <?php echo htmlspecialchars($curso["nivel"], ENT_QUOTES, "UTF-8"); ?></span>
                                        <a href="ver_curso.php?id=<?php echo (int) $curso["id"]; ?>" class="btn-primary-glow">Entrar al curso &rarr;</a>
                                    </div>
                                    <small>Impartido por <?php echo htmlspecialchars(trim($curso["instructor_nombre"]) ?: "Docente asignado", ENT_QUOTES, "UTF-8"); ?></small>
                                </div>
                            </article>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="reports-empty-state">
                        <span aria-hidden="true">📚</span>
                        <h3>Aún no hay cursos disponibles</h3>
                        <p>Cuando se publiquen cursos, podrás encontrarlos aquí y empezar a aprender.</p>
                    </div>
                <?php endif; ?>
            </section>

        <!-- VISTA DIRECTOR -->
        <?php elseif ($rol_usuario === "director" && $seccion === 'dashboard'): ?>
            <div class="page-header">
                <h1>Panel de Dirección Ejecutiva</h1>
                <p>Supervisión general del rendimiento académico e institucional.</p>
            </div>
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-icon alumnos">🎓</div><div class="stat-data"><span>Alumnos Matriculados</span><h3>120</h3></div></div>
                <div class="stat-card"><div class="stat-icon docentes">👨‍🏫</div><div class="stat-data"><span>Docentes Activos</span><h3>8</h3></div></div>
                <div class="stat-card"><div class="stat-icon total">📚</div><div class="stat-data"><span>Cursos Impartidos</span><h3>14</h3></div></div>
            </div>

        <!-- VISTA PONENTE / MAESTRO -->
        <?php elseif ($rol_usuario === "ponente" && $seccion === 'dashboard'): ?>
            <?php
            $stmt_courses = $conexion->prepare(
                "SELECT c.id, c.titulo, c.descripcion, c.categoria, c.nivel, c.foto_curso, c.banner_curso,
                        COUNT(l.id) AS total_lecciones
                 FROM cursos c
                 LEFT JOIN lecciones l ON l.curso_id = c.id
                 WHERE c.instructor_id = ?
                 GROUP BY c.id, c.titulo, c.descripcion, c.categoria, c.nivel, c.foto_curso, c.banner_curso
                 ORDER BY c.id DESC"
            );
            $stmt_courses->bind_param("i", $id_usuario);
            $stmt_courses->execute();
            $cursos_docente = $stmt_courses->get_result();
            $cursos_docente_lista = $cursos_docente->fetch_all(MYSQLI_ASSOC);
            $total_cursos_docente = count($cursos_docente_lista);
            $total_lecciones_docente = array_sum(array_column($cursos_docente_lista, "total_lecciones"));
            ?>
            <section class="teacher-dashboard">
                <div class="teacher-welcome">
                    <div>
                        <span class="section-eyebrow">PANEL DEL DOCENTE</span>
                        <h1>Hola, <?php echo htmlspecialchars($nombre_usuario, ENT_QUOTES, "UTF-8"); ?></h1>
                        <p>Administra el contenido de tus cursos y mantén tus lecciones al día.</p>
                        <a class="btn-primary-glow" href="dashboard.php?seccion=reportes">Ver reportes &rarr;</a>
                    </div>
                    <div class="teacher-welcome-mark" aria-hidden="true">👨‍🏫</div>
                </div>

                <div class="report-stats-grid teacher-quick-stats">
                    <article class="report-stat-card">
                        <span class="report-stat-icon">📚</span>
                        <div>
                            <span>Cursos asignados</span>
                            <strong><?php echo number_format($total_cursos_docente); ?></strong>
                        </div>
                    </article>
                    <article class="report-stat-card">
                        <span class="report-stat-icon">📝</span>
                        <div>
                            <span>Lecciones publicadas</span>
                            <strong><?php echo number_format((int) $total_lecciones_docente); ?></strong>
                        </div>
                    </article>
                </div>

                <div class="student-courses-heading">
                    <div>
                        <h2>Tus cursos</h2>
                        <p>Abre un curso para revisarlo o agrega una nueva lección.</p>
                    </div>
                </div>

                <?php if ($cursos_docente_lista): ?>
                    <div class="interactive-courses-grid">
                        <?php foreach ($cursos_docente_lista as $curso): ?>
                            <?php
                            $banner_url = url_https_valida($curso["banner_curso"] ?? null) ? $curso["banner_curso"] : "";
                            $foto_url = url_https_valida($curso["foto_curso"] ?? null) ? $curso["foto_curso"] : "";
                            ?>
                            <article class="course-card-interactive">
                                <?php render_course_hover_details($curso, $nombre_usuario . " " . $apellidos_usuario); ?>
                                <div class="course-card-banner" style="background-image: url('<?php echo htmlspecialchars($banner_url, ENT_QUOTES, "UTF-8"); ?>');">
                                    <span class="course-tag"><?php echo htmlspecialchars($curso["categoria"], ENT_QUOTES, "UTF-8"); ?></span>
                                    <?php if ($foto_url !== ""): ?>
                                        <img src="<?php echo htmlspecialchars($foto_url, ENT_QUOTES, "UTF-8"); ?>" class="course-card-avatar" alt="">
                                    <?php endif; ?>
                                </div>
                                <div class="course-card-body">
                                    <h3><?php echo htmlspecialchars($curso["titulo"], ENT_QUOTES, "UTF-8"); ?></h3>
                                    <p><?php echo htmlspecialchars($curso["descripcion"] ?? "", ENT_QUOTES, "UTF-8"); ?></p>
                                    <div class="course-card-footer">
                                        <span class="level-badge"><?php echo number_format((int) $curso["total_lecciones"]); ?> lecciones · <?php echo htmlspecialchars($curso["nivel"], ENT_QUOTES, "UTF-8"); ?></span>
                                        <a class="btn-primary-glow" href="ver_curso.php?id=<?php echo (int) $curso["id"]; ?>">Ver curso &rarr;</a>
                                    </div>
                                    <button class="btn-secondary-outline teacher-add-lesson" type="button" onclick="openLessonModal(<?php echo (int) $curso["id"]; ?>, <?php echo htmlspecialchars(json_encode($curso["titulo"]), ENT_QUOTES, "UTF-8"); ?>)">📝 Agregar lección</button>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="reports-empty-state teacher-empty-state">
                        <span aria-hidden="true">📚</span>
                        <h3>Aún no tienes cursos asignados</h3>
                        <p>Cuando un administrador te asigne un curso, aparecerá aquí para que puedas gestionar sus lecciones.</p>
                    </div>
                <?php endif; ?>
            </section>

        <!-- SECCIÓN: CURSOS INTERACTIVOS -->
        <?php elseif ($seccion === 'cursos'): ?>
            <?php
            if ($rol_usuario === "ponente") {
                $stmt_courses = $conexion->prepare(
                    "SELECT c.id, c.titulo, c.descripcion, c.categoria, c.nivel, c.foto_curso, c.banner_curso, TRIM(CONCAT(u.nombre, ' ', COALESCE(u.apellidos, ''))) AS instructor_nombre
                     FROM cursos c LEFT JOIN usuarios u ON c.instructor_id = u.id
                     WHERE c.instructor_id = ? ORDER BY c.id DESC"
                );
                $stmt_courses->bind_param("i", $id_usuario);
                $stmt_courses->execute();
                $cursos = $stmt_courses->get_result();
            } else {
                $cursos = $conexion->query(
                    "SELECT c.id, c.titulo, c.descripcion, c.categoria, c.nivel, c.foto_curso, c.banner_curso, TRIM(CONCAT(u.nombre, ' ', COALESCE(u.apellidos, ''))) AS instructor_nombre
                     FROM cursos c LEFT JOIN usuarios u ON c.instructor_id = u.id
                     ORDER BY c.id DESC"
                );
            }
            ?>
            <div class="page-header">
                <h1>📚 Catálogo General de Cursos</h1>
                <p>Accede al contenido dinámico y lecciones activas.</p>
            </div>

            <div class="interactive-courses-grid">
                <?php if ($cursos->num_rows > 0): ?>
                    <?php while ($curso = $cursos->fetch_assoc()): ?>
                    <?php
                    $banner_url = url_https_valida($curso["banner_curso"] ?? null) ? $curso["banner_curso"] : "";
                    $foto_url = url_https_valida($curso["foto_curso"] ?? null) ? $curso["foto_curso"] : "";
                    ?>
                    <div class="course-card-interactive">
                        <?php render_course_hover_details($curso, $curso["instructor_nombre"]); ?>
                        <div class="course-card-banner" style="background-image: url('<?php echo htmlspecialchars($banner_url, ENT_QUOTES, 'UTF-8'); ?>');">
                            <span class="course-tag"><?php echo htmlspecialchars($curso['categoria'], ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php if ($foto_url !== ''): ?>
                                <img src="<?php echo htmlspecialchars($foto_url, ENT_QUOTES, 'UTF-8'); ?>" class="course-card-avatar" alt="">
                            <?php endif; ?>
                        </div>
                        <div class="course-card-body">
                            <h3><?php echo htmlspecialchars($curso['titulo'], ENT_QUOTES, 'UTF-8'); ?></h3>
                            <p><?php echo htmlspecialchars($curso['descripcion'] ?? '', ENT_QUOTES, 'UTF-8'); ?></p>
                            <div class="course-card-footer">
                                <span class="level-badge"><?php echo htmlspecialchars($curso['nivel'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <a href="ver_curso.php?id=<?php echo (int) $curso['id']; ?>" class="btn-primary-glow">Entrar al curso &rarr;</a>
                            </div>
                            <small>Impartido por <?php echo htmlspecialchars($curso['instructor_nombre'] ?? 'Docente asignado', ENT_QUOTES, 'UTF-8'); ?></small>
                        </div>
                    </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p class="empty-state">No hay cursos disponibles por el momento.</p>
                <?php endif; ?>
            </div>

        <?php elseif ($seccion === 'reportes'): ?>
            <?php
            if ($rol_usuario === "ponente") {
                $stmt_report = $conexion->prepare(
                    "SELECT COUNT(DISTINCT c.id) AS cursos,
                            COUNT(DISTINCT l.id) AS lecciones
                     FROM cursos c
                     LEFT JOIN lecciones l ON l.curso_id = c.id
                     WHERE c.instructor_id = ?"
                );
                $stmt_report->bind_param("i", $id_usuario);
                $stmt_report->execute();
                $resumen_reportes = $stmt_report->get_result()->fetch_assoc();
            } else {
                $resumen_reportes = $conexion->query(
                    "SELECT (SELECT COUNT(*) FROM cursos) AS cursos,
                            (SELECT COUNT(*) FROM lecciones) AS lecciones"
                )->fetch_assoc();
            }

            $total_alumnos_reportes = $conexion->query(
                "SELECT COUNT(*) AS total FROM usuarios WHERE rol = 'alumno'"
            )->fetch_assoc()["total"];

            $cursos_recientes = $conexion->query(
                "SELECT c.id, c.titulo, c.descripcion, c.categoria, c.nivel, COUNT(l.id) AS lecciones,
                        TRIM(CONCAT(COALESCE(u.nombre, ''), ' ', COALESCE(u.apellidos, ''))) AS instructor
                 FROM cursos c
                 LEFT JOIN usuarios u ON c.instructor_id = u.id
                 LEFT JOIN lecciones l ON l.curso_id = c.id
                 " . ($rol_usuario === "ponente" ? "WHERE c.instructor_id = " . (int) $id_usuario : "") . "
                 GROUP BY c.id, c.titulo, c.descripcion, c.categoria, c.nivel, u.nombre, u.apellidos
                 ORDER BY c.id DESC
                 LIMIT 5"
            );
            ?>
            <section class="reports-section">
                <div class="page-header">
                    <span class="section-eyebrow">RESUMEN ACADÉMICO</span>
                    <h1>📈 Reportes</h1>
                    <p>
                        <?php echo $rol_usuario === "ponente"
                            ? "Consulta la actividad de tus cursos y sus contenidos."
                            : "Consulta un resumen de los cursos y contenidos publicados en la plataforma."; ?>
                    </p>
                </div>

                <div class="report-stats-grid">
                    <article class="report-stat-card">
                        <span class="report-stat-icon">📚</span>
                        <div>
                            <span><?php echo $rol_usuario === "ponente" ? "Mis cursos" : "Cursos disponibles"; ?></span>
                            <strong><?php echo number_format((int) $resumen_reportes["cursos"]); ?></strong>
                        </div>
                    </article>
                    <article class="report-stat-card">
                        <span class="report-stat-icon">📝</span>
                        <div>
                            <span>Lecciones publicadas</span>
                            <strong><?php echo number_format((int) $resumen_reportes["lecciones"]); ?></strong>
                        </div>
                    </article>
                    <article class="report-stat-card">
                        <span class="report-stat-icon">🎓</span>
                        <div>
                            <span><?php echo $rol_usuario === "ponente" ? "Alumnos en la plataforma" : "Alumnos registrados"; ?></span>
                            <strong><?php echo number_format((int) $total_alumnos_reportes); ?></strong>
                        </div>
                    </article>
                </div>

                <div class="reports-content-card">
                    <div class="reports-content-heading">
                        <div>
                            <h2><?php echo $rol_usuario === "ponente" ? "Actividad de mis cursos" : "Cursos recientes"; ?></h2>
                            <p>Contenido disponible en la plataforma.</p>
                        </div>
                        <?php if ($rol_usuario !== "ponente"): ?>
                            <a class="reports-link" href="dashboard.php?seccion=cursos">Explorar cursos &rarr;</a>
                        <?php endif; ?>
                    </div>
                    <?php if ($cursos_recientes->num_rows > 0): ?>
                        <div class="report-course-list">
                            <?php while ($curso_reporte = $cursos_recientes->fetch_assoc()): ?>
                                <div class="report-course-item">
                                    <a class="report-course-row" href="ver_curso.php?id=<?php echo (int) $curso_reporte["id"]; ?>">
                                        <span class="report-course-icon">📖</span>
                                        <span class="report-course-name">
                                            <strong><?php echo htmlspecialchars($curso_reporte["titulo"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            <small><?php echo htmlspecialchars($curso_reporte["categoria"], ENT_QUOTES, "UTF-8"); ?></small>
                                        </span>
                                        <span class="report-course-lessons"><?php echo number_format((int) $curso_reporte["lecciones"]); ?> lecciones</span>
                                        <span class="report-course-arrow" aria-hidden="true">&rarr;</span>
                                    </a>
                                    <?php render_course_hover_details($curso_reporte, $curso_reporte["instructor"]); ?>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="reports-empty-state">
                            <span aria-hidden="true">📚</span>
                            <h3>Aún no hay actividad para mostrar</h3>
                            <p>
                                <?php echo $rol_usuario === "ponente"
                                    ? "Cuando tengas cursos asignados, su actividad aparecerá aquí."
                                    : "Cuando haya cursos publicados, encontrarás aquí el resumen de su actividad."; ?>
                            </p>
                            <?php if ($rol_usuario !== "ponente"): ?>
                                <a class="btn-primary-glow" href="dashboard.php?seccion=cursos">Ver cursos disponibles</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

        <!-- SECCIÓN: CONFIGURACIÓN PERFIL -->
        <?php elseif ($seccion === 'configuracion'): ?>
            <div class="page-header">
                <h1>⚙️ Configuración de Perfil</h1>
                <p>Actualiza tu información personal, foto y contraseña.</p>
            </div>
            <div class="profile-settings-card">
                <form method="POST" action="dashboard.php?seccion=configuracion" enctype="multipart/form-data">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="action" value="update_config">
                    <input type="hidden" name="foto_url" id="hiddenFotoUrl">

                    <div class="profile-photo-section">
                        <div class="profile-photo-preview">
                            <?php if (!empty($nav_avatar_src)): ?>
                                <img id="profilePhotoPreview" src="<?php echo htmlspecialchars($nav_avatar_src, ENT_QUOTES, "UTF-8"); ?>" alt="Foto de perfil actual">
                            <?php else: ?>
                                <img id="profilePhotoPreview" src="" alt="Vista previa de foto" hidden>
                                <span id="profilePhotoInitial"><?php echo htmlspecialchars(strtoupper(substr($nombre_usuario, 0, 1)), ENT_QUOTES, "UTF-8"); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="profile-photo-info">
                            <strong>Foto de perfil</strong>
                            <p>JPG, PNG o WebP · máximo 3 MB</p>
                            <button type="button" class="btn-secondary-outline" onclick="openPhotoModal()">Cambiar foto</button>
                            <input type="file" id="hiddenFileInput" name="foto" accept="image/jpeg,image/png,image/webp" hidden onchange="updatePhotoPreview(this)">
                            <span id="photoFileName" class="photo-file-name" aria-live="polite"></span>
                        </div>
                    </div>

                    <div class="profile-form-fields">
                        <div class="form-group">
                            <label for="nombre">Nombre</label>
                            <input type="text" class="input-field" id="nombre" name="nombre" value="<?php echo htmlspecialchars($nombre_usuario, ENT_QUOTES, "UTF-8"); ?>" maxlength="120" autocomplete="given-name" required>
                        </div>
                        <div class="form-group">
                            <label for="apellidos">Apellidos</label>
                            <input type="text" class="input-field" id="apellidos" name="apellidos" value="<?php echo htmlspecialchars($apellidos_usuario, ENT_QUOTES, "UTF-8"); ?>" maxlength="120" autocomplete="family-name" required>
                        </div>
                        <div class="form-group profile-description-field">
                            <label for="descripcion">Descripción</label>
                            <textarea class="input-field" id="descripcion" name="descripcion" rows="4" maxlength="2000" placeholder="Cuéntanos un poco sobre ti"><?php echo htmlspecialchars($descripcion_perfil ?? "", ENT_QUOTES, "UTF-8"); ?></textarea>
                            <small>Puedes escribir hasta 2000 caracteres.</small>
                        </div>

                        <div class="password-fields-heading">
                            <strong>Cambiar contraseña</strong>
                            <span>Déjalos vacíos para conservar la contraseña actual.</span>
                        </div>
                        <div class="form-group">
                            <label for="password_nueva">Nueva contraseña</label>
                            <input type="password" class="input-field" id="password_nueva" name="password_nueva" placeholder="Mínimo 12 caracteres" minlength="12" autocomplete="new-password">
                        </div>
                        <div class="form-group">
                            <label for="confirmar_password">Confirmar nueva contraseña</label>
                            <input type="password" class="input-field" id="confirmar_password" name="confirmar_password" placeholder="Repite la nueva contraseña" minlength="12" autocomplete="new-password">
                        </div>
                    </div>

                    <div class="profile-form-actions">
                        <button type="submit" class="btn-primary-glow">Guardar cambios</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

    </main>
</div>

<!-- Modal Opciones de Foto -->
<div id="photoModalOverlay" class="drawer-overlay" onclick="closePhotoModal()"></div>
<div id="photoModal" class="auth-card" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); z-index:1002; width:90%; max-width:420px; padding:2rem;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1.2rem;">
        <h3 style="font-size:1.1rem; color:var(--slate-900);">Cambiar foto de perfil</h3>
        <button type="button" class="btn-close" onclick="closePhotoModal()">&times;</button>
    </div>

    <button type="button" class="btn-primary-glow" onclick="triggerFileInput()" style="width:100%; margin-bottom:1rem; border:none; padding:10px;">📁 Subir archivo local</button>
    <div class="divider"><span>o mediante URL</span></div>
    <input type="url" id="modalUrlInput" class="input-field" placeholder="https://ejemplo.com/foto.jpg" style="margin-top:0.8rem;">
    <button type="button" class="btn-secondary-outline" onclick="saveUrlPhoto()" style="width:100%; margin-top:0.8rem;">Usar esta URL</button>
</div>

<!-- Modal Lecciones -->
<div id="lessonModalOverlay" class="drawer-overlay" onclick="closeLessonModal()"></div>
<div id="lessonModal" class="auth-card" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); z-index:1002; width:90%; max-width:480px; padding:2rem;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
        <h3 id="lessonModalTitle">Editar Lección</h3>
        <button type="button" class="btn-close" onclick="closeLessonModal()">&times;</button>
    </div>
    <form method="POST" action="dashboard.php">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="save_lesson">
        <input type="hidden" name="curso_id" id="modalCourseId">
        <div class="form-group">
            <label>Título de la lección</label>
            <input type="text" name="titulo_leccion" class="input-field" placeholder="Ej. Tema 1..." required>
        </div>
        <div class="form-group">
            <label>Descripción</label>
            <textarea name="contenido_leccion" class="input-field" rows="3"></textarea>
        </div>
        <div class="form-group">
            <label>Enlace del material (opcional, HTTPS)</label>
            <input type="url" name="material_url" class="input-field" placeholder="https://...">
        </div>
        <button type="submit" class="btn-primary-glow" style="width:100%; border:none; padding:10px; margin-top:1rem;">Guardar Lección</button>
    </form>
</div>

<!-- Modal Drawer (SuperAdmin) -->
<?php if ($rol_usuario === "superadmin"): ?>
<div id="drawerOverlay" class="drawer-overlay" onclick="closeDrawer()"></div>
<div id="userDrawer" class="drawer" style="display:none;">
    <div>
        <div class="drawer-header">
            <h3>Editar Usuario</h3>
            <button class="btn-close" onclick="closeDrawer()">&times;</button>
        </div>
        <form method="POST" action="dashboard.php?seccion=usuarios" enctype="multipart/form-data" id="roleForm">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="update_user_admin">
            <input type="hidden" name="user_id" id="drawerUserId">

            <div style="margin-bottom: 1.2rem;">
                <label style="font-size:0.85rem; color:var(--slate-500);">Usuario seleccionado</label>
                <h4 id="drawerUserName" style="font-size:1.1rem; color:var(--slate-900); margin-top:2px;">-</h4>
            </div>

            <div class="form-group" style="margin-bottom: 1.2rem;">
                <label for="nuevo_rol" style="font-size:0.85rem; font-weight:600;">Asignar rol principal</label>
                <select name="nuevo_rol" id="drawerRoleSelect" class="input-field" style="margin-top:4px;">
                    <option value="superadmin">Super Admin</option>
                    <option value="director">Director</option>
                    <option value="ponente">Ponente / Docente</option>
                    <option value="alumno">Alumno</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 0.8rem;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">Subir foto desde archivo</label>
                <input type="file" class="input-field" name="foto_admin" accept="image/*">
            </div>

            <div style="text-align: center; color: var(--slate-500); font-size: 0.75rem; margin: 0.4rem 0;">&mdash; o URL &mdash;</div>

            <div class="form-group" style="margin-bottom: 1.5rem;">
                <label style="font-size:0.85rem; font-weight:600; display:block; margin-bottom:4px;">URL de la foto</label>
                <input type="url" class="input-field" name="foto_admin_url" placeholder="https://ejemplo.com/foto.jpg">
            </div>

            <button type="submit" class="btn-primary-glow" style="width:100%; border:none; padding:10px;">Guardar cambios</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openPhotoModal() {
    document.getElementById('photoModalOverlay').style.display = 'block';
    document.getElementById('photoModal').style.display = 'block';
}

function closePhotoModal() {
    document.getElementById('photoModalOverlay').style.display = 'none';
    document.getElementById('photoModal').style.display = 'none';
}

function triggerFileInput() {
    closePhotoModal();
    document.getElementById('hiddenFileInput').click();
}

function updatePhotoPreview(input) {
    if (input.files && input.files[0]) {
        document.getElementById('hiddenFotoUrl').value = '';
        var preview = document.getElementById('profilePhotoPreview');
        preview.src = URL.createObjectURL(input.files[0]);
        preview.hidden = false;
        var initial = document.getElementById('profilePhotoInitial');
        if (initial) initial.hidden = true;
        document.getElementById('photoFileName').innerText = "Seleccionada: " + input.files[0].name;
    }
}

function saveUrlPhoto() {
    var url = document.getElementById('modalUrlInput').value.trim();
    if (url.toLowerCase().indexOf('https://') === 0) {
        document.getElementById('hiddenFileInput').value = '';
        document.getElementById('hiddenFotoUrl').value = url;
        document.getElementById('photoFileName').innerText = "✓ URL vinculada correctamente";
        var preview = document.getElementById('profilePhotoPreview');
        preview.src = url;
        preview.hidden = false;
        var initial = document.getElementById('profilePhotoInitial');
        if (initial) initial.hidden = true;
        closePhotoModal();
    } else {
        document.getElementById('photoFileName').innerText = "Ingresa una URL segura que comience con https://";
    }
}

function openLessonModal(courseId, courseName) {
    document.getElementById('lessonModalTitle').innerText = '📝 Lecciones: ' + courseName;
    document.getElementById('modalCourseId').value = courseId;
    document.getElementById('lessonModalOverlay').style.display = 'block';
    document.getElementById('lessonModal').style.display = 'block';
}

function closeLessonModal() {
    document.getElementById('lessonModalOverlay').style.display = 'none';
    document.getElementById('lessonModal').style.display = 'none';
}

function openDrawer(id, nombre, rol) {
    document.getElementById('drawerUserId').value = id;
    document.getElementById('drawerUserName').innerText = nombre;
    document.getElementById('drawerRoleSelect').value = rol;
    document.getElementById('drawerOverlay').style.display = 'block';
    document.getElementById('userDrawer').style.display = 'flex';
}

function closeDrawer() {
    document.getElementById('drawerOverlay').style.display = 'none';
    document.getElementById('userDrawer').style.display = 'none';
}
</script>

<?php include_once "footer.php"; ?>