<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Sistema;

use App\Http\Controllers\Controller;
use App\Support\ErrorCorreo;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Ronda B, parte 1: pantalla "Estado del sistema" del superusuario.
 *
 * Muestra la configuración del correo SIN la contraseña: solo si está
 * puesta o no.
 */
class EstadoSistemaController extends Controller
{
    public const ASUNTO_PRUEBA = 'Prueba de correo – Proformas';

    /** Mailers que no envían correos reales. */
    private const MAILERS_SIN_ENVIO = ['log', 'array'];

    public function show(Request $request, Migrator $migrator): JsonResponse
    {
        $this->soloSuperusuario($request);

        return response()->json([
            'app' => [
                'nombre' => config('app.name'),
                'version' => config('app.version'),
                'entorno' => app()->environment(),
                'laravel' => app()->version(),
                'php' => PHP_VERSION,
            ],
            'migraciones' => $this->migraciones($migrator),
            'correo' => $this->correo(),
        ]);
    }

    public function correoPrueba(Request $request): JsonResponse
    {
        $this->soloSuperusuario($request);

        $usuario = $request->user();
        $correo = $this->correo();

        try {
            Mail::raw(
                "Hola {$usuario->name}:\n\nEste es un correo de prueba enviado desde la pantalla «Estado del sistema» de "
                .config('app.name').".\nSi lo recibiste, el envío de correos está bien configurado.",
                fn ($mensaje) => $mensaje->to($usuario->email, $usuario->name)->subject(self::ASUNTO_PRUEBA),
            );
        } catch (Throwable $error) {
            Log::error('No se pudo enviar el correo de prueba.', [
                'user_id' => $usuario->id,
                'mailer' => $correo['mailer'],
                'error' => ErrorCorreo::limpiar($error->getMessage()),
            ]);

            return response()->json([
                'enviado' => false,
                'message' => 'No se pudo enviar el correo de prueba. '.ErrorCorreo::explicar($error),
            ], 502);
        }

        return response()->json([
            'enviado' => true,
            'real' => $correo['envia_correos_reales'],
            'message' => $correo['envia_correos_reales']
                ? "Enviamos el correo de prueba a {$usuario->email}. Revisa tu bandeja (y la carpeta de spam)."
                : "El correo está configurado con el mailer «{$correo['mailer']}»: el mensaje quedó en el log y no salió ningún correo real.",
        ]);
    }

    /** @return array{aplicadas: int, pendientes: int, ultima: string|null} */
    private function migraciones(Migrator $migrator): array
    {
        $repositorio = $migrator->getRepository();

        if (! $repositorio->repositoryExists()) {
            return ['aplicadas' => 0, 'pendientes' => 0, 'ultima' => null];
        }

        $aplicadas = $repositorio->getRan();
        $archivos = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
        $pendientes = array_diff($archivos, $aplicadas);

        return [
            'aplicadas' => count($aplicadas),
            'pendientes' => count($pendientes),
            'ultima' => $aplicadas === [] ? null : end($aplicadas),
        ];
    }

    /**
     * @return array{mailer: string, envia_correos_reales: bool, configurado: bool, host: string|null, puerto: int|null, seguridad: string|null, usuario: string|null, password_configurada: bool, remitente: string|null, remitente_nombre: string|null, faltan: list<string>}
     */
    private function correo(): array
    {
        $mailer = (string) config('mail.default');
        $smtp = (array) config('mail.mailers.smtp', []);
        $esSmtp = $mailer === 'smtp';
        $remitente = config('mail.from.address');

        $faltan = [];

        if ($esSmtp) {
            foreach (['MAIL_HOST' => 'host', 'MAIL_PORT' => 'port', 'MAIL_USERNAME' => 'username', 'MAIL_PASSWORD' => 'password'] as $variable => $clave) {
                if (blank($smtp[$clave] ?? null)) {
                    $faltan[] = $variable;
                }
            }
        }

        if (blank($remitente)) {
            $faltan[] = 'MAIL_FROM_ADDRESS';
        }

        $real = ! in_array($mailer, self::MAILERS_SIN_ENVIO, true);

        return [
            'mailer' => $mailer,
            'envia_correos_reales' => $real,
            'configurado' => $real && $faltan === [],
            'host' => $esSmtp ? ($smtp['host'] ?? null) : null,
            'puerto' => $esSmtp && isset($smtp['port']) ? (int) $smtp['port'] : null,
            'seguridad' => $esSmtp ? ($smtp['scheme'] ?? null) : null,
            'usuario' => $esSmtp ? ($smtp['username'] ?? null) : null,
            'password_configurada' => $esSmtp && filled($smtp['password'] ?? null),
            'remitente' => $remitente,
            'remitente_nombre' => config('mail.from.name'),
            'faltan' => $faltan,
        ];
    }

    private function soloSuperusuario(Request $request): void
    {
        abort_unless($request->user()?->rol?->puedeConfigurarSistema(), 403, 'No tienes permisos para realizar esta acción.');
    }
}
