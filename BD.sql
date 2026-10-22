CREATE DATABASE IF NOT EXISTS cursos_online CHARACTER SET utf8mb4;
USE cursos_online;

-- 1. Tabla de Usuarios con Roles
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    rol ENUM('superadmin', 'director', 'ponente', 'alumno') NOT NULL DEFAULT 'alumno',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Cursos
CREATE TABLE IF NOT EXISTS cursos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(150) NOT NULL,
    descripcion TEXT NULL,
    ponente_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ponente_id) REFERENCES usuarios(id) ON DELETE CASCADE
);

-- 3. Inscripciones (Add / Remove Alumnos)
CREATE TABLE IF NOT EXISTS curso_alumnos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curso_id INT NOT NULL,
    alumno_id INT NOT NULL,
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

-- USUARIOS DE PRUEBA (Contraseña de todos: password123)
-- Hash generado con PASSWORD_DEFAULT
INSERT INTO usuarios (nombre, email, password, rol) VALUES
('Super Administrador', 'admin@cursos.com', '$2y$10$4n9Hh1wYjL.QfWj68t/PVO93Uu6X0f9f3X6UuP0tT4uG7mUaV4kKy', 'superadmin'),
('Director Académico', 'director@cursos.com', '$2y$10$4n9Hh1wYjL.QfWj68t/PVO93Uu6X0f9f3X6UuP0tT4uG7mUaV4kKy', 'director'),
('Prof. Roberto Ponente', 'ponente@cursos.com', '$2y$10$4n9Hh1wYjL.QfWj68t/PVO93Uu6X0f9f3X6UuP0tT4uG7mUaV4kKy', 'ponente'),
('Juan Alumno', 'alumno@cursos.com', '$2y$10$4n9Hh1wYjL.QfWj68t/PVO93Uu6X0f9f3X6UuP0tT4uG7mUaV4kKy', 'alumno')
ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);