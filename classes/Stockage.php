<?php

/**
 * Accès centralisé aux dossiers de stockage de MIGON.
 *
 * - dossier des photos de logements ;
 * - dossier privé des pièces d'identité.
 *
 * Les emplacements viennent de config/app.php : plus aucun chemin n'est écrit
 * en dur dans les pages.
 *
 * La base enregistre désormais uniquement le NOM du fichier. Les anciennes
 * lignes qui contiennent encore un chemin complet (C:\wamp64\...) restent
 * lisibles : on ne garde que le nom du fichier, recombiné avec le dossier
 * configuré. Cela empêche aussi de sortir du dossier de stockage, même si une
 * valeur de la base était modifiée.
 */
class Stockage
{
    private static ?array $config = null;

    private static function config(): array
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../config/app.php';
        }

        return self::$config;
    }

    private static function dossier(string $cle): string
    {
        $dossier = self::config()[$cle] ?? '';

        if (!is_string($dossier) || trim($dossier) === '') {
            throw new RuntimeException('Configuration de stockage manquante : ' . $cle);
        }

        return rtrim($dossier, '\\/');
    }

    /** Dossier des photos de logements (sans séparateur final). */
    public static function dossierPhotos(): string
    {
        return self::dossier('dossier_photos');
    }

    /** Dossier privé des pièces d'identité (sans séparateur final). */
    public static function dossierPrive(): string
    {
        return self::dossier('dossier_prive');
    }

    /**
     * Nom de fichier seul, quel que soit le séparateur utilisé dans la valeur
     * stockée (« C:\wamp64\migon_photos\abc.jpg », « /x/abc.jpg » ou « abc.jpg »).
     * Retourne une chaîne vide si la valeur n'est pas exploitable.
     */
    public static function nomFichier(string $valeurStockee): string
    {
        $nom = basename(str_replace('\\', '/', trim($valeurStockee)));

        if ($nom === '' || $nom === '.' || $nom === '..') {
            return '';
        }

        return $nom;
    }

    /** Chemin complet d'une photo à partir de la valeur enregistrée en base. */
    public static function cheminPhoto(string $valeurStockee): string
    {
        return self::chemin(self::dossierPhotos(), $valeurStockee);
    }

    /** Chemin complet d'un document privé à partir de la valeur enregistrée en base. */
    public static function cheminPrive(string $valeurStockee): string
    {
        return self::chemin(self::dossierPrive(), $valeurStockee);
    }

    private static function chemin(string $dossier, string $valeurStockee): string
    {
        $nom = self::nomFichier($valeurStockee);

        if ($nom === '') {
            return '';
        }

        return $dossier . DIRECTORY_SEPARATOR . $nom;
    }
}
