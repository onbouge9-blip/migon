
<?php
session_start();

require_once __DIR__ . '/../classes/Database.php';

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!in_array('proprietaire', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$pdo = (new Database())->getConnection();

$idProprietaire = (int) $_SESSION['utilisateur_id'];

$sql = "
SELECT
    l.*,

    (
        SELECT chemin_photo
        FROM photo_logement p
        WHERE p.id_logement = l.id_logement
        ORDER BY est_principale DESC, ordre_affichage ASC
        LIMIT 1
    ) AS photo,

    (
        SELECT MIN(prix)
        FROM tarif_location t
        WHERE t.id_logement = l.id_logement
    ) AS prix_min

FROM logement l
WHERE l.id_proprietaire = :id
ORDER BY l.date_creation DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $idProprietaire]);

$logements = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Mes logements | MIGON</title>

<link rel="stylesheet" href="../public/css/style.css">

<style>
.page{
    max-width:1200px;
    margin:35px auto;
    padding:20px;
}
.entete{
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:30px;
}
.bouton{
    background:#0f4d3f;
    color:white;
    padding:12px 18px;
    border-radius:8px;
    text-decoration:none;
}
.grille{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(310px,1fr));
    gap:25px;
}
.carte{
    background:white;
    border-radius:14px;
    overflow:hidden;
    border:1px solid #e3e8e5;
}
.photo{
    height:220px;
    background:#eef2ef;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#666;
}
.photo img{
    width:100%;
    height:100%;
    object-fit:cover;
}
.contenu{
    padding:18px;
}
.badge{
    display:inline-block;
    padding:6px 12px;
    border-radius:20px;
    font-size:13px;
    font-weight:bold;
}
.brouillon{
    background:#fff5d6;
    color:#8a6700;
}
.publie{
    background:#d9f5e5;
    color:#0f7a46;
}
.masque{
    background:#ffe4e4;
    color:#b22222;
}
.actions{
    margin-top:18px;
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}
.actions a{
    text-decoration:none;
    padding:9px 12px;
    border-radius:8px;
    font-size:14px;
}
.modifier{
    background:#eef4ff;
}
.supprimer{
    background:#ffecec;
}
.publier{
    background:#0f4d3f;
    color:white;
}
</style>

</head>
<body>

<main class="page">

<div class="entete">
    <div>
        <h1>Mes logements</h1>
        <p><?= count($logements) ?> logement(s) enregistré(s)</p>
    </div>

    <a class="bouton" href="creer-logement.php">
        + Nouveau logement
    </a>
</div>

<?php if (!$logements): ?>

    <p>Aucun logement enregistré.</p>

<?php else: ?>

<div class="grille">

<?php foreach($logements as $l): ?>

<div class="carte">

    <div class="photo">

        <?php if($l['photo']): ?>

            <img src="afficher-photo.php?id=<?= $l['id_logement'] ?>">

        <?php else: ?>

            Aucune photo

        <?php endif; ?>

    </div>

    <div class="contenu">

        <h2><?= h($l['titre']) ?></h2>

        <p><?= h($l['quartier'] ?: $l['ville']) ?></p>

        <p>
            <strong>
                <?= number_format((float)$l['prix_min'],0,',',' ') ?>
                FCFA
            </strong>
        </p>

        <span class="badge <?= h($l['statut']) ?>">
            <?= h(ucfirst($l['statut'])) ?>
        </span>

        <div class="actions">

            <a class="modifier"
               href="modifier-logement.php?id=<?= $l['id_logement'] ?>">
                Modifier
            </a>

            <a class="supprimer"
               href="supprimer-logement.php?id=<?= $l['id_logement'] ?>">
                Supprimer
            </a>

            <?php if($l['statut']==='brouillon'): ?>

            <a class="publier"
               href="publier-logement.php?id=<?= $l['id_logement'] ?>">
                Publier
            </a>

            <?php endif; ?>

        </div>

    </div>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>

</main>

</body>
</html>
