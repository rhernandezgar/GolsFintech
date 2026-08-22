<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * No se siembra ningun usuario: un usuario con contrasena conocida en el
     * sembrado es una credencial por omision, y llega a produccion en cuanto
     * alguien ejecuta el sembrado ahi. Los usuarios administrativos se dan de
     * alta con `php artisan user:create`, que exige contrasena e imprime el
     * secreto del segundo factor una sola vez.
     */
    public function run(): void
    {
        $this->call(OAuthSpaClientSeeder::class);
        $this->call(OAuthPersonalAccessClientSeeder::class);
    }
}
