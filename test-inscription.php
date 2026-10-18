<?php

require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Utilisateur.php';

try {
    $databaseClass = 'Database';
    $database = new $databaseClass();
    $pdo = $database->getConnection();

    $utilisateurClass = 'Utilisateur';
    $utilisateur = new $utilisateurClass($pdo);

    $id = $utilisateur->inscrire(
        'Test MIGON',
        '96000003',
        'test.migon3@example.com',
        'MigonTest2026!',
        'chercheur'
    );

    echo 'Inscription réussie !<br>';
    echo 'Identifiant du compte : ' . $id;

} catch (InvalidArgumentException $e) {
    echo 'Données invalides : ' .
        htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');

} catch (PDOException $e) {
    echo '<h3>Erreur SQL détectée</h3>';
    echo '<pre>';
    echo htmlspecialchars(
        $e->getMessage(),
        ENT_QUOTES,
        'UTF-8'
    );
    echo '</pre>';

    error_log($e->getMessage());

} catch (Throwable $e) {
    echo 'Erreur : ' .
        htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    error_log($e->getMessage());
}
?>