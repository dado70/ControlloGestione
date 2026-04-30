<?php

declare(strict_types=1);

class P7MDecryptor
{
    /**
     * Estrae il payload XML da un file .p7m (CMS SignedData dallo SDI).
     * Non è necessario alcun certificato privato: è una firma, non una cifratura.
     *
     * @throws RuntimeException se l'estrazione fallisce con entrambi i formati
     */
    public function decrypt(string $p7mPath): string
    {
        if (!file_exists($p7mPath)) {
            throw new RuntimeException("File P7M non trovato: $p7mPath");
        }

        $xmlPath = sys_get_temp_dir() . '/fe_' . uniqid('', true) . '.xml';

        // Tentativo 1: formato DER (standard SDI)
        $cmd = sprintf(
            'openssl smime -verify -noverify -in %s -inform DER -out %s 2>/dev/null',
            escapeshellarg($p7mPath),
            escapeshellarg($xmlPath)
        );
        exec($cmd, $output, $returnCode);

        if ($returnCode !== 0 || !file_exists($xmlPath) || filesize($xmlPath) === 0) {
            // Tentativo 2: formato PEM
            @unlink($xmlPath);
            $cmd2 = sprintf(
                'openssl cms -verify -noverify -in %s -inform PEM -out %s 2>/dev/null',
                escapeshellarg($p7mPath),
                escapeshellarg($xmlPath)
            );
            exec($cmd2, $output, $returnCode);
        }

        if ($returnCode !== 0 || !file_exists($xmlPath) || filesize($xmlPath) === 0) {
            @unlink($xmlPath);
            throw new RuntimeException("Impossibile estrarre XML dal file P7M: $p7mPath");
        }

        return $xmlPath;
    }
}
