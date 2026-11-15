<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../classes/Database.php';

function retour(string $type, string $message): never
{
    $_SESSION[$type] = $message;
    header('Location: logements.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: logements.php');
    exit;
}

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!in_array('proprietaire', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

$jeton = $_POST['csrf_token'] ?? '';

if (
    !is_string($jeton)
    || !isset($_SESSION['csrf_token'])
    || !hash_equals($_SESSION['csrf_token'], $jeton)
) {
    retour('erreur_logement', 'La demande a expiré. Réessaie.');
}

$idLogement = filter_input(INPUT_POST, 'id_logement', FILTER_VALIDATE_INT);
$idProprietaire = (int) $_SESSION['utilisateur_id'];

if (!$idLogement || $idLogement < 1) {
    retour('erreur_logement', 'Logement invalide.');
}

try {
    $pdo = (new Database())->getConnection();
    $pdo->beginTransaction();

    /*
     * Verrouiller le compte du propriétaire pour éviter
     * que deux demandes simultanées dépassent le quota.
     */
    $stmt = $pdo->prepare(
        'SELECT id_utilisateur
         FROM utilisateur
         WHERE id_utilisateur = ?
         FOR UPDATE'
    );
    $stmt->execute([$idProprietaire]);

    if (!$stmt->fetch()) {
        throw new RuntimeException('Compte introuvable.');
    }

    // Vérifier que le logement appartient au propriétaire.
    $stmt = $pdo->prepare(
        'SELECT id_logement, statut, date_suppression, latitude, longitude
         FROM logement
         WHERE id_logement = ?
           AND id_proprietaire = ?
         FOR UPDATE'
    );
    $stmt->execute([$idLogement, $idProprietaire]);
    $logement = $stmt->fetch();

    if (!$logement || $logement['date_suppression'] !== null) {
        throw new RuntimeException(
            'Ce logement est introuvable ou a été supprimé.'
        );
    }

    if ($logement['statut'] === 'publie') {
        throw new RuntimeException('Ce logement est déjà publié.');
    }

    // La position est indispensable à la recherche « autour de moi ».
    if ($logement['latitude'] === null || $logement['longitude'] === null) {
        throw new RuntimeException(
            'Enregistre la position géographique du logement '
            . '(bouton « Modifier ») avant de le publier.'
        );
    }

    // Vérifier les trois photos minimum.
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM photo_logement
         WHERE id_logement = ?'
    );
    $stmt->execute([$idLogement]);
    $nombrePhotos = (int) $stmt->fetchColumn();

    if ($nombrePhotos < 3) {
        throw new RuntimeException(
            'Ajoute au moins 3 photos avant de publier ce logement.'
        );
    }

    // Rechercher une publication déjà enregistrée.
    $stmt = $pdo->prepare(
        'SELECT id_publication, type_quota, id_abonnement
         FROM publication
         WHERE id_logement = ?
         FOR UPDATE'
    );
    $stmt->execute([$idLogement]);
    $publicationExistante = $stmt->fetch();

    if ($publicationExistante) {
        /*
         * Ce logement a déjà consommé son quota lors de sa première
         * publication. Une remise en publication ne consomme donc
         * aucun nouveau quota et ne nécessite pas un abonnement actif.
         * Cela respecte la règle MIGON : à l'expiration, les annonces
         * déjà publiées restent visibles (option A).
         * L'historique de publication est conservé tel quel.
         */
    } else {
        /*
         * Compter toutes les publications gratuites historiques.
         * On ne filtre ni les logements supprimés ni leur statut :
         * le quota ne doit jamais être récupéré.
         */
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM publication p
             INNER JOIN logement l
                 ON l.id_logement = p.id_logement
             WHERE l.id_proprietaire = ?
               AND p.type_quota = 'gratuit'"
        );
        $stmt->execute([$idProprietaire]);
        $publicationsGratuites = (int) $stmt->fetchColumn();

        if ($publicationsGratuites < 3) {
            // Il reste une publication gratuite.
            $stmt = $pdo->prepare(
                "INSERT INTO publication
                    (id_logement, id_abonnement, type_quota)
                 VALUES (?, NULL, 'gratuit')"
            );
            $stmt->execute([$idLogement]);
        } else {
            // Chercher un abonnement actuellement actif.
            $stmt = $pdo->prepare(
                "SELECT id_abonnement, quota_publications
                 FROM abonnement
                 WHERE id_proprietaire = ?
                   AND statut = 'actif'
                   AND date_debut <= NOW()
                   AND date_fin > NOW()
                 ORDER BY date_fin DESC
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([$idProprietaire]);
            $abonnement = $stmt->fetch();

            if (!$abonnement) {
                throw new RuntimeException(
                    'Tes 3 publications gratuites sont utilisées. '
                    . 'Un abonnement actif est nécessaire pour publier '
                    . 'davantage de logements.'
                );
            }

            $idAbonnement = (int) $abonnement['id_abonnement'];

            // Compter les premières publications rattachées à cet abonnement.
            $stmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM publication
                 WHERE id_abonnement = ?
                   AND type_quota = 'abonnement'"
            );
            $stmt->execute([$idAbonnement]);
            $publicationsPayantes = (int) $stmt->fetchColumn();

            $quotaAutorise = (int) $abonnement['quota_publications'];

            if ($publicationsPayantes >= $quotaAutorise) {
                throw new RuntimeException(
                    'Les ' . $quotaAutorise
                    . ' publications de cet abonnement sont utilisées.'
                );
            }

            $stmt = $pdo->prepare(
                "INSERT INTO publication
                    (id_logement, id_abonnement, type_quota)
                 VALUES (?, ?, 'abonnement')"
            );
            $stmt->execute([$idLogement, $idAbonnement]);
        }
    }

    // La publication n'est effective qu'après toutes les vérifications.
    $stmt = $pdo->prepare(
        "UPDATE logement
         SET statut = 'publie'
         WHERE id_logement = ?
           AND id_proprietaire = ?
           AND date_suppression IS NULL"
    );
    $stmt->execute([$idLogement, $idProprietaire]);

    $pdo->commit();

    retour(
        'succes_logement',
        'Le logement a été publié avec succès.'
    );

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($e instanceof RuntimeException) {
        retour('erreur_logement', $e->getMessage());
    }

    error_log('Erreur publication MIGON : ' . $e->getMessage());

    retour(
        'erreur_logement',
        'Une erreur est survenue pendant la publication. Réessaie.'
    );
}
