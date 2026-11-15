<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Geolocalisation.php';

function echouer(string $message, int $idLogement = 0): never
{
    $_SESSION['erreur_modification'] = $message;

    $url = 'modifier-logement.php';

    if ($idLogement > 0) {
        $url .= '?id=' . $idLogement;
    }

    header('Location: ' . $url);
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
    http_response_code(403);
    exit('Accès interdit.');
}

$idLogement = filter_input(
    INPUT_POST,
    'id_logement',
    FILTER_VALIDATE_INT
);

if (!$idLogement || $idLogement < 1) {
    echouer('Identifiant du logement invalide.');
}

$token = $_POST['csrf_token'] ?? '';

if (
    !is_string($token) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $token)
) {
    echouer('La session du formulaire a expiré. Réessaie.', $idLogement);
}

$titre = trim($_POST['titre'] ?? '');
$description = trim($_POST['description'] ?? '');
$type = $_POST['type_logement'] ?? '';
$ville = trim($_POST['ville'] ?? '');
$quartier = trim($_POST['quartier'] ?? '');
$adresse = trim($_POST['adresse'] ?? '');

if (
    $titre === '' ||
    $description === '' ||
    $ville === '' ||
    $adresse === '' ||
    !in_array($type, ['chambre', 'appartement'], true)
) {
    echouer('Remplis tous les champs obligatoires.', $idLogement);
}

if (
    mb_strlen($titre) > 180 ||
    mb_strlen($description) > 10000 ||
    mb_strlen($ville) > 120 ||
    mb_strlen($quartier) > 120 ||
    mb_strlen($adresse) > 255
) {
    echouer('Un des champs contient trop de caractères.', $idLogement);
}

// Position géographique : facultative. Si elle est vide, la position
// déjà enregistrée est conservée ; si elle est fournie, elle doit être valide.
$latitudeBrute = trim((string) ($_POST['latitude'] ?? ''));
$longitudeBrute = trim((string) ($_POST['longitude'] ?? ''));
$latitude = null;
$longitude = null;

if ($latitudeBrute !== '' || $longitudeBrute !== '') {
    $positionLogement = Geolocalisation::valider($latitudeBrute, $longitudeBrute);

    if ($positionLogement === null) {
        echouer('La position géographique du logement est invalide.', $idLogement);
    }

    [$latitude, $longitude] = $positionLogement;
}

// Valider les tarifs : au moins un tarif positif est obligatoire.
$durees = ['nuit', 'semaine', 'mois', 'annee'];
$tarifs = [];

foreach ($durees as $duree) {
    $valeur = trim((string) ($_POST['prix_' . $duree] ?? ''));

    if ($valeur === '') {
        $tarifs[$duree] = null;
        continue;
    }

    if (
        !preg_match('/^\d{1,10}(\.\d{1,2})?$/', $valeur) ||
        (float) $valeur <= 0 ||
        (float) $valeur > 9999999999.99
    ) {
        echouer('Vérifie les tarifs : chaque prix renseigné doit être positif.', $idLogement);
    }

    $tarifs[$duree] = $valeur;
}

$tarifPositif = false;

foreach ($tarifs as $prix) {
    if ($prix !== null && (float) $prix > 0) {
        $tarifPositif = true;
        break;
    }
}

if (!$tarifPositif) {
    echouer('Renseigne au moins un tarif supérieur à zéro.', $idLogement);
}

// Valider les équipements envoyés par le formulaire.
$idsEquipements = $_POST['equipements'] ?? [];

if (!is_array($idsEquipements)) {
    echouer('La liste des équipements est invalide.', $idLogement);
}

$idsEquipements = array_values(array_unique($idsEquipements));

if (count($idsEquipements) > 50) {
    echouer('Trop d’équipements sélectionnés.', $idLogement);
}

foreach ($idsEquipements as $idEquipement) {
    if (
        !is_string($idEquipement) &&
        !is_int($idEquipement)
    ) {
        echouer('Un équipement sélectionné est invalide.', $idLogement);
    }

    if (
        !ctype_digit((string) $idEquipement) ||
        (int) $idEquipement < 1
    ) {
        echouer('Un équipement sélectionné est invalide.', $idLogement);
    }
}

$idsEquipements = array_map('intval', $idsEquipements);

try {
    $pdo = (new Database())->getConnection();
    $idProprietaire = (int) $_SESSION['utilisateur_id'];

    $pdo->beginTransaction();

    // Verrouiller et vérifier le logement du propriétaire.
    $stmt = $pdo->prepare(
        "SELECT id_logement
         FROM logement
         WHERE id_logement = ?
           AND id_proprietaire = ?
           AND date_suppression IS NULL
         FOR UPDATE"
    );

    $stmt->execute([$idLogement, $idProprietaire]);

    if (!$stmt->fetch()) {
        $pdo->rollBack();
        http_response_code(404);
        exit('Logement introuvable ou accès non autorisé.');
    }

    // Mettre à jour les informations du logement.
    $stmt = $pdo->prepare(
        "UPDATE logement
         SET titre = ?,
             description = ?,
             type_logement = ?,
             ville = ?,
             quartier = ?,
             adresse = ?,
             latitude = COALESCE(?, latitude),
             longitude = COALESCE(?, longitude),
             date_modification = NOW()
         WHERE id_logement = ?
           AND id_proprietaire = ?"
    );

    $stmt->execute([
        $titre,
        $description,
        $type,
        $ville,
        $quartier !== '' ? $quartier : null,
        $adresse,
        $latitude,
        $longitude,
        $idLogement,
        $idProprietaire
    ]);

    // Synchroniser les tarifs : un tarif vide est supprimé.
    foreach ($tarifs as $duree => $prix) {
        if ($prix === null) {
            $stmt = $pdo->prepare(
                'DELETE FROM tarif_location
                 WHERE id_logement = ? AND duree_location = ?'
            );

            $stmt->execute([$idLogement, $duree]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id_tarif
                 FROM tarif_location
                 WHERE id_logement = ? AND duree_location = ?
                 LIMIT 1'
            );

            $stmt->execute([$idLogement, $duree]);
            $idTarif = $stmt->fetchColumn();

            if ($idTarif) {
                $stmt = $pdo->prepare(
                    'UPDATE tarif_location
                     SET prix = ?, devise = ? 
                     WHERE id_tarif = ?'
                );

                $stmt->execute([$prix, 'XOF', $idTarif]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO tarif_location
                     (id_logement, duree_location, prix, devise)
                     VALUES (?, ?, ?, ?)'
                );

                $stmt->execute([
                    $idLogement,
                    $duree,
                    $prix,
                    'XOF'
                ]);
            }
        }
    }

    // Remplacer les associations d'équipements.
    if ($idsEquipements) {
        $placeholders = implode(
            ',',
            array_fill(0, count($idsEquipements), '?')
        );

        $stmt = $pdo->prepare(
            "SELECT id_equipement
             FROM equipement
             WHERE id_equipement IN ($placeholders)"
        );

        $stmt->execute($idsEquipements);

        $idsValides = array_map(
            'intval',
            array_column($stmt->fetchAll(), 'id_equipement')
        );

        if (count($idsValides) !== count($idsEquipements)) {
            $pdo->rollBack();
            echouer('Un équipement sélectionné n’existe plus.', $idLogement);
        }
    }

    $stmt = $pdo->prepare(
        'DELETE FROM logement_equipement WHERE id_logement = ?'
    );
    $stmt->execute([$idLogement]);

    $stmt = $pdo->prepare(
        'INSERT INTO logement_equipement
         (id_logement, id_equipement)
         VALUES (?, ?)'
    );

    foreach ($idsEquipements as $idEquipement) {
        $stmt->execute([$idLogement, $idEquipement]);
    }

    // Les photos existantes ne sont pas modifiées.
    $pdo->commit();

    $_SESSION['succes_logement'] = 'Les modifications ont été enregistrées avec succès.';

    header('Location: logements.php');
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Erreur modification logement : ' . $e->getMessage());

    echouer(
        'Une erreur est survenue lors de l’enregistrement. Vérifie les données et réessaie.',
        $idLogement
    );
}
