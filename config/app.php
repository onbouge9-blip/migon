<?php

/**
 * Configuration générale de MIGON (hors base de données).
 *
 * Ordre de priorité, du plus faible au plus fort :
 *   1. les valeurs par défaut ci-dessous (installation WAMP classique) ;
 *   2. le fichier config/app.local.php (non versionné), qui retourne un tableau ;
 *   3. les variables d'environnement MIGON_DOSSIER_PHOTOS et MIGON_DOSSIER_PRIVE.
 *
 * Les deux dossiers doivent se trouver HORS du dossier web, afin que les
 * fichiers ne soient jamais accessibles directement depuis le navigateur.
 */
$config = [
    // Photos des logements (noms de fichiers aléatoires).
    'dossier_photos' => 'C:\\wamp64\\migon_photos',

    // Pièces d'identité (accès réservé à l'administration).
    'dossier_prive' => 'C:\\wamp64\\migon_prive',
];

$fichierLocal = __DIR__ . '/app.local.php';

if (is_file($fichierLocal)) {
    $surcharge = require $fichierLocal;

    if (is_array($surcharge)) {
        $config = array_merge($config, $surcharge);
    }
}

$variablesEnvironnement = [
    'dossier_photos' => 'MIGON_DOSSIER_PHOTOS',
    'dossier_prive' => 'MIGON_DOSSIER_PRIVE',
];

foreach ($variablesEnvironnement as $cle => $nomVariable) {
    $valeur = getenv($nomVariable);

    if (is_string($valeur) && $valeur !== '') {
        $config[$cle] = $valeur;
    }
}

return $config;
