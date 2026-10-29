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

asegurar_esquema_curso_alumnos($conexion);

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

// Asignar un curso a un docente
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "assign_course") {
    if ($rol_usuario !== "superadmin") {
        http_response_code(403);
        exit("No tienes permiso para realizar esta acción.");
    }

    $curso_id = filter_input(INPUT_POST, "curso_id", FILTER_VALIDATE_INT);
    $docente_id = ($_POST["instructor_id"] ?? "") === ""
        ? null
        : filter_input(INPUT_POST, "instructor_id", FILTER_VALIDATE_INT);

    if (!$curso_id || ($docente_id !== null && !$docente_id)) {
        $error_operacion = "Selecciona un curso y un docente válidos.";
    } else {
        $stmt = $conexion->prepare("SELECT id FROM cursos WHERE id = ?");
        $stmt->bind_param("i", $curso_id);
        $stmt->execute();
        $curso_existe = $stmt->get_result()->fetch_assoc();

        if (!$curso_existe) {
            $error_operacion = "No se encontró el curso que intentas asignar.";
        } elseif ($docente_id !== null) {
            $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE id = ? AND rol = 'ponente'");
            $stmt->bind_param("i", $docente_id);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) {
                $error_operacion = "La cuenta seleccionada no es un docente.";
            }
        }

        if ($error_operacion === "") {
            if ($docente_id === null) {
                $stmt = $conexion->prepare("UPDATE cursos SET instructor_id = NULL WHERE id = ?");
                $stmt->bind_param("i", $curso_id);
            } else {
                $stmt = $conexion->prepare("UPDATE cursos SET instructor_id = ? WHERE id = ?");
                $stmt->bind_param("ii", $docente_id, $curso_id);
            }
            $stmt->execute();
            $mensaje_exito = $docente_id === null
                ? "Se quitó la asignación del curso."
                : "Curso asignado al docente correctamente.";
        }
    }
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

// 5. PROCESAR: Inscribir alumno con validación del director y del administrador
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "enroll_student") {
    if (!in_array($rol_usuario, ["ponente", "director", "superadmin"], true)) {
        http_response_code(403);
        exit("No tienes permiso para inscribir alumnos.");
    }

    $curso_id = filter_input(INPUT_POST, "curso_id", FILTER_VALIDATE_INT);
    $alumno_id = filter_input(INPUT_POST, "alumno_id", FILTER_VALIDATE_INT);

    if (!$curso_id || !$alumno_id) {
        $error_operacion = "Debes seleccionar un curso y un alumno válido.";
    } else {
        if ($rol_usuario === "ponente") {
            $stmt = $conexion->prepare("SELECT id FROM cursos WHERE id = ? AND instructor_id = ?");
            $stmt->bind_param("ii", $curso_id, $id_usuario);
        } else {
            $stmt = $conexion->prepare("SELECT id FROM cursos WHERE id = ?");
            $stmt->bind_param("i", $curso_id);
        }

        $stmt->execute();
        $curso_valido = $stmt->get_result()->fetch_assoc();

        if (!$curso_valido) {
            $error_operacion = "No se encontró el curso o no tienes permiso para asignarlo.";
        } else {
            $stmt_alumno = $conexion->prepare("SELECT id FROM usuarios WHERE id = ? AND rol = 'alumno'");
            $stmt_alumno->bind_param("i", $alumno_id);
            $stmt_alumno->execute();
            if (!$stmt_alumno->get_result()->fetch_assoc()) {
                $error_operacion = "El usuario seleccionado no es un alumno válido.";
            }
        }

        if ($error_operacion === "") {
            $stmt_exist = $conexion->prepare(
                "SELECT id, estado, aprobado_por_director, aprobado_por_admin FROM curso_alumnos WHERE curso_id = ? AND alumno_id = ?"
            );
            $stmt_exist->bind_param("ii", $curso_id, $alumno_id);
            $stmt_exist->execute();
            $inscripcion = $stmt_exist->get_result()->fetch_assoc();

            if ($inscripcion) {
                $mensaje_exito = "Este alumno ya está registrado en el curso.";
            } else {
                $stmt_insert = $conexion->prepare(
                    "INSERT INTO curso_alumnos (curso_id, alumno_id, estado, aprobado_por_director, aprobado_por_admin) VALUES (?, ?, 'pendiente', 0, 0)"
                );
                $stmt_insert->bind_param("ii", $curso_id, $alumno_id);
                $stmt_insert->execute();
                $mensaje_exito = "Solicitud enviada. Requiere aprobación del director y del administrador.";
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "approve_student_enrollment") {
    if (!in_array($rol_usuario, ["director", "superadmin"], true)) {
        http_response_code(403);
        exit("No tienes permiso para aprobar matriculas.");
    }

    $inscripcion_id = filter_input(INPUT_POST, "inscripcion_id", FILTER_VALIDATE_INT);
    if (!$inscripcion_id) {
        $error_operacion = "No se encontró la solicitud de matrícula.";
    } else {
        $stmt = $conexion->prepare(
            "SELECT id, curso_id, alumno_id, estado, aprobado_por_director, aprobado_por_admin FROM curso_alumnos WHERE id = ?"
        );
        $stmt->bind_param("i", $inscripcion_id);
        $stmt->execute();
        $solicitud = $stmt->get_result()->fetch_assoc();

        if (!$solicitud) {
            $error_operacion = "La solicitud ya no existe.";
        } else {
            if ($rol_usuario === "director") {
                $set_director = 1;
                $set_admin = $solicitud["aprobado_por_admin"];
                $estado_final = ($set_director && $set_admin) ? "aprobado" : "pendiente";
                $stmt_update = $conexion->prepare(
                    "UPDATE curso_alumnos SET aprobado_por_director = 1, estado = ? WHERE id = ?"
                );
                $stmt_update->bind_param("si", $estado_final, $inscripcion_id);
            } else {
                $set_admin = 1;
                $set_director = $solicitud["aprobado_por_director"];
                $estado_final = ($set_director && $set_admin) ? "aprobado" : "pendiente";
                $stmt_update = $conexion->prepare(
                    "UPDATE curso_alumnos SET aprobado_por_admin = 1, estado = ? WHERE id = ?"
                );
                $stmt_update->bind_param("si", $estado_final, $inscripcion_id);
            }

            $stmt_update->execute();
            $mensaje_exito = $rol_usuario === "director"
                ? "Aprobación del director registrada. Esperando aprobación final del administrador."
                : "Aprobación del administrador registrada. La matrícula quedó confirmada.";
        }
    }
}

include_once "header.php";
?>

<div class="dashboard-layout <?php echo $seccion === 'configuracion' ? 'dashboard-layout--profile' : ''; ?>">
    <aside class="sidebar">
        <ul class="sidebar-nav">
            <li><a href="dashboard.php?seccion=dashboard" class="<?php echo ($seccion === 'dashboard') ? 'active' : ''; ?>" aria-label="Dashboard" title="Dashboard"><span class="sidebar-icon" aria-hidden="true">📊</span><span class="sidebar-label">Dashboard</span></a></li>

            <?php if ($rol_usuario === "superadmin"): ?>
                <li><a href="dashboard.php?seccion=usuarios" class="<?php echo ($seccion === 'usuarios') ? 'active' : ''; ?>" aria-label="Usuarios" title="Usuarios"><span class="sidebar-icon" aria-hidden="true">👥</span><span class="sidebar-label">Usuarios</span></a></li>
                <li><a href="dashboard.php?seccion=roles" class="<?php echo ($seccion === 'roles') ? 'active' : ''; ?>" aria-label="Roles" title="Roles"><span class="sidebar-icon" aria-hidden="true">🛡️</span><span class="sidebar-label">Roles</span></a></li>
            <?php endif; ?>

            <?php if ($rol_usuario === "director"): ?>
                <li><a href="dashboard.php?seccion=docentes" class="<?php echo ($seccion === 'docentes') ? 'active' : ''; ?>" aria-label="Cuerpo Docente" title="Cuerpo Docente"><span class="sidebar-icon" aria-hidden="true">👨‍🏫</span><span class="sidebar-label">Cuerpo Docente</span></a></li>
            <?php endif; ?>

            <?php if (in_array($rol_usuario, ["ponente", "director", "superadmin"], true)): ?>
                <?php $etiqueta_alumnos = $rol_usuario === "ponente" ? "Mis Alumnos" : "Matrículas"; ?>
                <li><a href="dashboard.php?seccion=mis_alumnos" class="<?php echo ($seccion === 'mis_alumnos') ? 'active' : ''; ?>" aria-label="<?php echo htmlspecialchars($etiqueta_alumnos, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($etiqueta_alumnos, ENT_QUOTES, 'UTF-8'); ?>"><span class="sidebar-icon" aria-hidden="true">👨‍🎓</span><span class="sidebar-label"><?php echo htmlspecialchars($etiqueta_alumnos, ENT_QUOTES, 'UTF-8'); ?></span></a></li>
            <?php endif; ?>

            <?php
                $etiqueta_cursos = $rol_usuario === 'director'
                    ? 'Todos los Cursos'
                    : ($rol_usuario === 'ponente' ? 'Gestión de Cursos' : 'Mis Cursos');
            ?>
            <li><a href="dashboard.php?seccion=cursos" class="<?php echo ($seccion === 'cursos') ? 'active' : ''; ?>" aria-label="<?php echo htmlspecialchars($etiqueta_cursos, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($etiqueta_cursos, ENT_QUOTES, 'UTF-8'); ?>"><span class="sidebar-icon" aria-hidden="true">📚</span><span class="sidebar-label"><?php echo htmlspecialchars($etiqueta_cursos, ENT_QUOTES, 'UTF-8'); ?></span></a></li>

            <li><a href="dashboard.php?seccion=reportes" class="<?php echo ($seccion === 'reportes') ? 'active' : ''; ?>" aria-label="Reportes" title="Reportes"><span class="sidebar-icon" aria-hidden="true">📈</span><span class="sidebar-label">Reportes</span></a></li>
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

            <section class="admin-users-page">
            <div class="page-header admin-users-heading">
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
                    <label class="search-bar" for="userSearch"><span aria-hidden="true">🔍</span><input type="search" id="userSearch" data-user-search placeholder="Buscar por nombre, correo o rol..." autocomplete="off"></label>
                    <a href="registro.php" class="btn-primary-glow admin-users-add">+ Nuevo usuario</a>
                </div>

                <table class="users-table">
                    <thead>
                        <tr>
                            <th scope="col">Usuario</th>
                            <th scope="col">Correo electrónico</th>
                            <th scope="col">Rol actual</th>
                            <th scope="col" class="users-actions-heading">Acciones</th>
                        </tr>
                    </thead>
                    <tbody data-user-rows>
                        <?php while ($u = $query_users->fetch_assoc()): ?>
                            <?php
                                $foto_src = url_https_valida($u['foto'] ?? null)
                                    ? $u['foto']
                                    : ((!empty($u['foto']) && basename($u['foto']) === $u['foto'] && file_exists(__DIR__ . "/uploads/" . $u['foto'])) ? "uploads/" . $u['foto'] : null);
                                $nombre_usuario_fila = trim($u['nombre'] . ' ' . ($u['apellidos'] ?? ''));
                            ?>
                            <tr data-user-row data-search-text="<?php echo htmlspecialchars($nombre_usuario_fila . ' ' . $u['email'] . ' ' . $u['rol'], ENT_QUOTES, 'UTF-8'); ?>">
                                <td>
                                    <div class="user-cell">
                                        <?php if ($foto_src): ?>
                                            <img src="<?php echo htmlspecialchars($foto_src, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="avatar" style="object-fit: cover;">
                                        <?php else: ?>
                                            <div class="avatar" aria-hidden="true"><?php echo htmlspecialchars(strtoupper(substr($u['nombre'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                        <strong><?php echo htmlspecialchars($nombre_usuario_fila, ENT_QUOTES, 'UTF-8'); ?></strong>
                                    </div>
                                </td>
                                <td><a class="user-email-link" href="mailto:<?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                                <td><span class="badge-role <?php echo htmlspecialchars($u['rol'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($u['rol']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td class="users-actions-cell">
                                    <button type="button" class="user-edit-btn" aria-label="Editar <?php echo htmlspecialchars($nombre_usuario_fila, ENT_QUOTES, 'UTF-8'); ?>" onclick="openDrawer(<?php echo (int) $u['id']; ?>, <?php echo htmlspecialchars(json_encode($nombre_usuario_fila), ENT_QUOTES, 'UTF-8'); ?>, '<?php echo htmlspecialchars($u['rol'], ENT_QUOTES, 'UTF-8'); ?>')">✏️</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                        <tr data-user-no-results hidden>
                            <td colspan="4">No se encontraron usuarios que coincidan con la búsqueda.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </section>

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
            <?php
            $resumen_director = $conexion->query(
                "SELECT
                    (SELECT COUNT(DISTINCT alumno_id) FROM curso_alumnos WHERE estado = 'aprobado') AS alumnos,
                    (SELECT COUNT(*) FROM usuarios WHERE rol = 'ponente') AS docentes,
                    (SELECT COUNT(*) FROM cursos) AS cursos,
                    (SELECT COUNT(*) FROM curso_alumnos WHERE estado = 'pendiente') AS pendientes"
            )->fetch_assoc();

            $solicitudes_director = $conexion->query(
                "SELECT ca.id, ca.aprobado_por_director, ca.aprobado_por_admin,
                        c.titulo AS curso,
                        TRIM(CONCAT(u.nombre, ' ', COALESCE(u.apellidos, ''))) AS alumno
                 FROM curso_alumnos ca
                 INNER JOIN cursos c ON c.id = ca.curso_id
                 INNER JOIN usuarios u ON u.id = ca.alumno_id
                 WHERE ca.estado = 'pendiente'
                 ORDER BY ca.id DESC
                 LIMIT 5"
            );

            $cursos_director = $conexion->query(
                "SELECT c.id, c.titulo, c.categoria, c.nivel,
                        TRIM(CONCAT(COALESCE(docente.nombre, ''), ' ', COALESCE(docente.apellidos, ''))) AS docente,
                        COUNT(DISTINCT CASE WHEN ca.estado = 'aprobado' THEN ca.alumno_id END) AS alumnos,
                        COUNT(DISTINCT l.id) AS lecciones
                 FROM cursos c
                 LEFT JOIN usuarios docente ON docente.id = c.instructor_id
                 LEFT JOIN curso_alumnos ca ON ca.curso_id = c.id
                 LEFT JOIN lecciones l ON l.curso_id = c.id
                 GROUP BY c.id, c.titulo, c.categoria, c.nivel, docente.nombre, docente.apellidos
                 ORDER BY c.id DESC
                 LIMIT 5"
            );
            ?>
            <section class="director-dashboard">
                <header class="director-welcome">
                    <div class="director-welcome-copy">
                        <span class="section-eyebrow">SUPERVISIÓN INSTITUCIONAL</span>
                        <h1>Panel de Dirección</h1>
                        <p>Hola, <?php echo htmlspecialchars($nombre_usuario, ENT_QUOTES, "UTF-8"); ?>. Consulta el estado académico y atiende las solicitudes que requieren aprobación.</p>
                        <a class="btn-primary-glow" href="dashboard.php?seccion=mis_alumnos">Revisar matrículas &rarr;</a>
                    </div>
                    <div class="director-welcome-mark" aria-hidden="true">🏛️</div>
                </header>

                <div class="director-metrics-grid">
                    <article class="director-metric-card director-metric-card--students">
                        <span class="director-metric-icon" aria-hidden="true">🎓</span>
                        <div>
                            <span>Alumnos matriculados</span>
                            <strong><?php echo number_format((int) ($resumen_director["alumnos"] ?? 0)); ?></strong>
                            <small>Con matrícula aprobada</small>
                        </div>
                    </article>
                    <article class="director-metric-card director-metric-card--teachers">
                        <span class="director-metric-icon" aria-hidden="true">👨‍🏫</span>
                        <div>
                            <span>Docentes activos</span>
                            <strong><?php echo number_format((int) ($resumen_director["docentes"] ?? 0)); ?></strong>
                            <small>Perfiles docentes registrados</small>
                        </div>
                    </article>
                    <article class="director-metric-card director-metric-card--courses">
                        <span class="director-metric-icon" aria-hidden="true">📚</span>
                        <div>
                            <span>Cursos disponibles</span>
                            <strong><?php echo number_format((int) ($resumen_director["cursos"] ?? 0)); ?></strong>
                            <small>Oferta académica actual</small>
                        </div>
                    </article>
                    <article class="director-metric-card director-metric-card--pending">
                        <span class="director-metric-icon" aria-hidden="true">⏳</span>
                        <div>
                            <span>Matrículas pendientes</span>
                            <strong><?php echo number_format((int) ($resumen_director["pendientes"] ?? 0)); ?></strong>
                            <small>Esperando validación</small>
                        </div>
                    </article>
                </div>

                <div class="director-overview-grid">
                    <section class="director-panel director-approvals-panel">
                        <div class="director-panel-heading">
                            <div>
                                <span class="section-eyebrow">PENDIENTES</span>
                                <h2>Solicitudes recientes</h2>
                            </div>
                            <a class="director-text-link" href="dashboard.php?seccion=mis_alumnos">Ver todas &rarr;</a>
                        </div>
                        <?php if ($solicitudes_director->num_rows > 0): ?>
                            <div class="director-request-list">
                                <?php while ($solicitud = $solicitudes_director->fetch_assoc()): ?>
                                    <article class="director-request-row">
                                        <span class="director-request-avatar" aria-hidden="true">🎓</span>
                                        <div class="director-request-info">
                                            <strong><?php echo htmlspecialchars($solicitud["alumno"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            <span><?php echo htmlspecialchars($solicitud["curso"], ENT_QUOTES, "UTF-8"); ?></span>
                                            <small>
                                                Director: <?php echo (int) $solicitud["aprobado_por_director"] ? "Aprobado" : "Pendiente"; ?>
                                                <span aria-hidden="true">·</span>
                                                Administración: <?php echo (int) $solicitud["aprobado_por_admin"] ? "Aprobado" : "Pendiente"; ?>
                                            </small>
                                        </div>
                                        <a class="director-review-link" href="dashboard.php?seccion=mis_alumnos">Revisar</a>
                                    </article>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="director-empty-state">
                                <span aria-hidden="true">✓</span>
                                <div>
                                    <strong>Todo está al día</strong>
                                    <p>No hay solicitudes de matrícula pendientes.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="director-panel director-courses-panel">
                        <div class="director-panel-heading">
                            <div>
                                <span class="section-eyebrow">OFERTA ACADÉMICA</span>
                                <h2>Resumen de cursos</h2>
                            </div>
                            <a class="director-text-link" href="dashboard.php?seccion=cursos">Ver catálogo &rarr;</a>
                        </div>
                        <?php if ($cursos_director->num_rows > 0): ?>
                            <div class="director-course-list">
                                <?php while ($curso_director = $cursos_director->fetch_assoc()): ?>
                                    <article class="director-course-row">
                                        <div class="director-course-mark" aria-hidden="true">📘</div>
                                        <div class="director-course-info">
                                            <a href="ver_curso.php?id=<?php echo (int) $curso_director["id"]; ?>">
                                                <?php echo htmlspecialchars($curso_director["titulo"], ENT_QUOTES, "UTF-8"); ?>
                                            </a>
                                            <span><?php echo htmlspecialchars($curso_director["categoria"], ENT_QUOTES, "UTF-8"); ?> · <?php echo htmlspecialchars($curso_director["docente"] ?: "Sin docente asignado", ENT_QUOTES, "UTF-8"); ?></span>
                                        </div>
                                        <div class="director-course-stats">
                                            <strong><?php echo number_format((int) $curso_director["alumnos"]); ?></strong>
                                            <small>alumnos</small>
                                            <strong><?php echo number_format((int) $curso_director["lecciones"]); ?></strong>
                                            <small>lecciones</small>
                                        </div>
                                    </article>
                                <?php endwhile; ?>
                            </div>
                        <?php else: ?>
                            <div class="director-empty-state">
                                <span aria-hidden="true">📚</span>
                                <div>
                                    <strong>Aún no hay cursos</strong>
                                    <p>Los cursos se mostrarán aquí cuando estén registrados.</p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            </section>

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

        <?php elseif ($rol_usuario === "director" && $seccion === 'docentes'): ?>
            <?php
            $docentes_director = $conexion->query(
                "SELECT u.id, u.nombre, u.apellidos, u.email, u.descripcion, u.foto,
                        COALESCE(cursos.total_cursos, 0) AS total_cursos,
                        COALESCE(alumnos.total_alumnos, 0) AS total_alumnos,
                        COALESCE(lecciones.total_lecciones, 0) AS total_lecciones
                 FROM usuarios u
                 LEFT JOIN (
                    SELECT instructor_id, COUNT(*) AS total_cursos
                    FROM cursos
                    GROUP BY instructor_id
                 ) cursos ON cursos.instructor_id = u.id
                 LEFT JOIN (
                    SELECT c.instructor_id, COUNT(DISTINCT ca.alumno_id) AS total_alumnos
                    FROM cursos c
                    INNER JOIN curso_alumnos ca ON ca.curso_id = c.id AND ca.estado = 'aprobado'
                    GROUP BY c.instructor_id
                 ) alumnos ON alumnos.instructor_id = u.id
                 LEFT JOIN (
                    SELECT c.instructor_id, COUNT(l.id) AS total_lecciones
                    FROM cursos c
                    LEFT JOIN lecciones l ON l.curso_id = c.id
                    GROUP BY c.instructor_id
                 ) lecciones ON lecciones.instructor_id = u.id
                 WHERE u.rol = 'ponente'
                 ORDER BY u.nombre, u.apellidos"
            );
            $resumen_docentes_director = $conexion->query(
                "SELECT
                    COUNT(*) AS total_docentes,
                    COUNT(DISTINCT c.instructor_id) AS docentes_con_curso,
                    (SELECT COUNT(*) FROM cursos WHERE instructor_id IS NULL) AS cursos_sin_docente
                 FROM usuarios u
                 LEFT JOIN cursos c ON c.instructor_id = u.id
                 WHERE u.rol = 'ponente'"
            )->fetch_assoc();
            ?>
            <section class="director-dashboard director-staff-page">
                <header class="director-welcome director-staff-welcome">
                    <div class="director-welcome-copy">
                        <span class="section-eyebrow">EQUIPO ACADÉMICO</span>
                        <h1>Cuerpo Docente</h1>
                        <p>Consulta la participación de cada docente, los cursos que imparte y los alumnos que acompaña.</p>
                    </div>
                    <div class="director-welcome-mark" aria-hidden="true">👨‍🏫</div>
                </header>

                <div class="director-metrics-grid director-staff-metrics">
                    <article class="director-metric-card director-metric-card--teachers">
                        <span class="director-metric-icon" aria-hidden="true">👨‍🏫</span>
                        <div>
                            <span>Docentes registrados</span>
                            <strong><?php echo number_format((int) $resumen_docentes_director["total_docentes"]); ?></strong>
                            <small>Personal académico</small>
                        </div>
                    </article>
                    <article class="director-metric-card director-metric-card--courses">
                        <span class="director-metric-icon" aria-hidden="true">📚</span>
                        <div>
                            <span>Con cursos asignados</span>
                            <strong><?php echo number_format((int) $resumen_docentes_director["docentes_con_curso"]); ?></strong>
                            <small>Docentes activos en catálogo</small>
                        </div>
                    </article>
                    <article class="director-metric-card director-metric-card--pending">
                        <span class="director-metric-icon" aria-hidden="true">🧭</span>
                        <div>
                            <span>Cursos sin docente</span>
                            <strong><?php echo number_format((int) $resumen_docentes_director["cursos_sin_docente"]); ?></strong>
                            <small>Requieren asignación</small>
                        </div>
                    </article>
                </div>

                <section class="director-panel director-staff-panel">
                    <div class="director-panel-heading director-staff-heading">
                        <div>
                            <span class="section-eyebrow">DIRECTORIO</span>
                            <h2>Docentes de la institución</h2>
                        </div>
                        <label class="director-staff-search">
                            <span aria-hidden="true">🔎</span>
                            <input type="search" data-director-staff-search placeholder="Buscar por nombre o correo" aria-label="Buscar docentes por nombre o correo">
                        </label>
                    </div>

                    <?php if ($docentes_director->num_rows > 0): ?>
                        <div class="director-staff-grid" data-director-staff-list>
                            <?php while ($docente = $docentes_director->fetch_assoc()): ?>
                                <?php
                                $foto_docente = null;
                                if (!empty($docente["foto"])) {
                                    $foto_docente = url_https_valida($docente["foto"])
                                        ? $docente["foto"]
                                        : (basename($docente["foto"]) === $docente["foto"] && file_exists(__DIR__ . "/uploads/" . $docente["foto"])
                                            ? "uploads/" . $docente["foto"]
                                            : null);
                                }
                                $nombre_docente = trim($docente["nombre"] . " " . ($docente["apellidos"] ?? ""));
                                preg_match('/^./u', $docente["nombre"], $inicial_docente_match);
                                $inicial_docente = $inicial_docente_match[0] ?? "D";
                                ?>
                                <article class="director-staff-card" data-director-staff-card data-search-text="<?php echo htmlspecialchars($nombre_docente . " " . $docente["email"], ENT_QUOTES, "UTF-8"); ?>">
                                    <div class="director-staff-card-top">
                                        <?php if ($foto_docente): ?>
                                            <img class="director-staff-avatar" src="<?php echo htmlspecialchars($foto_docente, ENT_QUOTES, "UTF-8"); ?>" alt="Foto de <?php echo htmlspecialchars($nombre_docente, ENT_QUOTES, "UTF-8"); ?>">
                                        <?php else: ?>
                                            <span class="director-staff-avatar director-staff-avatar--initial" aria-hidden="true"><?php echo htmlspecialchars(strtoupper($inicial_docente), ENT_QUOTES, "UTF-8"); ?></span>
                                        <?php endif; ?>
                                        <span class="director-staff-status <?php echo (int) $docente["total_cursos"] > 0 ? "is-active" : "is-unassigned"; ?>">
                                            <?php echo (int) $docente["total_cursos"] > 0 ? "Con cursos" : "Sin cursos"; ?>
                                        </span>
                                    </div>
                                    <div class="director-staff-identity">
                                        <h3><?php echo htmlspecialchars($nombre_docente, ENT_QUOTES, "UTF-8"); ?></h3>
                                        <a href="mailto:<?php echo htmlspecialchars($docente["email"], ENT_QUOTES, "UTF-8"); ?>"><?php echo htmlspecialchars($docente["email"], ENT_QUOTES, "UTF-8"); ?></a>
                                        <p><?php echo htmlspecialchars(trim($docente["descripcion"] ?? "") ?: "Docente de la institución.", ENT_QUOTES, "UTF-8"); ?></p>
                                    </div>
                                    <div class="director-staff-stats">
                                        <div><strong><?php echo number_format((int) $docente["total_cursos"]); ?></strong><span>Cursos</span></div>
                                        <div><strong><?php echo number_format((int) $docente["total_alumnos"]); ?></strong><span>Alumnos</span></div>
                                        <div><strong><?php echo number_format((int) $docente["total_lecciones"]); ?></strong><span>Lecciones</span></div>
                                    </div>
                                    <a class="director-staff-action" href="dashboard.php?seccion=cursos">Explorar cursos del equipo <span aria-hidden="true">&rarr;</span></a>
                                </article>
                            <?php endwhile; ?>
                        </div>
                        <p class="director-staff-no-results" data-director-staff-empty hidden>No se encontraron docentes con esa búsqueda.</p>
                    <?php else: ?>
                        <div class="director-empty-state">
                            <span aria-hidden="true">👨‍🏫</span>
                            <div>
                                <strong>Aún no hay docentes registrados</strong>
                                <p>Cuando se agreguen perfiles docentes, aparecerán aquí con su actividad académica.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </section>
            </section>

        <?php elseif (in_array($rol_usuario, ["ponente", "director", "superadmin"], true) && $seccion === 'mis_alumnos'): ?>
            <?php
            if ($rol_usuario === "ponente") {
                $stmt_alumnos = $conexion->prepare(
                    "SELECT u.id, u.nombre, u.apellidos, u.email,
                            COUNT(DISTINCT ca.curso_id) AS cursos_count,
                            GROUP_CONCAT(DISTINCT c.titulo ORDER BY c.titulo SEPARATOR ' · ') AS cursos
                     FROM curso_alumnos ca
                     INNER JOIN cursos c ON c.id = ca.curso_id
                     INNER JOIN usuarios u ON u.id = ca.alumno_id
                     WHERE c.instructor_id = ? AND ca.estado = 'aprobado'
                     GROUP BY u.id, u.nombre, u.apellidos, u.email
                     ORDER BY u.nombre, u.apellidos"
                );
                $stmt_alumnos->bind_param("i", $id_usuario);
                $stmt_alumnos->execute();
                $alumnos = $stmt_alumnos->get_result();
                $total_alumnos_docente = $alumnos->num_rows;
            } else {
                $pendientes = $conexion->query(
                    "SELECT ca.id, ca.curso_id, c.titulo AS curso, u.id AS alumno_id, CONCAT(u.nombre, ' ', u.apellidos) AS alumno, u.email,
                            ca.estado, ca.aprobado_por_director, ca.aprobado_por_admin
                     FROM curso_alumnos ca
                     INNER JOIN cursos c ON c.id = ca.curso_id
                     INNER JOIN usuarios u ON u.id = ca.alumno_id
                     WHERE ca.estado IN ('pendiente', 'aprobado')
                     ORDER BY ca.id DESC"
                );
                $alumnos = $conexion->query(
                    "SELECT u.id, u.nombre, u.apellidos, u.email,
                            COUNT(DISTINCT ca.curso_id) AS cursos_count,
                            GROUP_CONCAT(DISTINCT c.titulo ORDER BY c.titulo SEPARATOR ' · ') AS cursos
                     FROM curso_alumnos ca
                     INNER JOIN cursos c ON c.id = ca.curso_id
                     INNER JOIN usuarios u ON u.id = ca.alumno_id
                     WHERE ca.estado = 'aprobado'
                     GROUP BY u.id, u.nombre, u.apellidos, u.email
                     ORDER BY u.nombre, u.apellidos"
                );
                $total_alumnos_docente = $alumnos->num_rows;
            }
            ?>
            <?php
            $total_cursos_matriculas = $rol_usuario === "director"
                ? (int) $conexion->query("SELECT COUNT(*) AS total FROM cursos")->fetch_assoc()["total"]
                : (int) $conexion->query("SELECT COUNT(*) AS total FROM cursos WHERE instructor_id = " . (int) $id_usuario)->fetch_assoc()["total"];
            $total_pendientes_matriculas = $rol_usuario === "ponente"
                ? 0
                : (int) $conexion->query("SELECT COUNT(*) AS total FROM curso_alumnos WHERE estado = 'pendiente'")->fetch_assoc()["total"];
            ?>
            <section class="students-panel <?php echo $rol_usuario === "director" ? "director-students-panel" : ""; ?>">
                <div class="students-header">
                    <div>
                        <span class="section-eyebrow"><?php echo $rol_usuario === "ponente" ? "MIS ALUMNOS" : ($rol_usuario === "director" ? "CONTROL ACADÉMICO" : "VALIDACIÓN DE MATRÍCULAS"); ?></span>
                        <h1><?php echo $rol_usuario === "ponente" ? "👨‍🎓 Alumnos inscritos" : ($rol_usuario === "director" ? "👨‍🎓 Gestión de matrículas" : "👨‍🎓 Validación de estudiantes"); ?></h1>
                        <p><?php echo $rol_usuario === "ponente"
                            ? "Consulta quiénes están cursando tus materias y en qué cursos están matriculados."
                            : ($rol_usuario === "director"
                                ? "Supervisa las solicitudes y registra la aprobación académica de las matrículas."
                                : "Revisa las solicitudes de matriculación y su estado de aprobación."); ?></p>
                    </div>
                    <span class="mini-status-pill"><?php echo number_format((int) $total_alumnos_docente); ?> alumnos</span>
                </div>

                <?php if ($rol_usuario !== "ponente" && $pendientes && $pendientes->num_rows > 0): ?>
                    <div class="approval-list-card">
                        <h2><?php echo $rol_usuario === "director" ? "Solicitudes de matrícula" : "Solicitudes pendientes"; ?></h2>
                        <?php while ($solicitud = $pendientes->fetch_assoc()): ?>
                            <div class="approval-row">
                                <div>
                                    <strong><?php echo htmlspecialchars($solicitud["alumno"], ENT_QUOTES, "UTF-8"); ?></strong>
                                    <small><?php echo htmlspecialchars($solicitud["email"], ENT_QUOTES, "UTF-8"); ?></small>
                                    <p><?php echo htmlspecialchars($solicitud["curso"], ENT_QUOTES, "UTF-8"); ?></p>
                                </div>
                                <div class="approval-status">
                                    <span class="student-tag"><?php echo $solicitud["estado"] === "aprobado" ? "Aprobada" : "Pendiente"; ?></span>
                                    <div class="approval-meta">
                                        <span>Director: <?php echo (int) $solicitud["aprobado_por_director"] ? '✓' : '—'; ?></span>
                                        <span>Admin: <?php echo (int) $solicitud["aprobado_por_admin"] ? '✓' : '—'; ?></span>
                                    </div>
                                    <form method="POST" action="dashboard.php?seccion=mis_alumnos">
                                        <?php echo csrf_field(); ?>
                                        <input type="hidden" name="action" value="approve_student_enrollment">
                                        <input type="hidden" name="inscripcion_id" value="<?php echo (int) $solicitud["id"]; ?>">
                                        <button type="submit" class="btn-primary-glow" style="width:auto; padding:10px 14px;"><?php echo (int) $solicitud["aprobado_por_director"] ? "Registrar aprobación" : "Aprobar matrícula"; ?></button>
                                    </form>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php endif; ?>

                <div class="students-summary-grid">
                    <article class="student-summary-card">
                        <span class="report-stat-icon">🎓</span>
                        <div>
                            <small>Total</small>
                            <strong><?php echo number_format((int) $total_alumnos_docente); ?></strong>
                        </div>
                    </article>
                    <article class="student-summary-card">
                        <span class="report-stat-icon">📚</span>
                        <div>
                            <small><?php echo $rol_usuario === "director" ? "Cursos de la institución" : "Cursos"; ?></small>
                            <strong><?php echo number_format($total_cursos_matriculas); ?></strong>
                        </div>
                    </article>
                    <?php if ($rol_usuario === "director"): ?>
                        <article class="student-summary-card student-summary-card--pending">
                            <span class="report-stat-icon">⏳</span>
                            <div>
                                <small>Matrículas pendientes</small>
                                <strong><?php echo number_format($total_pendientes_matriculas); ?></strong>
                            </div>
                        </article>
                    <?php endif; ?>
                </div>

                <?php if ($rol_usuario === "ponente"): ?>
                    <?php if ($alumnos->num_rows > 0): ?>
                        <div class="student-list-card">
                            <?php while ($alumno = $alumnos->fetch_assoc()): ?>
                                <div class="student-row">
                                    <div class="student-profile">
                                        <?php
                                        $foto_alumno = null;
                                        if (!empty($alumno['foto'] ?? '')) {
                                            $foto_alumno = url_https_valida($alumno['foto']) ? $alumno['foto'] : (file_exists(__DIR__ . '/uploads/' . basename($alumno['foto'])) ? 'uploads/' . basename($alumno['foto']) : null);
                                        }
                                        ?>
                                        <?php if ($foto_alumno): ?>
                                            <img src="<?php echo htmlspecialchars($foto_alumno, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto de <?php echo htmlspecialchars(trim(($alumno['nombre'] ?? '') . ' ' . ($alumno['apellidos'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" class="student-avatar">
                                        <?php else: ?>
                                            <div class="student-avatar initial"><?php echo htmlspecialchars(strtoupper(substr($alumno['nombre'] ?? 'A', 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?php echo htmlspecialchars(trim(($alumno['nombre'] ?? '') . ' ' . ($alumno['apellidos'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <small><?php echo htmlspecialchars($alumno['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small>
                                        </div>
                                    </div>
                                    <div class="student-course-list">
                                        <span class="student-tag"><?php echo (int) $alumno['cursos_count']; ?> cursos</span>
                                        <p><?php echo htmlspecialchars($alumno['cursos'] ?? 'Sin cursos asignados', ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                </div>
                            <?php endwhile; ?>
                        </div>
                    <?php else: ?>
                        <div class="reports-empty-state student-empty-state">
                            <span aria-hidden="true">👨‍🎓</span>
                            <h3>Aún no tienes alumnos inscritos</h3>
                            <p>Cuando un estudiante se matricule en alguno de tus cursos, aparecerá aquí.</p>
                        </div>
                    <?php endif; ?>
                <?php elseif ($alumnos->num_rows > 0): ?>
                    <div class="student-list-card">
                        <?php while ($alumno = $alumnos->fetch_assoc()): ?>
                            <div class="student-row">
                                <div class="student-profile">
                                    <?php
                                    $foto_alumno = null;
                                    if (!empty($alumno['foto'] ?? '')) {
                                        $foto_alumno = url_https_valida($alumno['foto']) ? $alumno['foto'] : (file_exists(__DIR__ . '/uploads/' . basename($alumno['foto'])) ? 'uploads/' . basename($alumno['foto']) : null);
                                    }
                                    ?>
                                    <?php if ($foto_alumno): ?>
                                        <img src="<?php echo htmlspecialchars($foto_alumno, ENT_QUOTES, 'UTF-8'); ?>" alt="Foto de <?php echo htmlspecialchars(trim(($alumno['nombre'] ?? '') . ' ' . ($alumno['apellidos'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" class="student-avatar">
                                    <?php else: ?>
                                        <div class="student-avatar initial"><?php echo htmlspecialchars(strtoupper(substr($alumno['nombre'] ?? 'A', 0, 1)), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <?php endif; ?>
                                    <div>
                                        <strong><?php echo htmlspecialchars(trim(($alumno['nombre'] ?? '') . ' ' . ($alumno['apellidos'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <small><?php echo htmlspecialchars($alumno['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small>
                                    </div>
                                </div>
                                <div class="student-course-list">
                                    <span class="student-tag"><?php echo (int) $alumno['cursos_count']; ?> cursos</span>
                                    <p><?php echo htmlspecialchars($alumno['cursos'] ?? 'Sin cursos asignados', ENT_QUOTES, 'UTF-8'); ?></p>
                                </div>
                            </div>
                        <?php endwhile; ?>
                    </div>
                <?php else: ?>
                    <div class="reports-empty-state student-empty-state">
                        <span aria-hidden="true">👨‍🎓</span>
                        <h3>No hay matrículas validadas aún</h3>
                        <p>Las inscripciones aprobadas con director y administrador aparecerán aquí.</p>
                    </div>
                <?php endif; ?>
            </section>

        <!-- SECCIÓN: CURSOS INTERACTIVOS -->
        <?php elseif ($seccion === 'cursos'): ?>
            <?php
            if ($rol_usuario === "ponente") {
                $stmt_courses = $conexion->prepare(
                    "SELECT c.id, c.titulo, c.descripcion, c.categoria, c.nivel, c.foto_curso, c.banner_curso, c.instructor_id, TRIM(CONCAT(u.nombre, ' ', COALESCE(u.apellidos, ''))) AS instructor_nombre
                     FROM cursos c LEFT JOIN usuarios u ON c.instructor_id = u.id
                     WHERE c.instructor_id = ? ORDER BY c.id DESC"
                );
                $stmt_courses->bind_param("i", $id_usuario);
                $stmt_courses->execute();
                $cursos = $stmt_courses->get_result();
            } else {
                $cursos = $conexion->query(
                    "SELECT c.id, c.titulo, c.descripcion, c.categoria, c.nivel, c.foto_curso, c.banner_curso, c.instructor_id, TRIM(CONCAT(u.nombre, ' ', COALESCE(u.apellidos, ''))) AS instructor_nombre
                     FROM cursos c LEFT JOIN usuarios u ON c.instructor_id = u.id
                     ORDER BY c.id DESC"
                );
            }
            if ($rol_usuario === "superadmin") {
                $docentes_disponibles = $conexion->query(
                    "SELECT id, nombre, apellidos FROM usuarios WHERE rol = 'ponente' ORDER BY nombre, apellidos"
                )->fetch_all(MYSQLI_ASSOC);
            }
            $alumnos_disponibles = $conexion->query(
                "SELECT id, nombre, apellidos FROM usuarios WHERE rol = 'alumno' ORDER BY nombre, apellidos"
            )->fetch_all(MYSQLI_ASSOC);
            $metricas_director_cursos = null;
            if ($rol_usuario === "director") {
                $metricas_director_cursos = $conexion->query(
                    "SELECT
                        COUNT(*) AS total,
                        COALESCE(SUM(instructor_id IS NOT NULL), 0) AS asignados,
                        COALESCE(SUM(instructor_id IS NULL), 0) AS sin_docente,
                        (SELECT COUNT(*) FROM usuarios WHERE rol = 'ponente') AS docentes
                     FROM cursos"
                )->fetch_assoc();
            }
            ?>
            <div class="course-management-shell <?php echo $rol_usuario === "director" ? "director-course-shell" : ""; ?>">
                <div class="course-management-header">
                    <div>
                        <span class="section-eyebrow"><?php echo $rol_usuario === "director" ? "SUPERVISIÓN ACADÉMICA" : "GESTIÓN DE CURSOS"; ?></span>
                        <h1><?php echo $rol_usuario === "director" ? "📚 Oferta académica" : "📚 Catálogo General de Cursos"; ?></h1>
                        <p><?php echo $rol_usuario === "director"
                            ? "Supervisa la asignación docente y el contenido publicado en cada curso."
                            : "Accede al contenido dinámico y lecciones activas."; ?></p>
                    </div>
                    <div class="course-management-actions">
                        <span class="mini-status-pill"><?php echo $cursos->num_rows; ?> cursos</span>
                        <a href="dashboard.php?seccion=reportes" class="reports-link">Ver reportes &rarr;</a>
                    </div>
                </div>

                <?php if ($rol_usuario === "director"): ?>
                    <div class="course-management-metrics director-course-metrics">
                        <article class="course-metric-card">
                            <span class="report-stat-icon">📚</span>
                            <div><small>Cursos registrados</small><strong><?php echo number_format((int) $metricas_director_cursos["total"]); ?></strong></div>
                        </article>
                        <article class="course-metric-card">
                            <span class="report-stat-icon">✅</span>
                            <div><small>Con docente asignado</small><strong><?php echo number_format((int) $metricas_director_cursos["asignados"]); ?></strong></div>
                        </article>
                        <article class="course-metric-card">
                            <span class="report-stat-icon">🧭</span>
                            <div><small>Sin docente</small><strong><?php echo number_format((int) $metricas_director_cursos["sin_docente"]); ?></strong></div>
                        </article>
                        <article class="course-metric-card">
                            <span class="report-stat-icon">👨‍🏫</span>
                            <div><small>Docentes registrados</small><strong><?php echo number_format((int) $metricas_director_cursos["docentes"]); ?></strong></div>
                        </article>
                    </div>
                <?php else: ?>
                <div class="course-management-metrics">
                    <article class="course-metric-card">
                        <span class="report-stat-icon">📚</span>
                        <div>
                            <small>Total</small>
                            <strong><?php echo number_format((int) $cursos->num_rows); ?></strong>
                        </div>
                    </article>
                    <article class="course-metric-card">
                        <span class="report-stat-icon">🎯</span>
                        <div>
                            <small>Activos</small>
                            <strong><?php echo number_format((int) $cursos->num_rows); ?></strong>
                        </div>
                    </article>
                    <article class="course-metric-card">
                        <span class="report-stat-icon">👨‍🏫</span>
                        <div>
                            <small>Docentes</small>
                            <strong><?php echo $rol_usuario === "superadmin" ? number_format((int) $conexion->query("SELECT COUNT(*) AS total FROM usuarios WHERE rol = 'ponente'")->fetch_assoc()['total']) : 'Tu equipo'; ?></strong>
                        </div>
                    </article>
                </div>
                <?php endif; ?>
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

                            <?php if ($rol_usuario === "ponente" || $rol_usuario === "superadmin"): ?>
                                <div class="course-card-actions">
                                    <button type="button" class="btn-secondary-outline" onclick="openLessonModal(<?php echo (int) $curso["id"]; ?>, <?php echo htmlspecialchars(json_encode($curso['titulo']), ENT_QUOTES, 'UTF-8'); ?>)">📝 Agregar lección</button>
                                    <a href="ver_curso.php?id=<?php echo (int) $curso["id"]; ?>#calificaciones" class="btn-secondary-outline" style="text-align:center; text-decoration:none;">✓ Calificar</a>
                                </div>
                            <?php endif; ?>

                            <?php if (in_array($rol_usuario, ["ponente", "director", "superadmin"], true)): ?>
                                <form method="POST" action="dashboard.php?seccion=cursos" class="course-assignment-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="enroll_student">
                                    <input type="hidden" name="curso_id" value="<?php echo (int) $curso["id"]; ?>">
                                    <label for="student-<?php echo (int) $curso["id"]; ?>">Inscribir alumno</label>
                                    <select class="input-field" id="student-<?php echo (int) $curso["id"]; ?>" name="alumno_id" required>
                                        <option value="">Selecciona un alumno</option>
                                        <?php foreach ($alumnos_disponibles as $alumno): ?>
                                            <option value="<?php echo (int) $alumno["id"]; ?>">
                                                <?php echo htmlspecialchars(trim($alumno["nombre"] . " " . ($alumno["apellidos"] ?? "")), ENT_QUOTES, "UTF-8"); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn-secondary-outline">Guardar matrícula</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($rol_usuario === "superadmin"): ?>
                                <form method="POST" action="dashboard.php?seccion=cursos" class="course-assignment-form">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action" value="assign_course">
                                    <input type="hidden" name="curso_id" value="<?php echo (int) $curso["id"]; ?>">
                                    <label for="instructor-<?php echo (int) $curso["id"]; ?>">Asignar docente</label>
                                    <select class="input-field" id="instructor-<?php echo (int) $curso["id"]; ?>" name="instructor_id">
                                        <option value="">Sin asignar</option>
                                        <?php foreach ($docentes_disponibles as $docente): ?>
                                            <option value="<?php echo (int) $docente["id"]; ?>" <?php echo (int) $curso["instructor_id"] === (int) $docente["id"] ? "selected" : ""; ?>>
                                                <?php echo htmlspecialchars(trim($docente["nombre"] . " " . ($docente["apellidos"] ?? "")), ENT_QUOTES, "UTF-8"); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn-secondary-outline">Guardar asignación</button>
                                </form>
                            <?php endif; ?>
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

            $total_alumnos_reportes = $rol_usuario === "director"
                ? $conexion->query("SELECT COUNT(DISTINCT alumno_id) AS total FROM curso_alumnos WHERE estado = 'aprobado'")->fetch_assoc()["total"]
                : $conexion->query("SELECT COUNT(*) AS total FROM usuarios WHERE rol = 'alumno'")->fetch_assoc()["total"];
            $total_docentes_reportes = $rol_usuario === "director"
                ? (int) $conexion->query("SELECT COUNT(*) AS total FROM usuarios WHERE rol = 'ponente'")->fetch_assoc()["total"]
                : 0;

            $resumen_docente = null;
            if ($rol_usuario === "ponente") {
                $resumen_docente = [
                    "alumnos" => (int) $conexion->query(
                        "SELECT COUNT(DISTINCT ca.alumno_id) AS total
                         FROM curso_alumnos ca
                         INNER JOIN cursos c ON c.id = ca.curso_id
                         WHERE c.instructor_id = " . (int) $id_usuario
                    )->fetch_assoc()["total"],
                    "promedio_general" => (float) $conexion->query(
                        "SELECT COALESCE(ROUND(AVG(ca2.calificacion), 2), 0) AS promedio
                         FROM calificaciones_actividad ca2
                         INNER JOIN leccion_actividades la ON la.id = ca2.actividad_id
                         INNER JOIN cursos c ON c.id = la.curso_id
                         WHERE c.instructor_id = " . (int) $id_usuario
                    )->fetch_assoc()["promedio"],
                    "calificaciones" => (int) $conexion->query(
                        "SELECT COUNT(*) AS total
                         FROM calificaciones_actividad ca2
                         INNER JOIN leccion_actividades la ON la.id = ca2.actividad_id
                         INNER JOIN cursos c ON c.id = la.curso_id
                         WHERE c.instructor_id = " . (int) $id_usuario
                    )->fetch_assoc()["total"],
                ];
            }

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
            $primer_curso_id = 0;
            if ($rol_usuario === "ponente") {
                $primer_curso_id = (int) $conexion->query(
                    "SELECT c.id FROM cursos c WHERE c.instructor_id = " . (int) $id_usuario . " ORDER BY c.id DESC LIMIT 1"
                )->fetch_assoc()["id"];
            }
            ?>
            <section class="reports-section <?php echo $rol_usuario === "director" ? "director-reports" : ""; ?>">
                <div class="reports-hero">
                    <div>
                        <span class="section-eyebrow"><?php echo $rol_usuario === "director" ? "INDICADORES INSTITUCIONALES" : "RESUMEN ACADÉMICO"; ?></span>
                        <h1><?php echo $rol_usuario === "director" ? "📈 Reporte institucional" : "📈 Reportes"; ?></h1>
                        <p>
                            <?php echo $rol_usuario === "ponente"
                                ? "Consulta la actividad de tus cursos y sus contenidos con un panorama claro del rendimiento actual."
                                : ($rol_usuario === "director"
                                    ? "Consulta el alcance de las matrículas aprobadas y el contenido académico publicado."
                                    : "Consulta un resumen visual de los cursos y contenidos publicados en la plataforma."); ?>
                        </p>
                    </div>
                    <div class="reports-badges">
                        <span class="report-chip success">Activo</span>
                        <span class="report-chip">Plataforma</span>
                        <span class="report-chip">Seguimiento</span>
                    </div>
                </div>

                <div class="report-stats-grid">
                    <article class="report-stat-card report-stat-card--violet">
                        <span class="report-stat-icon">📚</span>
                        <div>
                            <span><?php echo $rol_usuario === "ponente" ? "Mis cursos" : "Cursos disponibles"; ?></span>
                            <strong><?php echo number_format((int) $resumen_reportes["cursos"]); ?></strong>
                            <small>En catálogo</small>
                        </div>
                    </article>
                    <article class="report-stat-card report-stat-card--cyan">
                        <span class="report-stat-icon">📝</span>
                        <div>
                            <span>Lecciones publicadas</span>
                            <strong><?php echo number_format((int) $resumen_reportes["lecciones"]); ?></strong>
                            <small>Contenido total</small>
                        </div>
                    </article>
                    <article class="report-stat-card report-stat-card--gold">
                        <span class="report-stat-icon">🎓</span>
                        <div>
                            <span><?php echo $rol_usuario === "ponente" ? "Alumnos en tus cursos" : ($rol_usuario === "director" ? "Alumnos matriculados" : "Alumnos registrados"); ?></span>
                            <strong><?php echo number_format((int) ($resumen_docente["alumnos"] ?? $total_alumnos_reportes)); ?></strong>
                            <small><?php echo $rol_usuario === "ponente" || $rol_usuario === "director" ? "Matrículas aprobadas" : "Usuarios activos"; ?></small>
                        </div>
                    </article>
                    <?php if ($rol_usuario === "director"): ?>
                        <article class="report-stat-card report-stat-card--violet">
                            <span class="report-stat-icon">👨‍🏫</span>
                            <div>
                                <span>Docentes registrados</span>
                                <strong><?php echo number_format($total_docentes_reportes); ?></strong>
                                <small>Equipo académico</small>
                            </div>
                        </article>
                    <?php endif; ?>
                    <?php if ($rol_usuario === "ponente"): ?>
                        <article class="report-stat-card report-stat-card--violet">
                            <span class="report-stat-icon">📊</span>
                            <div>
                                <span>Promedio general</span>
                                <strong><?php echo number_format((float) ($resumen_docente["promedio_general"] ?? 0), 2); ?>%</strong>
                                <small>Calificaciones registradas</small>
                            </div>
                        </article>
                    <?php endif; ?>
                </div>

                <?php if ($rol_usuario === "ponente"): ?>
                    <div class="teacher-insights-panel">
                        <div class="teacher-insights-header">
                            <div>
                                <span class="section-eyebrow">INSIGHT DOCENTE</span>
                                <h3>Rendimiento de tus actividades</h3>
                            </div>
                            <?php if ($primer_curso_id > 0): ?>
                                <a href="ver_curso.php?id=<?php echo (int) $primer_curso_id; ?>#calificaciones" class="reports-link">Ir a calificaciones &rarr;</a>
                            <?php else: ?>
                                <span class="reports-link" style="opacity:0.65;">Aún no hay cursos</span>
                            <?php endif; ?>
                        </div>
                        <div class="teacher-insights-grid">
                            <div class="teacher-insight-item">
                                <span>Calificaciones enviadas</span>
                                <strong><?php echo number_format((int) ($resumen_docente["calificaciones"] ?? 0)); ?></strong>
                            </div>
                            <div class="teacher-insight-item">
                                <span>Alumnos con al menos una nota</span>
                                <strong><?php echo number_format((int) ($resumen_docente["alumnos"] ?? 0)); ?></strong>
                            </div>
                            <div class="teacher-insight-item">
                                <span>Seguimiento</span>
                                <strong><?php echo ((float) ($resumen_docente["promedio_general"] ?? 0) >= 70) ? "Estable" : "Requiere apoyo"; ?></strong>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

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
<div id="drawerOverlay" class="drawer-overlay" onclick="closeDrawer()" aria-hidden="true"></div>
<section id="userDrawer" class="drawer" role="dialog" aria-modal="true" aria-labelledby="drawerTitle" aria-hidden="true">
    <div>
        <div class="drawer-header">
            <h3 id="drawerTitle">Editar Usuario</h3>
            <button type="button" class="btn-close" onclick="closeDrawer()" aria-label="Cerrar ventana">&times;</button>
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
</section>
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
    var drawer = document.getElementById('userDrawer');
    var overlay = document.getElementById('drawerOverlay');
    var userId = document.getElementById('drawerUserId');
    var userName = document.getElementById('drawerUserName');
    var roleSelect = document.getElementById('drawerRoleSelect');

    if (!drawer || !overlay || !userId || !userName || !roleSelect) {
        console.error('No se pudo abrir la ventana de edición: faltan elementos del formulario.');
        return;
    }

    userId.value = id;
    userName.textContent = nombre;
    roleSelect.value = rol;
    overlay.classList.add('is-open');
    drawer.classList.add('is-open');
    overlay.setAttribute('aria-hidden', 'false');
    drawer.setAttribute('aria-hidden', 'false');
    roleSelect.focus();
}

function closeDrawer() {
    var drawer = document.getElementById('userDrawer');
    var overlay = document.getElementById('drawerOverlay');
    if (!drawer || !overlay) return;

    overlay.classList.remove('is-open');
    drawer.classList.remove('is-open');
    overlay.setAttribute('aria-hidden', 'true');
    drawer.setAttribute('aria-hidden', 'true');
}

var directorStaffSearch = document.querySelector('[data-director-staff-search]');
if (directorStaffSearch) {
    var directorStaffCards = Array.from(document.querySelectorAll('[data-director-staff-card]'));
    var directorStaffEmpty = document.querySelector('[data-director-staff-empty]');

    directorStaffSearch.addEventListener('input', function () {
        var term = directorStaffSearch.value.trim().toLocaleLowerCase();
        var visibleCount = 0;

        directorStaffCards.forEach(function (card) {
            var matches = card.dataset.searchText.toLocaleLowerCase().includes(term);
            card.hidden = !matches;
            if (matches) visibleCount++;
        });

        if (directorStaffEmpty) directorStaffEmpty.hidden = visibleCount > 0;
    });
}

var userSearch = document.querySelector('[data-user-search]');
if (userSearch) {
    var userRows = Array.from(document.querySelectorAll('[data-user-row]'));
    var userNoResults = document.querySelector('[data-user-no-results]');

    userSearch.addEventListener('input', function () {
        var term = userSearch.value.trim().toLocaleLowerCase();
        var visibleCount = 0;

        userRows.forEach(function (row) {
            var matches = row.dataset.searchText.toLocaleLowerCase().includes(term);
            row.hidden = !matches;
            if (matches) visibleCount++;
        });

        if (userNoResults) userNoResults.hidden = visibleCount > 0;
    });
}
</script>

<?php include_once "footer.php"; ?>