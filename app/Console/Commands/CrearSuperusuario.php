<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Rol;
use App\Models\User;
use App\Support\EmpresaActual;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Ronda B, parte 1: la ÚNICA forma de crear un superusuario.
 *
 * Interactivo: pide nombre, correo y contraseña (oculta, con confirmación).
 * Para instalaciones automatizadas acepta --nombre y --email, y con
 * --password-temporal genera una contraseña y la muestra una sola vez.
 */
class CrearSuperusuario extends Command
{
    protected $signature = 'sistema:crear-superusuario
        {--nombre= : Nombre completo}
        {--email= : Correo con el que inicia sesión}
        {--password-temporal : Genera una contraseña temporal y la muestra una sola vez}';

    protected $description = 'Crea un superusuario (rol técnico: configuración, usuarios administradores y estado del sistema)';

    public function handle(): int
    {
        $nombre = trim((string) ($this->option('nombre') ?? $this->ask('Nombre completo')));
        $email = Str::lower(trim((string) ($this->option('email') ?? $this->ask('Correo electrónico'))));

        $temporal = (bool) $this->option('password-temporal');
        $password = $temporal ? $this->generarPassword() : (string) $this->secret('Contraseña (mínimo 8 caracteres, con letras y números)');

        $validador = Validator::make(
            ['name' => $nombre, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::min(8)->letters()->numbers()],
            ],
            [
                'name.required' => 'Escribe el nombre.',
                'email.required' => 'Escribe el correo.',
                'email.email' => 'El correo no es válido.',
                'email.unique' => 'Ya existe un usuario con ese correo.',
            ],
        );

        if ($validador->fails()) {
            foreach ($validador->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (! $temporal && $this->secret('Repite la contraseña') !== $password) {
            $this->error('Las contraseñas no coinciden.');

            return self::FAILURE;
        }

        $usuario = new User([
            'name' => $nombre,
            'email' => $email,
            'password' => $password,
            'activo' => true,
            'empresa_id' => EmpresaActual::id(),
        ]);
        // El rol no se asigna en masa desde ningún formulario: solo aquí.
        $usuario->rol = Rol::Superusuario;
        $usuario->email_verified_at = now();
        $usuario->save();

        $this->info("Superusuario creado: {$usuario->name} <{$usuario->email}> (id {$usuario->id}).");

        if ($temporal) {
            $this->warn("Contraseña temporal: {$password}");
            $this->line('Guárdala en un lugar seguro y cámbiala al entrar: no se vuelve a mostrar.');
        }

        return self::SUCCESS;
    }

    private function generarPassword(): string
    {
        // Letras y números garantizados, sin caracteres que confundan al dictarla.
        $letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $numeros = '23456789';
        $todo = $letras.$numeros;
        $password = $letras[random_int(0, strlen($letras) - 1)].$numeros[random_int(0, strlen($numeros) - 1)];

        for ($i = 0; $i < 12; $i++) {
            $password .= $todo[random_int(0, strlen($todo) - 1)];
        }

        return str_shuffle($password);
    }
}
