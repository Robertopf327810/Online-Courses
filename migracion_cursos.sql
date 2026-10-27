USE cursos_online;

DELIMITER $$

DROP PROCEDURE IF EXISTS migrar_esquema_cursos_online$$
CREATE PROCEDURE migrar_esquema_cursos_online()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'apellidos'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN apellidos VARCHAR(120) NOT NULL DEFAULT '' AFTER nombre;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'descripcion'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN descripcion TEXT NULL AFTER apellidos;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'foto'
    ) THEN
        ALTER TABLE usuarios ADD COLUMN foto VARCHAR(255) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'instructor_id'
    ) THEN
        IF EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'ponente_id'
        ) THEN
            ALTER TABLE cursos CHANGE COLUMN ponente_id instructor_id INT NULL;
        ELSE
            ALTER TABLE cursos ADD COLUMN instructor_id INT NULL;
        END IF;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'categoria'
    ) THEN
        ALTER TABLE cursos ADD COLUMN categoria VARCHAR(100) NOT NULL DEFAULT 'General';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'nivel'
    ) THEN
        ALTER TABLE cursos ADD COLUMN nivel VARCHAR(50) NOT NULL DEFAULT 'Todos los niveles';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'foto_curso'
    ) THEN
        ALTER TABLE cursos ADD COLUMN foto_curso VARCHAR(500) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cursos' AND COLUMN_NAME = 'banner_curso'
    ) THEN
        ALTER TABLE cursos ADD COLUMN banner_curso VARCHAR(500) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lecciones'
    ) THEN
        CREATE TABLE lecciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            curso_id INT NOT NULL,
            titulo VARCHAR(150) NOT NULL,
            contenido TEXT NULL,
            duracion VARCHAR(50) NULL,
            video_url VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
        );
    ELSE
        IF NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lecciones' AND COLUMN_NAME = 'duracion'
        ) THEN
            ALTER TABLE lecciones ADD COLUMN duracion VARCHAR(50) NULL;
        END IF;

        IF NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lecciones' AND COLUMN_NAME = 'video_url'
        ) THEN
            IF EXISTS (
                SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lecciones' AND COLUMN_NAME = 'material_url'
            ) THEN
                ALTER TABLE lecciones CHANGE COLUMN material_url video_url VARCHAR(500) NULL;
            ELSE
                ALTER TABLE lecciones ADD COLUMN video_url VARCHAR(500) NULL;
            END IF;
        END IF;
    END IF;
END$$

CALL migrar_esquema_cursos_online()$$
DROP PROCEDURE migrar_esquema_cursos_online$$

DELIMITER ;

INSERT INTO cursos (titulo, descripcion, instructor_id, categoria, nivel, foto_curso, banner_curso)
SELECT 'Desarrollo Web Full Stack con PHP',
       'Aprende a construir plataformas dinámicas, seguras y modulares utilizando PHP, MySQL y CSS3.',
       id, 'Desarrollo Web', 'Intermedio',
       'https://images.unsplash.com/photo-1599507593499-a3f7d7d97667?auto=format&fit=crop&w=600&q=80',
       'https://images.unsplash.com/photo-1517694712202-14dd9538aa97?auto=format&fit=crop&w=1200&q=80'
FROM usuarios
WHERE email = 'ponente@cursos.com' AND rol = 'ponente'
  AND NOT EXISTS (SELECT 1 FROM cursos WHERE titulo = 'Desarrollo Web Full Stack con PHP');

INSERT INTO cursos (titulo, descripcion, instructor_id, categoria, nivel, foto_curso, banner_curso)
SELECT 'Bases de Datos Relacionales y SQL',
       'Aprende normalización, consultas y fundamentos de MySQL.',
       id, 'Bases de Datos', 'Avanzado',
       'https://images.unsplash.com/photo-1544383835-bda2bc66a55d?auto=format&fit=crop&w=600&q=80',
       'https://images.unsplash.com/photo-1558494949-ef010cbdcc31?auto=format&fit=crop&w=1200&q=80'
FROM usuarios
WHERE email = 'ponente@cursos.com' AND rol = 'ponente'
  AND NOT EXISTS (SELECT 1 FROM cursos WHERE titulo = 'Bases de Datos Relacionales y SQL');

INSERT INTO lecciones (curso_id, titulo, contenido, duracion)
SELECT c.id, 'Introducción a PHP', 'Conoce la sintaxis básica y el entorno de ejecución de PHP.', '15 min'
FROM cursos c
WHERE c.titulo = 'Desarrollo Web Full Stack con PHP'
  AND NOT EXISTS (SELECT 1 FROM lecciones WHERE curso_id = c.id AND titulo = 'Introducción a PHP');

INSERT INTO lecciones (curso_id, titulo, contenido, duracion)
SELECT c.id, 'Conexión con MySQL', 'Aprende a conectar tu aplicación PHP con una base de datos MySQL.', '20 min'
FROM cursos c
WHERE c.titulo = 'Desarrollo Web Full Stack con PHP'
  AND NOT EXISTS (SELECT 1 FROM lecciones WHERE curso_id = c.id AND titulo = 'Conexión con MySQL');

INSERT INTO lecciones (curso_id, titulo, contenido, duracion)
SELECT c.id, 'Fundamentos de bases de datos', 'Repasa tablas, relaciones y claves para estructurar información.', '18 min'
FROM cursos c
WHERE c.titulo = 'Bases de Datos Relacionales y SQL'
  AND NOT EXISTS (SELECT 1 FROM lecciones WHERE curso_id = c.id AND titulo = 'Fundamentos de bases de datos');

UPDATE cursos c
JOIN usuarios u ON u.email = 'ponente@cursos.com' AND u.rol = 'ponente'
SET c.instructor_id = u.id
WHERE c.instructor_id IS NULL
  AND c.titulo IN ('Desarrollo Web Full Stack con PHP', 'Bases de Datos Relacionales y SQL');
