<?php
declare(strict_types=1);
namespace App\Service\Support;

final class EmailUsuario
{
    public static function normalizar(string $email): string { return strtolower(trim($email)); }
}
