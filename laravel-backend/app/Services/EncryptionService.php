<?php

namespace App\Services;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class EncryptionService
{
    /**
     * Encripta una contraseña usando Laravel Crypt.
     */
    public function encrypt(string $password): string
    {
        return Crypt::encryptString($password);
    }

    /**
     * Desencripta una contraseña previamente encriptada con Laravel Crypt.
     *
     * @throws \RuntimeException si la desencriptación falla
     */
    public function decrypt(string $encryptedPassword): string
    {
        try {
            return Crypt::decryptString($encryptedPassword);
        } catch (DecryptException $e) {
            throw new \RuntimeException(
                'No se pudo desencriptar la contraseña de la cuenta de correo. ' .
                'La clave de encriptación puede haber cambiado o el valor está corrupto. ' .
                'Error original: ' . $e->getMessage()
            );
        }
    }
}
