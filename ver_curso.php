<?php
session_start();
include_once "conexion.php";
require_once "helpers.php";
require_once "csrf.php";

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

$rol_usuario = $_SESSION["rol"] ?? "alumno";
$id_usuario = (int) ($_SESSION["usuario_id"] ?? 0);
$mensaje_exito = "";
$error_operacion = "";

function asegurar_tablas_calificaciones($conexion) {
    $conexion->query(
        "CREATE TABLE IF NOT EXISTS leccion_actividades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            curso_id INT NOT NULL,
            leccion_id INT NOT NULL,
            titulo VARCHAR(180) NOT NULL,
            descripcion TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_actividad_leccion (curso_id, leccion_id),
            KEY idx_actividad_curso (curso_id),
            KEY idx_actividad_leccion (leccion_id),
            CONSTRAINT fk_actividad_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
            CONSTRAINT fk_actividad_leccion FOREIGN KEY (leccion_id) REFERENCES lecciones(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $conexion->query(
        "CREATE TABLE IF NOT EXISTS calificaciones_actividad (
            id INT AUTO_INCREMENT PRIMARY KEY,
            actividad_id INT NOT NULL,
            alumno_id INT NOT NULL,
            calificacion DECIMAL(5,2) NOT NULL,
            comentarios TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_actividad_alumno (actividad_id, alumno_id),
            KEY idx_calificacion_alumno (alumno_id),
            CONSTRAINT fk_calificacion_actividad FOREIGN KEY (actividad_id) REFERENCES leccion_actividades(id) ON DELETE CASCADE,
            CONSTRAINT fk_calificacion_alumno FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function obtener_actividad_por_leccion($conexion, $curso_id, $leccion_id) {
    $stmt = $conexion->prepare("SELECT id, titulo, descripcion FROM leccion_actividades WHERE curso_id = ? AND leccion_id = ? LIMIT 1");
    $stmt->bind_param("ii", $curso_id, $leccion_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function obtener_calificaciones_por_actividad($conexion, $actividad_id) {
    $stmt = $conexion->prepare("SELECT alumno_id, calificacion, comentarios FROM calificaciones_actividad WHERE actividad_id = ?");
    $stmt->bind_param("i", $actividad_id);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $mapa = [];
    while ($fila = $resultado->fetch_assoc()) {
        $mapa[(int) $fila["alumno_id"]] = [
            "calificacion" => (float) $fila["calificacion"],
            "comentarios" => $fila["comentarios"] ?? "",
        ];
    }
    return $mapa;
}

asegurar_tablas_calificaciones($conexion);
asegurar_esquema_curso_alumnos($conexion);

$curso_id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$curso_id) {
    http_response_code(404);
    exit("No se encontró el curso solicitado.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && !validar_csrf()) {
    http_response_code(400);
    exit("La solicitud expiró o no es válida. Recarga la página e inténtalo de nuevo.");
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save_lesson_grades") {
    $puede_calificar = in_array($rol_usuario, ["ponente", "superadmin"], true) && ($rol_usuario === "superadmin" || $curso_id > 0);
    if (!$puede_calificar) {
        http_response_code(403);
        exit("No tienes permiso para calificar alumnos.");
    }

    $leccion_id = filter_input(INPUT_POST, "leccion_id", FILTER_VALIDATE_INT);
    if (!$leccion_id) {
        $error_operacion = "No se encontró la lección para guardar la calificación.";
    } else {
        $stmt_valid = $conexion->prepare("SELECT c.id, c.instructor_id FROM cursos c WHERE c.id = ?");
        $stmt_valid->bind_param("i", $curso_id);
        $stmt_valid->execute();
        $curso_validado = $stmt_valid->get_result()->fetch_assoc();

        if (!$curso_validado) {
            $error_operacion = "No se encontró el curso.";
        } elseif ($rol_usuario !== "superadmin" && (int) $curso_validado["instructor_id"] !== $id_usuario) {
            $error_operacion = "No tienes permiso para calificar este curso.";
        } else {
            $titulo_actividad = trim($_POST["actividad_titulo"] ?? "");
            $descripcion_actividad = trim($_POST["actividad_descripcion"] ?? "");
            $titulo_actividad = $titulo_actividad !== "" ? $titulo_actividad : "Actividad de la lección";

            $stmt_actividad = $conexion->prepare(
                "INSERT INTO leccion_actividades (curso_id, leccion_id, titulo, descripcion) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE titulo = VALUES(titulo), descripcion = VALUES(descripcion)"
            );
            $stmt_actividad->bind_param("iiss", $curso_id, $leccion_id, $titulo_actividad, $descripcion_actividad);
            $stmt_actividad->execute();

            $actividad = obtener_actividad_por_leccion($conexion, $curso_id, $leccion_id);
            if ($actividad) {
                $actividad_id = (int) $actividad["id"];
                $calificaciones_post = $_POST["calificacion"] ?? [];
                foreach ($calificaciones_post as $alumno_id_post => $valor) {
                    $alumno_id_post = filter_var($alumno_id_post, FILTER_VALIDATE_INT);
                    $valor = trim((string) $valor);
                    if (!$alumno_id_post || $valor === "") {
                        continue;
                    }

                    $calificacion_valor = (float) $valor;
                    if ($calificacion_valor < 0) {
                        $calificacion_valor = 0;
                    }
                    if ($calificacion_valor > 100) {
                        $calificacion_valor = 100;
                    }

                    $comentario = trim((string) ($_POST["comentario"][ $alumno_id_post ] ?? ""));
                    $stmt_grado = $conexion->prepare(
                        "INSERT INTO calificaciones_actividad (actividad_id, alumno_id, calificacion, comentarios) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE calificacion = VALUES(calificacion), comentarios = VALUES(comentarios), created_at = CURRENT_TIMESTAMP"
                    );
                    $stmt_grado->bind_param("iids", $actividad_id, $alumno_id_post, $calificacion_valor, $comentario);
                    $stmt_grado->execute();
                }
                $mensaje_exito = "Las calificaciones de la lección se guardaron correctamente.";
            }
        }
    }
}

$stmt = $conexion->prepare(
    "SELECT c.*, TRIM(CONCAT(u.nombre, ' ', COALESCE(u.apellidos, ''))) AS instructor_nombre
     FROM cursos c
     LEFT JOIN usuarios u ON c.instructor_id = u.id
     WHERE c.id = ?"
);
$stmt->bind_param("i", $curso_id);
$stmt->execute();
$curso = $stmt->get_result()->fetch_assoc();

if (!$curso) {
    http_response_code(404);
    exit("No se encontró el curso solicitado.");
}

$stmt_l = $conexion->prepare("SELECT id, titulo, contenido, duracion, video_url AS material_url FROM lecciones WHERE curso_id = ? ORDER BY id ASC");
$stmt_l->bind_param("i", $curso_id);
$stmt_l->execute();
$lecciones = $stmt_l->get_result()->fetch_all(MYSQLI_ASSOC);
$primera_leccion = $lecciones[0] ?? null;

$foto_curso = url_https_valida($curso["foto_curso"] ?? null) ? $curso["foto_curso"] : "";
$banner_curso = url_https_valida($curso["banner_curso"] ?? null) ? $curso["banner_curso"] : "";

$puede_calificar = in_array($rol_usuario, ["ponente", "superadmin"], true) && ($rol_usuario === "superadmin" || (int) $curso["instructor_id"] === $id_usuario);

$stmt_alumnos = $conexion->prepare(
    "SELECT u.id, TRIM(CONCAT(u.nombre, ' ', COALESCE(u.apellidos, ''))) AS nombre_completo, u.email
     FROM curso_alumnos ca
     INNER JOIN usuarios u ON u.id = ca.alumno_id
     WHERE ca.curso_id = ? AND ca.estado = 'aprobado' AND u.rol = 'alumno'
     ORDER BY u.nombre, u.apellidos"
);
$stmt_alumnos->bind_param("i", $curso_id);
$stmt_alumnos->execute();
$alumnos = $stmt_alumnos->get_result()->fetch_all(MYSQLI_ASSOC);

$filtro_alumno = filter_input(INPUT_GET, "alumno_filtro", FILTER_VALIDATE_INT);
$filtro_leccion = filter_input(INPUT_GET, "leccion_filtro", FILTER_VALIDATE_INT);

$lecciones_filtradas = [];
foreach ($lecciones as $leccion) {
    if ($filtro_leccion && (int) $leccion["id"] !== (int) $filtro_leccion) {
        continue;
    }
    $lecciones_filtradas[] = $leccion;
}

$alumnos_filtrados = [];
foreach ($alumnos as $alumno) {
    if ($filtro_alumno && (int) $alumno["id"] !== (int) $filtro_alumno) {
        continue;
    }
    $alumnos_filtrados[] = $alumno;
}

$actividades_por_leccion = [];
$calificaciones_por_actividad = [];
foreach ($lecciones_filtradas as $leccion) {
    $actividad = obtener_actividad_por_leccion($conexion, $curso_id, (int) $leccion["id"]);
    $actividades_por_leccion[(int) $leccion["id"]] = $actividad;
    if ($actividad) {
        $calificaciones_por_actividad[(int) $leccion["id"]] = obtener_calificaciones_por_actividad($conexion, (int) $actividad["id"]);
    }
}

$promedios_alumnos = [];
$promedio_curso_total = 0;
$total_calificaciones = 0;
$promedio_por_leccion = [];

foreach ($lecciones_filtradas as $leccion) {
    $leccion_id = (int) $leccion["id"];
    $calificaciones_leccion = $calificaciones_por_actividad[$leccion_id] ?? [];
    if (!empty($calificaciones_leccion)) {
        $valores_leccion = [];
        foreach ($calificaciones_leccion as $calificacion) {
            if ($filtro_alumno && (int) $calificacion["alumno_id"] !== (int) $filtro_alumno) {
                continue;
            }
            $valores_leccion[] = (float) $calificacion["calificacion"];
            $promedio_curso_total += (float) $calificacion["calificacion"];
            $total_calificaciones++;
        }
        $promedio_por_leccion[$leccion_id] = count($valores_leccion) ? round(array_sum($valores_leccion) / count($valores_leccion), 2) : 0;
    } else {
        $promedio_por_leccion[$leccion_id] = 0;
    }
}

foreach ($alumnos_filtrados as $alumno) {
    $alumno_id = (int) $alumno["id"];
    $suma = 0;
    $conteo = 0;

    foreach ($lecciones_filtradas as $leccion) {
        $leccion_id = (int) $leccion["id"];
        $calificaciones_leccion = $calificaciones_por_actividad[$leccion_id] ?? [];
        if (isset($calificaciones_leccion[$alumno_id])) {
            $suma += (float) $calificaciones_leccion[$alumno_id]["calificacion"];
            $conteo++;
        }
    }

    $promedios_alumnos[] = [
        "alumno_id" => $alumno_id,
        "nombre" => $alumno["nombre_completo"],
        "email" => $alumno["email"],
        "promedio" => $conteo > 0 ? round($suma / $conteo, 2) : 0,
        "conteo" => $conteo,
    ];
}

if ($total_calificaciones > 0) {
    $promedio_curso = round($promedio_curso_total / $total_calificaciones, 2);
} else {
    $promedio_curso = 0;
}

if (!empty($promedios_alumnos)) {
    usort($promedios_alumnos, function ($a, $b) {
        if ($a["promedio"] === $b["promedio"]) {
            return strcmp($a["nombre"], $b["nombre"]);
        }
        return $b["promedio"] <=> $a["promedio"];
    });
    $mejor_alumno = $promedios_alumnos[0];
    $alumnos_aprobados = count(array_filter($promedios_alumnos, fn($alumno) => (float) $alumno["promedio"] >= 70));
    $alumnos_reprobados = count($promedios_alumnos) - $alumnos_aprobados;
} else {
    $mejor_alumno = null;
    $alumnos_aprobados = 0;
    $alumnos_reprobados = 0;
}

$mejor_leccion = null;
foreach ($lecciones_filtradas as $leccion) {
    $leccion_id = (int) $leccion["id"];
    $promedio_leccion = (float) ($promedio_por_leccion[$leccion_id] ?? 0);

    if ($mejor_leccion === null || $promedio_leccion > $mejor_leccion["promedio"]) {
        $mejor_leccion = [
            "titulo" => $leccion["titulo"],
            "promedio" => $promedio_leccion,
        ];
    }
}

if (isset($_GET["export_csv"]) && $_GET["export_csv"] == "1" && $puede_calificar) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=calificaciones-curso-" . (int) $curso_id . ".csv");
    $handle = fopen("php://output", "w");
    fputcsv($handle, ["Alumno", "Email", "Lección", "Actividad", "Calificación", "Comentario"]);

    $alumnos_export = $alumnos_filtrados ?: $alumnos;
    foreach ($lecciones_filtradas as $leccion) {
        $leccion_id = (int) $leccion["id"];
        $actividad = $actividades_por_leccion[$leccion_id] ?? null;
        foreach ($alumnos_export as $alumno) {
            $alumno_id = (int) $alumno["id"];
            if ($filtro_alumno && $alumno_id !== (int) $filtro_alumno) {
                continue;
            }
            $calificacion = $calificaciones_por_actividad[$leccion_id][$alumno_id] ?? null;
            fputcsv($handle, [
                $alumno["nombre_completo"],
                $alumno["email"],
                $leccion["titulo"],
                $actividad["titulo"] ?? "Actividad de la lección",
                $calificacion ? number_format((float) $calificacion["calificacion"], 2, ".", "") : "",
                $calificacion["comentarios"] ?? "",
            ]);
        }
    }
    fclose($handle);
    exit;
}

include_once "header.php";
?>

<div class="course-view-container">
    <div class="course-hero-banner" style="background-image: linear-gradient(180deg, rgba(15,23,42,0.4) 0%, rgba(15,23,42,0.92) 100%)<?php echo $banner_curso !== '' ? ", url('" . htmlspecialchars($banner_curso, ENT_QUOTES, 'UTF-8') . "')" : ''; ?>;">
        <div class="hero-content">
            <a href="dashboard.php?seccion=cursos" class="back-link">&larr; Volver a cursos</a>
            <div class="course-header-flex">
                <?php if ($foto_curso !== ''): ?>
                    <img src="<?php echo htmlspecialchars($foto_curso, ENT_QUOTES, 'UTF-8'); ?>" class="course-profile-avatar" alt="">
                <?php endif; ?>
                <div>
                    <span class="badge-category"><?php echo htmlspecialchars($curso["categoria"], ENT_QUOTES, "UTF-8"); ?> &bull; <?php echo htmlspecialchars($curso["nivel"], ENT_QUOTES, "UTF-8"); ?></span>
                    <h1 class="course-main-title"><?php echo htmlspecialchars($curso["titulo"], ENT_QUOTES, "UTF-8"); ?></h1>
                    <p class="course-instructor-info">Impartido por <strong><?php echo htmlspecialchars($curso["instructor_nombre"] ?? "Docente asignado", ENT_QUOTES, "UTF-8"); ?></strong></p>
                </div>
            </div>
        </div>
    </div>

    <div class="course-body-grid">
        <div class="course-primary">
            <div class="video-preview-card">
                <div class="video-placeholder" id="videoContainer">
                    <div class="play-button-overlay">
                        <span class="play-icon">▶</span>
                        <p><?php echo $primera_leccion ? "Selecciona un material para abrirlo" : "Este curso todavía no tiene lecciones"; ?></p>
                    </div>
                </div>
                <div class="lesson-details">
                    <h2 id="activeLessonTitle"><?php echo htmlspecialchars($primera_leccion["titulo"] ?? "Temario del curso", ENT_QUOTES, "UTF-8"); ?></h2>
                    <p id="activeLessonContent"><?php echo htmlspecialchars($primera_leccion["contenido"] ?? ($curso["descripcion"] ?? ""), ENT_QUOTES, "UTF-8"); ?></p>
                    <div id="lessonMaterial">
                        <?php
                        $primer_material = $primera_leccion["material_url"] ?? "";
                        if (url_https_valida($primer_material)):
                        ?>
                            <a class="btn-primary-glow" href="<?php echo htmlspecialchars($primer_material, ENT_QUOTES, "UTF-8"); ?>" target="_blank" rel="noopener noreferrer">Abrir material &rarr;</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="course-sidebar-lessons">
            <div class="progress-box">
                <div class="progress-info">
                    <span>Temario del curso</span>
                    <strong><?php echo count($lecciones); ?> lecciones</strong>
                </div>
            </div>

            <div class="lessons-list-card">
                <h3>📖 Lecciones</h3>
                <div class="lessons-accordion">
                    <?php if ($lecciones): ?>
                        <?php foreach ($lecciones as $index => $leccion): ?>
                            <?php
                            $material = $leccion["material_url"] ?? "";
                            if (!url_https_valida($material)) {
                                $material = "";
                            }
                            ?>
                            <button type="button"
                                class="lesson-item <?php echo $index === 0 ? "active" : ""; ?>"
                                data-title="<?php echo htmlspecialchars($leccion["titulo"], ENT_QUOTES, "UTF-8"); ?>"
                                data-content="<?php echo htmlspecialchars($leccion["contenido"] ?? "", ENT_QUOTES, "UTF-8"); ?>"
                                data-material="<?php echo htmlspecialchars($material, ENT_QUOTES, "UTF-8"); ?>">
                                <span class="lesson-item-left">
                                    <span class="lesson-status"><?php echo $index + 1; ?></span>
                                    <span>
                                        <strong><?php echo htmlspecialchars($leccion["titulo"], ENT_QUOTES, "UTF-8"); ?></strong>
                                        <?php if (!empty($leccion["duracion"])): ?>
                                            <span class="lesson-duration">⏱️ <?php echo htmlspecialchars($leccion["duracion"], ENT_QUOTES, "UTF-8"); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </span>
                            </button>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="empty-state">El docente aún no ha agregado lecciones.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($mensaje_exito)): ?>
        <div class="alert-success-message"><?php echo htmlspecialchars($mensaje_exito, ENT_QUOTES, "UTF-8"); ?></div>
    <?php endif; ?>
    <?php if (!empty($error_operacion)): ?>
        <div class="alert-error-message"><?php echo htmlspecialchars($error_operacion, ENT_QUOTES, "UTF-8"); ?></div>
    <?php endif; ?>

    <div class="teacher-grading-wrap" id="calificaciones">
        <?php if ($puede_calificar): ?>
            <div class="grading-header">
                <div>
                    <span class="section-eyebrow">CALIFICACIONES</span>
                    <h2>📘 Actividades y notas por lección</h2>
                </div>
                <span class="mini-status-pill"><?php echo count($alumnos_filtrados); ?> alumnos aprobados</span>
            </div>

            <form method="GET" action="ver_curso.php" class="grade-filter-form">
                <input type="hidden" name="id" value="<?php echo (int) $curso_id; ?>">
                <div class="grade-filter-controls">
                    <label class="grade-filter-field">
                        <span>Alumno</span>
                        <select name="alumno_filtro">
                            <option value="">Todos</option>
                            <?php foreach ($alumnos as $alumno): ?>
                                <option value="<?php echo (int) $alumno["id"]; ?>" <?php echo ($filtro_alumno === (int) $alumno["id"]) ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars($alumno["nombre_completo"], ENT_QUOTES, "UTF-8"); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="grade-filter-field">
                        <span>Lección</span>
                        <select name="leccion_filtro">
                            <option value="">Todas</option>
                            <?php foreach ($lecciones as $leccion): ?>
                                <option value="<?php echo (int) $leccion["id"]; ?>" <?php echo ($filtro_leccion === (int) $leccion["id"]) ? "selected" : ""; ?>>
                                    <?php echo htmlspecialchars($leccion["titulo"], ENT_QUOTES, "UTF-8"); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button type="submit" class="btn-secondary-outline">Aplicar filtros</button>
                    <a class="btn-secondary-outline" href="ver_curso.php?id=<?php echo (int) $curso_id; ?>&alumno_filtro=<?php echo (int) ($filtro_alumno ?: 0); ?>&leccion_filtro=<?php echo (int) ($filtro_leccion ?: 0); ?>&export_csv=1#calificaciones">Exportar CSV</a>
                    <?php if ($filtro_alumno || $filtro_leccion): ?>
                        <a href="ver_curso.php?id=<?php echo (int) $curso_id; ?>#calificaciones" class="btn-transparent-link">Limpiar</a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="grade-summary-grid">
                <article class="grade-summary-card grade-summary-card--primary">
                    <span class="grade-summary-icon">📊</span>
                    <div>
                        <small>Promedio del curso</small>
                        <strong><?php echo number_format($promedio_curso, 2); ?>%</strong>
                    </div>
                </article>
                <article class="grade-summary-card grade-summary-card--success">
                    <span class="grade-summary-icon">🏆</span>
                    <div>
                        <small>Mejor alumno</small>
                        <strong><?php echo htmlspecialchars($mejor_alumno["nombre"] ?? "Sin datos", ENT_QUOTES, "UTF-8"); ?></strong>
                    </div>
                </article>
                <article class="grade-summary-card grade-summary-card--warning">
                    <span class="grade-summary-icon">✅</span>
                    <div>
                        <small>Calificaciones registradas</small>
                        <strong><?php echo number_format((int) $total_calificaciones); ?></strong>
                    </div>
                </article>
                <article class="grade-summary-card grade-summary-card--info">
                    <span class="grade-summary-icon">🎯</span>
                    <div>
                        <small>Aprobados / reprobados</small>
                        <strong><?php echo (int) $alumnos_aprobados; ?> / <?php echo (int) $alumnos_reprobados; ?></strong>
                    </div>
                </article>
            </div>

            <div class="grade-summary-grid grade-summary-grid--compact">
                <article class="grade-summary-card grade-summary-card--secondary">
                    <span class="grade-summary-icon">📘</span>
                    <div>
                        <small>Mejor lección</small>
                        <strong><?php echo htmlspecialchars($mejor_leccion["titulo"] ?? "Sin datos", ENT_QUOTES, "UTF-8"); ?></strong>
                    </div>
                </article>
                <article class="grade-summary-card grade-summary-card--primary">
                    <span class="grade-summary-icon">⭐</span>
                    <div>
                        <small>Promedio de la mejor lección</small>
                        <strong><?php echo number_format((float) ($mejor_leccion["promedio"] ?? 0), 2); ?>%</strong>
                    </div>
                </article>
            </div>

            <div class="grade-report-grid">
                <div class="grade-report-panel">
                    <h3>Reporte por lección</h3>
                    <table class="grade-table">
                        <thead>
                            <tr>
                                <th>Lección</th>
                                <th>Promedio</th>
                                <th>Alumnos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lecciones_filtradas as $leccion): ?>
                                <?php $leccion_id = (int) $leccion["id"]; ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($leccion["titulo"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><?php echo number_format((float) ($promedio_por_leccion[$leccion_id] ?? 0), 2); ?>%</td>
                                    <td><?php echo count($calificaciones_por_actividad[$leccion_id] ?? []); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="grade-report-panel">
                    <h3>Reporte por alumno</h3>
                    <table class="grade-table">
                        <thead>
                            <tr>
                                <th>Alumno</th>
                                <th>Promedio</th>
                                <th>Actividades</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($promedios_alumnos as $promedio_alumno): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($promedio_alumno["nombre"], ENT_QUOTES, "UTF-8"); ?></td>
                                    <td><?php echo number_format((float) $promedio_alumno["promedio"], 2); ?>%</td>
                                    <td><?php echo (int) $promedio_alumno["conteo"]; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($promedios_alumnos)): ?>
                                <tr>
                                    <td colspan="3">No hay calificaciones para este filtro.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($lecciones_filtradas): ?>
                <?php foreach ($lecciones_filtradas as $leccion): ?>
                    <?php
                    $actividad = $actividades_por_leccion[(int) $leccion["id"]] ?? null;
                    $calificaciones = $calificaciones_por_actividad[(int) $leccion["id"]] ?? [];
                    $alumnos_lectura = $alumnos_filtrados;
                    if ($filtro_alumno) {
                        $alumnos_lectura = array_values(array_filter($alumnos_filtrados, fn($alumno) => (int) $alumno["id"] === (int) $filtro_alumno));
                    }
                    ?>
                    <div class="lesson-grade-card">
                        <div class="lesson-grade-card-header">
                            <div>
                            <span class="lesson-grade-badge">Lección</span>
                                <h3><?php echo htmlspecialchars($leccion["titulo"], ENT_QUOTES, "UTF-8"); ?></h3>
                            </div>
                        <span class="lesson-grade-count"><?php echo count($alumnos_lectura); ?> estudiantes</span>
                        </div>

                        <?php if ($alumnos_lectura): ?>
                        <form method="POST" action="ver_curso.php?id=<?php echo (int) $curso_id; ?>#calificaciones" class="grade-form">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="action" value="save_lesson_grades">
                            <input type="hidden" name="leccion_id" value="<?php echo (int) $leccion["id"]; ?>">

                            <div class="grade-activity-fields">
                                <div class="grade-field-group">
                                    <label for="actividad-titulo-<?php echo (int) $leccion["id"]; ?>">Nombre de la actividad</label>
                                    <input id="actividad-titulo-<?php echo (int) $leccion["id"]; ?>" type="text" name="actividad_titulo" class="input-field" value="<?php echo htmlspecialchars($actividad["titulo"] ?? "Actividad de la lección", ENT_QUOTES, "UTF-8"); ?>" placeholder="Ej. Ejercicio práctico de PHP">
                                </div>
                                <div class="grade-field-group">
                                    <label for="actividad-descripcion-<?php echo (int) $leccion["id"]; ?>">Descripción</label>
                                    <textarea id="actividad-descripcion-<?php echo (int) $leccion["id"]; ?>" name="actividad_descripcion" class="input-field" rows="3" placeholder="Describe la tarea o actividad que se realizará."><?php echo htmlspecialchars($actividad["descripcion"] ?? "", ENT_QUOTES, "UTF-8"); ?></textarea>
                                </div>
                            </div>

                            <div class="grade-students-list">
                                <?php foreach ($alumnos_lectura as $alumno): ?>
                                    <?php $calificacion_actual = $calificaciones[(int) $alumno["id"]] ?? null; ?>
                                    <div class="student-grade-row">
                                        <div class="student-grade-meta">
                                            <strong><?php echo htmlspecialchars($alumno["nombre_completo"], ENT_QUOTES, "UTF-8"); ?></strong>
                                            <small><?php echo htmlspecialchars($alumno["email"], ENT_QUOTES, "UTF-8"); ?></small>
                                        </div>
                                        <div class="student-grade-controls">
                                            <div class="grade-control">
                                                <label for="calificacion-<?php echo (int) $leccion["id"]; ?>-<?php echo (int) $alumno["id"]; ?>">Calificación</label>
                                                <input id="calificacion-<?php echo (int) $leccion["id"]; ?>-<?php echo (int) $alumno["id"]; ?>" type="number" name="calificacion[<?php echo (int) $alumno["id"]; ?>]" min="0" max="100" step="0.5" value="<?php echo htmlspecialchars((string) ($calificacion_actual["calificacion"] ?? ""), ENT_QUOTES, "UTF-8"); ?>" placeholder="0-100">
                                            </div>
                                            <div class="grade-control grade-comment-control">
                                                <label for="comentario-<?php echo (int) $leccion["id"]; ?>-<?php echo (int) $alumno["id"]; ?>">Comentario</label>
                                                <input id="comentario-<?php echo (int) $leccion["id"]; ?>-<?php echo (int) $alumno["id"]; ?>" type="text" name="comentario[<?php echo (int) $alumno["id"]; ?>]" value="<?php echo htmlspecialchars((string) ($calificacion_actual["comentarios"] ?? ""), ENT_QUOTES, "UTF-8"); ?>" placeholder="Observación opcional">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <button type="submit" class="btn-primary-glow grade-submit-btn">Guardar notas de esta lección</button>
                        </form>
                        <?php else: ?>
                            <div class="grade-empty-note">
                                <strong>Aún no hay alumnos con matrícula aprobada.</strong>
                                <span>Las calificaciones estarán disponibles cuando se complete la validación del director y del administrador.</span>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="reports-empty-state student-empty-state">
                    <span aria-hidden="true">📘</span>
                    <h3>No hay lecciones para el filtro seleccionado</h3>
                    <p>Prueba con otra lección o limpia los filtros para ver el contenido completo.</p>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="reports-empty-state student-empty-state">
                <span aria-hidden="true">🔒</span>
                <h3>Vista solo para docente</h3>
                <p>Solo el docente responsable del curso o el administrador pueden calificar a los alumnos.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.lesson-item[data-title]').forEach(function (item) {
    item.addEventListener('click', function () {
        document.getElementById('activeLessonTitle').textContent = item.dataset.title;
        document.getElementById('activeLessonContent').textContent = item.dataset.content;

        document.querySelectorAll('.lesson-item').forEach(function (lesson) {
            lesson.classList.remove('active');
        });
        item.classList.add('active');

        var materialContainer = document.getElementById('lessonMaterial');
        materialContainer.replaceChildren();
        if (item.dataset.material) {
            var link = document.createElement('a');
            link.className = 'btn-primary-glow';
            link.href = item.dataset.material;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = 'Abrir material →';
            materialContainer.appendChild(link);
        }
    });
});
</script>

<?php include_once "footer.php"; ?>
