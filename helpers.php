<?php
function url_https_valida($url): bool
{
    return is_string($url) &&
        filter_var($url, FILTER_VALIDATE_URL) !== false &&
        strtolower((string) parse_url($url, PHP_URL_SCHEME)) === "https";
}

function asegurar_esquema_curso_alumnos(mysqli $conexion): void
{
    $conexion->query(
        "CREATE TABLE IF NOT EXISTS curso_alumnos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            curso_id INT NOT NULL,
            alumno_id INT NOT NULL,
            estado ENUM('pendiente', 'aprobado') NOT NULL DEFAULT 'pendiente',
            aprobado_por_director TINYINT(1) NOT NULL DEFAULT 0,
            aprobado_por_admin TINYINT(1) NOT NULL DEFAULT 0,
            fecha_inscripcion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_curso_alumno (curso_id, alumno_id),
            KEY idx_curso_alumno_curso (curso_id),
            KEY idx_curso_alumno_alumno (alumno_id),
            CONSTRAINT fk_curso_alumno_curso FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
            CONSTRAINT fk_curso_alumno_usuario FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columnas = [];
    $resultado = $conexion->query("SHOW COLUMNS FROM curso_alumnos");
    if ($resultado) {
        while ($fila = $resultado->fetch_assoc()) {
            $columnas[] = $fila["Field"];
        }
    }

    $estado_nuevo = !in_array("estado", $columnas, true);
    if ($estado_nuevo) {
        $conexion->query("ALTER TABLE curso_alumnos ADD COLUMN estado ENUM('pendiente', 'aprobado') NOT NULL DEFAULT 'pendiente' AFTER alumno_id");
        $columnas[] = "estado";
    }

    if (!in_array("aprobado_por_director", $columnas, true)) {
        $conexion->query("ALTER TABLE curso_alumnos ADD COLUMN aprobado_por_director TINYINT(1) NOT NULL DEFAULT 0 AFTER estado");
        $columnas[] = "aprobado_por_director";
    }

    if (!in_array("aprobado_por_admin", $columnas, true)) {
        $conexion->query("ALTER TABLE curso_alumnos ADD COLUMN aprobado_por_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER aprobado_por_director");
        $columnas[] = "aprobado_por_admin";
    }

    if ($estado_nuevo) {
        $conexion->query("UPDATE curso_alumnos SET estado = 'aprobado', aprobado_por_director = 1, aprobado_por_admin = 1");
    } else {
        $conexion->query("UPDATE curso_alumnos SET estado = 'aprobado' WHERE estado IS NULL OR estado = ''");
    }
    $conexion->query("UPDATE curso_alumnos SET aprobado_por_director = 0 WHERE aprobado_por_director IS NULL");
    $conexion->query("UPDATE curso_alumnos SET aprobado_por_admin = 0 WHERE aprobado_por_admin IS NULL");
}

function render_error_page(string $title, string $message, string $details = "", string $button_label = "Intentar de nuevo", string $button_url = "index.php"): void
{
    http_response_code(500);

    $safe_title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safe_message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safe_details = htmlspecialchars($details, ENT_QUOTES, 'UTF-8');
    $safe_button_label = htmlspecialchars($button_label, ENT_QUOTES, 'UTF-8');
    $safe_button_url = htmlspecialchars($button_url, ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>$safe_title</title>
    <style>
        :root {
            --bg: #020d1a;
            --bg-alt: #020d1d;
            --text: #eef6ff;
            --muted: rgba(195, 216, 230, 0.9);
            --soft: rgba(168, 188, 204, 0.88);
            --primary: #38c9ff;
            --primary-deep: #06b6ff;
            --orange-1: #ff9c52;
            --orange-2: #ff7f40;
            --orange-3: #ffbe6d;
            --shadow: rgba(56, 201, 255, 0.35);
        }

        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            min-height: 100%;
            height: 100%;
            background: radial-gradient(circle at center, rgba(12, 22, 36, 0.96), var(--bg) 48%, var(--bg-alt) 100%);
            color: var(--text);
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            display: grid;
            place-items: center;
            padding: 18px 24px;
        }

        .error-shell {
            width: min(1180px, 100%);
            min-height: 90vh;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 46px;
            padding: 16px 0;
        }

        .error-visual {
            width: 180px;
            min-width: 180px;
            display: flex;
            justify-content: center;
            align-items: center;
            transform: translateY(8px);
        }

        .error-face {
            position: relative;
            width: 112px;
            height: 90px;
            border-radius: 55% 45% 50% 50% / 60% 60% 40% 40%;
            background: linear-gradient(135deg, var(--orange-3), var(--orange-1) 48%, var(--orange-2));
            box-shadow: 0 0 28px rgba(255, 145, 59, 0.55);
        }

        .error-face::before,
        .error-face::after {
            content: "";
            position: absolute;
            top: 30px;
            width: 10px;
            height: 10px;
            background: #1b1a1d;
            border-radius: 50%;
        }

        .error-face::before { left: 26px; }
        .error-face::after { right: 26px; }

        .error-smile {
            position: absolute;
            left: 50%;
            bottom: 17px;
            width: 30px;
            height: 18px;
            border: 4px solid transparent;
            border-bottom-color: rgba(25, 18, 17, 0.9);
            border-radius: 50%;
            transform: translateX(-50%);
        }

        .error-panel {
            width: min(560px, 100%);
        }

        .error-title {
            margin: 0;
            font-size: clamp(2.3rem, 2.8vw, 4.3rem);
            line-height: 0.92;
            letter-spacing: -0.06em;
            font-weight: 800;
            color: var(--text);
            text-shadow: 0 0 12px rgba(255,255,255,0.04);
        }

        .error-message {
            margin-top: 1.25rem;
            font-size: 0.86rem;
            line-height: 1.5;
            color: var(--muted);
            font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .error-section {
            margin-top: 1.65rem;
        }

        .error-section h2 {
            margin: 0 0 0.7rem;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text);
        }

        .error-section ul {
            list-style: none;
            margin: 0;
            padding: 0;
            display: grid;
            gap: 0.42rem;
            color: var(--muted);
            font-size: 0.98rem;
        }

        .error-section li {
            position: relative;
            padding-left: 1.15rem;
            line-height: 1.55;
        }

        .error-section li::before {
            content: "•";
            position: absolute;
            left: 0;
            color: var(--text);
        }

        .error-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 150px;
            margin-top: 1.5rem;
            padding: 0.82rem 1.2rem;
            border-radius: 10px;
            background: linear-gradient(180deg, var(--primary), var(--primary-deep));
            color: #041a2b;
            font-weight: 800;
            text-decoration: none;
            box-shadow: 0 12px 25px var(--shadow);
        }

        .error-footer {
            margin-top: 1.05rem;
            color: var(--soft);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        @media (max-width: 700px) {
            .error-shell {
                flex-direction: column;
                justify-content: center;
                gap: 18px;
                min-height: 100vh;
            }

            .error-panel {
                width: 100%;
            }

            .error-message,
            .error-section ul,
            .error-footer {
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <div class="error-shell">
        <div class="error-visual" aria-hidden="true">
            <div class="error-face">
                <span class="error-smile"></span>
            </div>
        </div>

        <div class="error-panel">
            <h1 class="error-title">$safe_title</h1>
            <div class="error-message">$safe_message</div>

            <div class="error-section">
                <h2>¿Qué puedes hacer?</h2>
                <ul>
                    <li>Revisa que la URL o la configuración del sitio sean correctas.</li>
                    <li>Comprueba que el servidor y la base de datos sigan disponibles.</li>
                    <li>Intenta cargar la página de nuevo en unos segundos.</li>
                </ul>
            </div>

            <a href="$safe_button_url" class="error-action">$safe_button_label</a>
            <div class="error-footer">$safe_details</div>
        </div>
    </div>
</body>
</html>
HTML;
    exit;
}

function gestionar_errores_fatales(): void
{
    $ultimo_error = error_get_last();
    if ($ultimo_error === null) {
        return;
    }

    $tipos_fatales = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) $ultimo_error['type'], $tipos_fatales, true)) {
        return;
    }

    $mensaje = trim((string) $ultimo_error['message']);
    if ($mensaje === '') {
        $mensaje = 'No se pudo completar la solicitud.';
    }

    render_error_page(
        "Looks like there's a problem with this site",
        $mensaje,
        'The server at ' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ' sent an error: 500 Internal Server Error',
        'Try Again',
        $_SERVER['REQUEST_URI'] ?: 'index.php'
    );
}

register_shutdown_function('gestionar_errores_fatales');
