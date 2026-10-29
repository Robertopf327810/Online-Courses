CREATE DATABASE IF NOT EXISTS cursos_online CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cursos_online;

-- 1. Tabla de Usuarios con Roles
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    apellidos VARCHAR(120) NOT NULL DEFAULT '',
    descripcion TEXT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    rol ENUM('superadmin', 'director', 'ponente', 'alumno') NOT NULL DEFAULT 'alumno',
    foto VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Cursos
CREATE TABLE IF NOT EXISTS cursos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    instructor_id INT NULL,
    categoria VARCHAR(100) NOT NULL DEFAULT 'General',
    nivel VARCHAR(50) NOT NULL DEFAULT 'Todos los niveles',
    foto_curso VARCHAR(500) NULL,
    banner_curso VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (instructor_id) REFERENCES usuarios(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS lecciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    contenido TEXT NULL,
    duracion VARCHAR(50) NULL,
    video_url VARCHAR(500) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
);

-- 3. Inscripciones (Add / Remove Alumnos)
CREATE TABLE IF NOT EXISTS curso_alumnos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    alumno_id INT NOT NULL,
    estado ENUM('pendiente', 'aprobado') NOT NULL DEFAULT 'pendiente',
    aprobado_por_director TINYINT(1) NOT NULL DEFAULT 0,
    aprobado_por_admin TINYINT(1) NOT NULL DEFAULT 0,
    fecha_inscripcion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (curso_id, alumno_id),
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- 4. Bloques (Bloques 1...3)
CREATE TABLE IF NOT EXISTS bloques (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    numero_bloque INT NOT NULL,
    titulo VARCHAR(100) NOT NULL,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE
);

-- 5. Temas y Material
CREATE TABLE IF NOT EXISTS temas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bloque_id INT NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    material_url VARCHAR(255) NULL,
    FOREIGN KEY (bloque_id) REFERENCES bloques(id) ON DELETE CASCADE
);

-- 6. Tareas / Exámenes y Calificaciones
CREATE TABLE IF NOT EXISTS evaluaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bloque_id INT NOT NULL,
    titulo VARCHAR(150) NOT NULL,
    tipo ENUM('tarea', 'examen') NOT NULL DEFAULT 'tarea',
    FOREIGN KEY (bloque_id) REFERENCES bloques(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS calificaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evaluacion_id INT NOT NULL,
    alumno_id INT NOT NULL,
    calificacion DECIMAL(5, 2) NOT NULL,
    FOREIGN KEY (evaluacion_id) REFERENCES evaluaciones(id) ON DELETE CASCADE,
    FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS leccion_actividades (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    leccion_id INT NOT NULL,
    titulo VARCHAR(180) NOT NULL,
    descripcion TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_actividad_leccion (curso_id, leccion_id),
    KEY idx_actividad_curso (curso_id),
    KEY idx_actividad_leccion (leccion_id),
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    FOREIGN KEY (leccion_id) REFERENCES lecciones(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS calificaciones_actividad (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actividad_id INT NOT NULL,
    alumno_id INT NOT NULL,
    calificacion DECIMAL(5, 2) NOT NULL,
    comentarios TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_actividad_alumno (actividad_id, alumno_id),
    KEY idx_calificacion_alumno (alumno_id),
    FOREIGN KEY (actividad_id) REFERENCES leccion_actividades(id) ON DELETE CASCADE,
    FOREIGN KEY (alumno_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- 7. Chat del curso
CREATE TABLE IF NOT EXISTS chat_mensajes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    usuario_id INT NOT NULL,
    mensaje TEXT NOT NULL,
    fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (curso_id) REFERENCES cursos(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- Cuentas de prueba. La contraseña del alumno es alumno123.
INSERT INTO usuarios (nombre, apellidos, email, password, rol) VALUES
('Super', 'Administrador', 'admin@cursos.com', '$2y$10$4n9Hh1wYjL.QfWj68t/PVO93Uu6X0f9f3X6UuP0tT4uG7mUaV4kKy', 'superadmin'),
('Director', 'Académico', 'director@cursos.com', '$2y$10$4n9Hh1wYjL.QfWj68t/PVO93Uu6X0f9f3X6UuP0tT4uG7mUaV4kKy', 'director'),
('Roberto', 'Ponente', 'ponente@cursos.com', '$2y$10$4n9Hh1wYjL.QfWj68t/PVO93Uu6X0f9f3X6UuP0tT4uG7mUaV4kKy', 'ponente'),
('Juan', 'Alumno', 'alumno@cursos.com', '$2y$12$/Mfla.ZUJSVro27fZ5YV/eAZlQ99A2NqT2FlnzubG4DD4nHn/e3Xy', 'alumno'),
('María', 'López', 'maria@cursos.com', '$2y$12$/Mfla.ZUJSVro27fZ5YV/eAZlQ99A2NqT2FlnzubG4DD4nHn/e3Xy', 'alumno')
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre), apellidos=VALUES(apellidos);

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

INSERT INTO curso_alumnos (curso_id, alumno_id, estado, aprobado_por_director, aprobado_por_admin)
SELECT c.id, u.id, 'aprobado', 1, 1
FROM cursos c
JOIN usuarios u ON u.email = 'alumno@cursos.com'
WHERE c.instructor_id = (SELECT id FROM usuarios WHERE email = 'ponente@cursos.com')
  AND NOT EXISTS (
      SELECT 1 FROM curso_alumnos ca
      WHERE ca.curso_id = c.id AND ca.alumno_id = u.id
  );

INSERT INTO curso_alumnos (curso_id, alumno_id, estado, aprobado_por_director, aprobado_por_admin)
SELECT c.id, u.id, 'aprobado', 1, 1
FROM cursos c
JOIN usuarios u ON u.email = 'maria@cursos.com'
WHERE c.instructor_id = (SELECT id FROM usuarios WHERE email = 'ponente@cursos.com')
  AND NOT EXISTS (
      SELECT 1 FROM curso_alumnos ca
      WHERE ca.curso_id = c.id AND ca.alumno_id = u.id
  );