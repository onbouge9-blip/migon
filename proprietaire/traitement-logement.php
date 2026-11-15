<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Geolocalisation.php';

function echouer(string $message): never
{
    $_SESSION['erreur_logement'] = $message;
    header('Location: creer-logement.php');
    exit;
}

function nettoyer(string $valeur): string
{
    return trim($valeur);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: creer-logement.php');
    exit;
}

if (empty($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!in_array('proprietaire', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

$jetonSession = $_SESSION['csrf_token'] ?? '';
$jetonFormulaire = $_POST['csrf_token'] ?? '';

if (
    !is_string($jetonSession) ||
    !is_string($jetonFormulaire) ||
    $jetonSession === '' ||
    !hash_equals($jetonSession, $jetonFormulaire)
) {
    echouer('La session du formulaire a expiré. Recharge la page et réessaie.');
}

$idProprietaire = (int) $_SESSION['utilisateur_id'];

$titre = nettoyer((string) ($_POST['titre'] ?? ''));
$description = nettoyer((string) ($_POST['description'] ?? ''));
$adresse = nettoyer((string) ($_POST['adresse'] ?? ''));
$ville = nettoyer((string) ($_POST['ville'] ?? 'Cotonou'));
$quartier = nettoyer((string) ($_POST['quartier'] ?? ''));
$typeLogement = (string) ($_POST['type_logement'] ?? '');
$positionLogement = Geolocalisation::valider(
    $_POST['latitude'] ?? '',
    $_POST['longitude'] ?? ''
);

if (
    $titre === '' ||
    $description === '' ||
    $adresse === '' ||
    $ville === '' ||
    !in_array($typeLogement, ['chambre', 'appartement'], true)
) {
    echouer('Remplis tous les champs obligatoires correctement.');
}

if ($positionLogement === null) {
    echouer('Indique la position géographique du logement (bouton GPS ou clic sur la carte).');
}

[$latitude, $longitude] = $positionLogement;

if (mb_strlen($titre) > 180 || mb_strlen($ville) > 120 ||
    mb_strlen($adresse) > 255 || mb_strlen($quartier) > 120) {
    echouer('Un des champs contient trop de caractères.');
}

/*
 * Vérifier les tarifs.
 * Un tarif vide est ignoré.
 */
$champsTarifs = [
    'nuit' => 'prix_nuit',
    'semaine' => 'prix_semaine',
    'mois' => 'prix_mois',
    'annee' => 'prix_annee',
];

$tarifs = [];

foreach ($champsTarifs as $duree => $champ) {
    $valeur = $_POST[$champ] ?? '';

    if ($valeur === '') {
        continue;
    }

    if (!is_numeric($valeur) || (float) $valeur <= 0) {
        echouer('Tous les tarifs renseignés doivent être supérieurs à zéro.');
    }

    if ((float) $valeur > 9999999999.99) {
        echouer('Un tarif est trop élevé.');
    }

    $tarifs[$duree] = number_format((float) $valeur, 2, '.', '');
}

if ($tarifs === []) {
    echouer('Indique au moins un tarif de location.');
}

/*
 * Valider les équipements à partir de la base de données.
 * Le formulaire transmet leurs noms, pas leurs identifiants.
 */
$equipementsDemandes = $_POST['equipements'] ?? [];

if (!is_array($equipementsDemandes)) {
    echouer('La liste des équipements est invalide.');
}

$equipementsDemandes = array_values(array_unique(array_filter(
    $equipementsDemandes,
    static fn($nom): bool => is_string($nom) && trim($nom) !== ''
)));

if (count($equipementsDemandes) > 50) {
    echouer('Le nombre d’équipements sélectionnés est invalide.');
}

/*
 * Vérifier les photos : au moins 3, au maximum 10 pour cette première version.
 */
if (
    !isset($_FILES['photos']) ||
    !is_array($_FILES['photos']['name'] ?? null)
) {
    echouer('Sélectionne au moins trois photos du logement.');
}

$photos = [];
$nombrePhotos = count($_FILES['photos']['name']);

if ($nombrePhotos < 3 || $nombrePhotos > 10) {
    echouer('Sélectionne entre 3 et 10 photos.');
}

$tailleMax = 5 * 1024 * 1024;
$typesAutorises = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

$finfo = new finfo(FILEINFO_MIME_TYPE);

for ($i = 0; $i < $nombrePhotos; $i++) {
    $erreurUpload = $_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
    $taille = (int) ($_FILES['photos']['size'][$i] ?? 0);
    $tmp = $_FILES['photos']['tmp_name'][$i] ?? '';

    if ($erreurUpload !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
        echouer('Une photo n’a pas pu être téléversée correctement.');
    }

    if ($taille <= 0 || $taille > $tailleMax) {
        echouer('Chaque photo doit peser au maximum 5 Mo.');
    }

    $mime = $finfo->file($tmp);

    if (!isset($typesAutorises[$mime])) {
        echouer('Format de photo invalide. Utilise JPG, PNG ou WEBP.');
    }

    $photos[] = [
        'tmp' => $tmp,
        'extension' => $typesAutorises[$mime],
    ];
}

/*
 * Le dossier doit exister et être accessible en écriture.
 * Les noms enregistrés sont aléatoires.
 */
$dossierPhotos = 'C:\\wamp64\\migon_photos';

if (!is_dir($dossierPhotos) || !is_writable($dossierPhotos)) {
    error_log('Le dossier de photos MIGON est absent ou non accessible en écriture.');
    echouer('Le stockage des photos n’est pas prêt. Contacte l’administrateur.');
}

$fichiersDeplaces = [];
$pdo = null;

try {
    $database = new Database();
    $pdo = $database->getConnection();

    /*
     * Vérifier que les équipements reçus existent réellement.
     */
    $idsEquipements = [];

    if ($equipementsDemandes !== []) {
        $placeholders = implode(',', array_fill(0, count($equipementsDemandes), '?'));

        $requeteEquipements = $pdo->prepare(
            "SELECT id_equipement, nom_equipement
             FROM equipement
             WHERE nom_equipement IN ($placeholders)"
        );

        $requeteEquipements->execute($equipementsDemandes);

        foreach ($requeteEquipements->fetchAll() as $equipement) {
            $idsEquipements[] = (int) $equipement['id_equipement'];
        }

        if (count($idsEquipements) !== count($equipementsDemandes)) {
            echouer('Un équipement sélectionné est inconnu. Recharge le formulaire.');
        }
    }

    /*
     * Déplacer les photos vers le dossier privé.
     * Si une opération échoue ensuite, les fichiers déplacés seront supprimés.
     */
    foreach ($photos as $photo) {
        $nomFichier = bin2hex(random_bytes(24)) . '.' . $photo['extension'];
        $destination = $dossierPhotos . DIRECTORY_SEPARATOR . $nomFichier;

        if (!move_uploaded_file($photo['tmp'], $destination)) {
            throw new RuntimeException('Impossible de stocker une photo.');
        }

        $fichiersDeplaces[] = $destination;
    }

    $pdo->beginTransaction();

    /*
     * Créer le logement en brouillon.
     * La publication n'est pas automatique.
     */
   $requeteLogement = $pdo->prepare(
    "INSERT INTO logement
        (
            id_proprietaire,
            titre,
            description,
            adresse,
            ville,
            quartier,
            latitude,
            longitude,
            type_logement,
            statut
        )
     VALUES
        (
            :id_proprietaire,
            :titre,
            :description,
            :adresse,
            :ville,
            :quartier,
            :latitude,
            :longitude,
            :type_logement,
            'brouillon'
        )"
);

   $requeteLogement->execute([
    'id_proprietaire' => $idProprietaire,
    'titre' => $titre,
    'description' => $description,
    'adresse' => $adresse,
    'ville' => $ville,
    'quartier' => $quartier !== '' ? $quartier : null,
    'latitude' => $latitude,
    'longitude' => $longitude,
    'type_logement' => $typeLogement,
]);

    $idLogement = (int) $pdo->lastInsertId();

    /*
     * Enregistrer tous les tarifs renseignés.
     */
    $requeteTarif = $pdo->prepare(
        'INSERT INTO tarif_location
            (id_logement, duree_location, prix, devise)
         VALUES
            (:id_logement, :duree_location, :prix, :devise)'
    );

    foreach ($tarifs as $duree => $prix) {
        $requeteTarif->execute([
            'id_logement' => $idLogement,
            'duree_location' => $duree,
            'prix' => $prix,
            'devise' => 'XOF',
        ]);
    }

    /*
     * Associer les équipements sélectionnés.
     */
    $requeteEquipement = $pdo->prepare(
        'INSERT INTO logement_equipement (id_logement, id_equipement)
         VALUES (:id_logement, :id_equipement)'
    );

    foreach ($idsEquipements as $idEquipement) {
        $requeteEquipement->execute([
            'id_logement' => $idLogement,
            'id_equipement' => $idEquipement,
        ]);
    }

    /*
     * Enregistrer les photos.
     * La première photo est la photo principale.
     */
    $requetePhoto = $pdo->prepare(
        'INSERT INTO photo_logement
            (id_logement, chemin_photo, est_principale, ordre_affichage)
         VALUES
            (:id_logement, :chemin_photo, :est_principale, :ordre_affichage)'
    );

    foreach ($fichiersDeplaces as $index => $chemin) {
        $requetePhoto->execute([
            'id_logement' => $idLogement,
            'chemin_photo' => $chemin,
            'est_principale' => $index === 0 ? 1 : 0,
            'ordre_affichage' => $index + 1,
        ]);
    }

    $pdo->commit();

    $_SESSION['succes_logement'] =
        'Le logement a été enregistré en brouillon. Tu pourras préparer sa publication après vérification.';

    header('Location: logements.php');
    exit;

} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    foreach ($fichiersDeplaces as $chemin) {
        if (is_file($chemin)) {
            unlink($chemin);
        }
    }

    error_log('Erreur création logement MIGON : ' . $e->getMessage());

    echouer('Impossible d’enregistrer le logement. Vérifie les informations et réessaie.');
}
