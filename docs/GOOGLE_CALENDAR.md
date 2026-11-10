# Google Calendar — Fechas importantes

Este módulo sincroniza las fechas registradas en el admin con **una sola cuenta de Google** de la agencia. OAuth y tokens viven solo en Laravel; React nunca recibe `client_secret` ni refresh tokens.

## 1. Google Cloud Console

1. Crea un proyecto en [Google Cloud Console](https://console.cloud.google.com/).
2. Habilita **Google Calendar API**.
3. **APIs & Services → Credentials → Create credentials → OAuth client ID**.
4. Tipo: **Web application**.
5. **Authorized redirect URIs** (debe coincidir exactamente con `.env`):
   - Local: `http://localhost/google-calendar/oauth/callback`  
     (o la URL pública de tu API si usas XAMPP con virtual host)
   - Producción: `https://tu-dominio-api.com/google-calendar/oauth/callback`
6. Copia **Client ID** y **Client secret**.

## 2. Variables de entorno (`.env`)

```env
APP_URL=http://localhost
FRONTEND_URL=http://localhost:5173

GOOGLE_CALENDAR_CLIENT_ID=tu-client-id.apps.googleusercontent.com
GOOGLE_CALENDAR_CLIENT_SECRET=tu-secret
GOOGLE_CALENDAR_REDIRECT_URI="${APP_URL}/google-calendar/oauth/callback"
GOOGLE_CALENDAR_TIMEZONE=America/Mexico_City
```

Ajusta `APP_URL` al origen donde responde Laravel (incluye puerto si aplica).

## 3. Conectar la cuenta de la agencia

1. Inicia sesión como **admin** en el panel React.
2. Ve a **Fechas importantes**.
3. Pulsa **Conectar Google Calendar** (llama a `GET /api/google-calendar/connect-url` y abre la URL devuelta).
4. Autoriza con la cuenta Gmail/Google de la agencia.
5. Google redirige a Laravel (`/google-calendar/oauth/callback`) y Laravel te envía de vuelta al frontend con `?google=connected`.

Estado: `GET /api/google-calendar/status` (requiere token admin).

## 4. Recordatorios y recurrencia

- Eventos **todo el día** con `RRULE:FREQ=YEARLY` (cumpleaños/aniversarios se repiten cada año).
- **29 de febrero:** Google Calendar repite en años bisiestos; en años no bisiestos la próxima ocurrencia en la app usa **28 de febrero** como aproximación para listados “próximos”.
- Recordatorios: popup y email según anticipación (mismo día, 1, 3 o 7 días, o personalizado en días).
- Hora exacta personalizada: se guarda en observaciones del evento; los avisos nativos de Google para eventos de día completo dependen de la configuración de la app Calendar en el móvil.

## 5. Sincronización

| Acción        | Comportamiento                                      |
|---------------|-----------------------------------------------------|
| Crear         | Guarda en MySQL → intenta crear evento en Google   |
| Editar        | Actualiza MySQL → PATCH al mismo `event_id`        |
| Eliminar      | DELETE en Google (si hay id) → borra registro      |
| Reintentar    | `POST /api/important-dates/{id}/sync`              |

Estados: `synced`, `pending`, `error`. No se marca `synced` hasta respuesta exitosa de Google.

## 6. Comprobar notificaciones

Las push al celular las gestiona **Google Calendar**, no Laravel. Verifica:

- App Google Calendar instalada y con notificaciones activas.
- El calendario correcto visible en el móvil.
- Recordatorios del evento (popup/email) en la cuenta conectada.

No afirmes que las notificaciones funcionan hasta probarlas en un dispositivo real.

## 7. Dependencia opcional

La integración usa `Illuminate\Support\Facades\Http` (sin paquete extra). Si prefieres el SDK oficial:

```bash
composer require google/apiclient
```

No es obligatorio para el funcionamiento actual.
