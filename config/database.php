<?php

/**
 * Configuration de la base de données.
 *
 * Ordre de priorité, du plus faible au plus fort :
 *   1. les valeurs par défaut ci-dessous (WAMP en local) ;
 *   2. le fichier config/database.local.php (non versionné), qui retourne un tableau ;
 *   3. les variables d'environnement MIGON_DB_HOST, MIGON_DB_NAME,
 *      MIGON_DB_USER et MIGON_DB_PASSWORD.
 *
 * En production, ne jamais utiliser « root » sans mot de passe.
 */
$config = [
    'host' => 'localhost',
    'dbname' => 'migon',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
];

$fichierLocal = __DIR__ . '/database.local.php';

if (is_file($fichierLocal)) {
    $surcharge = require $fichierLocal;

    if (is_array($surcharge)) {
        $config = array_merge($config, $surcharge);
    }
}

$variablesEnvironnement = [
    'host' => 'MIGON_DB_HOST',
    'dbname' => 'MIGON_DB_NAME',
    'username' => 'MIGON_DB_USER',
    'password' => 'MIGON_DB_PASSWORD',
];

foreach ($variablesEnvironnement as $cle => $nomVariable) {
    $valeur = getenv($nomVariable);

    // Un mot de passe vide défini explicitement est une valeur valide.
    if (is_string($valeur) && ($valeur !== '' || $cle === 'password')) {
        $config[$cle] = $valeur;
    }
}

return $config;
