# Cómo actualizar la página con las mejoras del Ciclo 2

No hay cambios en la base de datos: **no hay que recrearla ni ejecutar scripts SQL**.

1. Reemplace en su repositorio de GitHub los archivos de esta versión (o suba el proyecto completo).
   Cambiaron: `backend/app/controllers/{CatalogoController,ReporteController,UsuarioController}.php`,
   `backend/public/index.php`, `frontend/js/views/admin.js` y `README.md`.
2. Haga `git add . && git commit -m "Ciclo 2" && git push`.
3. En Render, si el *Auto-Deploy* está activo, se redespliegan solos el servicio `clinica-vida-salud-api`
   y el sitio estático `clinica-vida-salud-web`. Si no, entre a cada servicio y pulse **Manual Deploy > Deploy latest commit**.
4. Verifique: `https://SU-API.onrender.com/api/health` debe responder `"base_de_datos": true`.
5. Pruebe como administrador: *Usuarios y roles > Permisos por rol*, *Bitácora > Exportar CSV*
   y que al crear un horario, bloqueo o especialidad aparezca el evento en la bitácora.
6. Si el navegador muestra la versión anterior, recargue con Ctrl + F5.

En local: `docker compose up --build`.
