<?php
session_start();
include_once "conexion.php";
require_once "helpers.php";

if (!isset($_SESSION["usuario_id"])) {
    header("Location: login.php");
    exit;
}

$curso_id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);
if (!$curso_id) {
    http_response_code(404);
    exit("No se encontró el curso solicitado.");
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
