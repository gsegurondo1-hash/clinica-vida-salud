#!/bin/sh
# Render ejecuta este script al publicar el sitio estatico: escribe la URL de la API.
set -e
API="${API_URL:-http://localhost:8000/api}"
DEMO="${MOSTRAR_DEMO:-true}"
cat > js/config.js <<CFG
export const API_URL = '${API}';
export const MOSTRAR_DEMO = ${DEMO};
CFG
echo "API_URL configurada: $API"
