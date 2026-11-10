@echo off
setlocal
cd /d "%~dp0"

rem %~dp0 trae barra final; quitarla evita que rompa las comillas
set "LARAVEL=%~dp0"
if "%LARAVEL:~-1%"=="\" set "LARAVEL=%LARAVEL:~0,-1%"
set "FRONTEND=%LARAVEL%\..\AgenciaSueV2"

if not exist "%FRONTEND%\package.json" (
  echo No se encontro el frontend en:
  echo %FRONTEND%
  pause
  exit /b 1
)

where php >nul 2>&1
if errorlevel 1 (
  echo No se encontro php en el PATH.
  pause
  exit /b 1
)

where npm >nul 2>&1
if errorlevel 1 (
  echo No se encontro npm en el PATH.
  pause
  exit /b 1
)

echo Iniciando Laravel en http://localhost:8000
start "Laravel :8000" cmd /k "cd /d ""%LARAVEL%"" && php artisan serve --host=localhost --port=8000"

echo Iniciando frontend AgenciaSueV2 con npm run dev
start "AgenciaSueV2" cmd /k "cd /d ""%FRONTEND%"" && npm run dev"

echo.
echo Laravel:  http://localhost:8000
echo Frontend: %FRONTEND%
echo Para detenerlos ejecuta apagar.bat
endlocal
