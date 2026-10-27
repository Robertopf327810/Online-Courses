# Online-Courses

## Instalación

1. Crea la base de datos ejecutando `BD.sql` en MySQL.
2. Para una base existente, haz una copia de seguridad y ejecuta `migracion_cursos.sql`. La migración reconoce tanto el esquema anterior con `ponente_id`/`material_url` como el esquema existente con `instructor_id`/`video_url`.
3. Configura la conexión con las variables de entorno `DB_HOST`, `DB_USER`, `DB_PASSWORD` y `DB_NAME`. Si `DB_PASSWORD` no está definida, se usa la contraseña local anterior del proyecto (`Sistemas`); reemplázala con una variable de entorno antes de desplegar. Los demás valores predeterminados son `localhost`, `root` y `cursos_online`.
4. Configura el servidor web para servir este directorio con PHP y MySQLi habilitado. La aplicación crea `uploads/` cuando se sube la primera foto.

La instalación de ejemplo crea dos cursos y lecciones para el usuario `ponente@cursos.com` definido en `BD.sql`. El perfil permite guardar nombre, apellidos y una descripción personal. En bases existentes, ejecuta la migración para agregar las columnas que falten. Los datos ficticios son solo para desarrollo; reemplázalos antes de publicar la aplicación.