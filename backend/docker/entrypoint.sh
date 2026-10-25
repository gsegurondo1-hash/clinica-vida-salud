#!/bin/sh
set -e
# Crea tablas y datos de ejemplo la primera vez (si la base esta vacia)
php /var/www/html/scripts/setup_db.php --auto || echo "Aviso: no se pudo inicializar la base de datos"
exec apache2-foreground
