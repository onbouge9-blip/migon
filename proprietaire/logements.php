<?php
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/../classes/Database.php';

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('proprietaire', $roles, true)) {
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
        SELECT id_photo
        FROM photo_logement p
        WHERE p.id_logement = l.id_logement
        ORDER BY est_principale DESC, ordre_affichage ASC
        LIMIT 1
    ) AS photo,

    (
        SELECT MIN(prix)
        FROM tarif_location t
        WHERE t.id_logement = l.id_logement
    ) AS prix_min,

    (
        SELECT COUNT(*)
        FROM paiement_reactivation pr
        WHERE pr.id_logement = l.id_logement
          AND pr.id_proprietaire = l.id_proprietaire
          AND pr.statut_paiement = 'en_attente'
    ) AS reactivation_en_attente

FROM logement l
WHERE l.id_proprietaire = :id
  AND l.date_suppression IS NULL
ORDER BY l.date_creation DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    'id' => $idProprietaire
]);

$logements = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Mes logements | MIGON</title>

<link rel="stylesheet" href="../public/css/style.css">

<style>

.page {
    max-width: 1200px;
    margin: 35px auto;
    padding: 20px;
}

.entete {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    gap: 15px;
    flex-wrap: wrap;
}

.bouton {
    background: #0f4d3f;
    color: white;
    padding: 12px 18px;
    border-radius: 8px;
    text-decoration: none;
}

.actions-entete {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.bouton-retour {
    display: inline-block;
    background: #eef2ef;
    color: #0f4d3f;
    padding: 12px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 600;
}

.bouton-retour:hover {
    background: #dce7e0;
}

.grille {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
    gap: 25px;
}

.carte {
    background: white;
    border-radius: 14px;
    overflow: hidden;
    border: 1px solid #e3e8e5;
}

.photo {
    height: 220px;
    background: #eef2ef;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #666;
}

.photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.contenu {
    padding: 18px;
}

.badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: bold;
}

.brouillon {
    background: #fff5d6;
    color: #8a6700;
}

.publie {
    background: #d9f5e5;
    color: #0f7a46;
}

.masque {
    background: #ffe4e4;
    color: #b22222;
}

/* Disponibilité */

.disponibilite {
    margin-left: 8px;
}

.disponible {
    background: #d9f5e5;
    color: #0f7a46;
}

.indisponible {
    background: #ffe4e4;
    color: #b22222;
}

/* Actions */

.actions {
    margin-top: 18px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}

.actions a,
.actions button {
    text-decoration: none;
    padding: 9px 12px;
    border-radius: 8px;
    font-size: 14px;
    font: inherit;
}

.actions form {
    margin: 0;
}

.modifier {
    background: #eef4ff;
    color: #183b75;
}

.supprimer {
    border: none;
    cursor: pointer;
    background: #ffecec;
    color: #b22222;
}

.publier {
    border: none;
    cursor: pointer;
    background: #0f4d3f;
    color: white;
}

.indisponible-btn {
    border: none;
    background: #fff5d6;
    color: #8a6700;
    cursor: pointer;
}

.disponible-btn {
    display: inline-block;
    background: #087f5b;
    color: white;
    padding: 9px 12px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
}

.disponible-btn:hover {
    background: #066b4c;
}

/* Messages */

.message-disponibilite {
    margin-bottom: 20px;
    padding: 14px 16px;
    border-radius: 8px;
    font-weight: 600;
}

.succes-disponibilite {
    background: #d9f5e5;
    color: #0f7a46;
    border: 1px solid #b8e6c9;
}

.erreur-disponibilite {
    background: #ffe4e4;
    color: #b22222;
    border: 1px solid #f5bcbc;
}

@media (max-width: 600px) {
    .page {
        padding: 15px;
    }
}
.reactivation-attente {
    margin-top: 12px;
    padding: 10px 12px;
    background: #fff5d6;
    color: #8a6700;
    border: 1px solid #f0d98a;
    border-radius: 8px;
    font-size: 14px;
    line-height: 1.4;
}

</style>

</head>

<body>

<main class="page">

    <?php if (!empty($_SESSION['succes_logement'])): ?>
        <div class="message-disponibilite succes-disponibilite">
            <?= h($_SESSION['succes_logement']) ?>
        </div>
        <?php unset($_SESSION['succes_logement']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['erreur_logement'])): ?>
        <div class="message-disponibilite erreur-disponibilite">
            <?= h($_SESSION['erreur_logement']) ?>
        </div>
        <?php unset($_SESSION['erreur_logement']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['succes_disponibilite'])): ?>
        <div class="message-disponibilite succes-disponibilite">
            <?= h($_SESSION['succes_disponibilite']) ?>
        </div>
        <?php unset($_SESSION['succes_disponibilite']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['erreur_disponibilite'])): ?>
        <div class="message-disponibilite erreur-disponibilite">
            <?= h($_SESSION['erreur_disponibilite']) ?>
        </div>
        <?php unset($_SESSION['erreur_disponibilite']); ?>
    <?php endif; ?>


    <div class="entete">

        <div>
            <h1>Mes logements</h1>
            <p><?= count($logements) ?> logement(s) enregistré(s)</p>
        </div>

        <div class="actions-entete">

            <a class="bouton-retour" href="index.php">
                ← Retour à mon espace
            </a>

            <a class="bouton" href="creer-logement.php">
                + Nouveau logement
            </a>

        </div>

    </div>


    <?php if (empty($logements)): ?>

        <p>Aucun logement enregistré.</p>

    <?php else: ?>

        <div class="grille">

            <?php foreach ($logements as $l): ?>

                <div class="carte">

                    <div class="photo">

                        <?php if (!empty($l['photo'])): ?>

                            <img
                                src="afficher-photo.php?id=<?= (int) $l['id_logement'] ?>&amp;photo=<?= (int) $l['photo'] ?>"
                                alt="Photo du logement"
                            >

                        <?php else: ?>

                            Aucune photo

                        <?php endif; ?>

                    </div>


                    <div class="contenu">

                        <h2><?= h($l['titre']) ?></h2>

                        <p>
                            <?= h($l['quartier'] ?: $l['ville']) ?>
                        </p>

                        <?php if ($l['prix_min'] !== null): ?>
                            <p>
                                <strong>
                                    <?= number_format(
                                        (float) $l['prix_min'],
                                        0,
                                        ',',
                                        ' '
                                    ) ?>
                                    FCFA
                                </strong>
                            </p>
                        <?php else: ?>
                            <p>Prix non renseigné</p>
                        <?php endif; ?>


                        <!-- Statut du logement -->
                        <span class="badge <?= h($l['statut']) ?>">
                            <?= h(ucfirst($l['statut'])) ?>
                        </span>


                        <!-- Position géographique manquante -->
                        <?php if ($l['latitude'] === null || $l['longitude'] === null): ?>
                            <span class="badge" style="background:#fff8e6;color:#7a5200;"
                                  title="Ce logement n'apparaît pas dans la recherche « autour de moi ».">
                                Position non renseignée
                            </span>
                        <?php endif; ?>

                        <!-- Disponibilité -->
                        <span class="badge disponibilite <?= h($l['disponibilite'] ?? 'disponible') ?>">
                            <?= ($l['disponibilite'] ?? 'disponible') === 'disponible'
                                ? 'Disponible'
                                : 'Indisponible' ?>
                        </span>

                              <?php if (
    $l['disponibilite'] === 'indisponible'
    && (int) $l['reactivation_en_attente'] > 0
): ?>

    <p class="reactivation-attente">
        ⏳ Demande de réactivation en attente de confirmation du paiement.
    </p>

<?php endif; ?>
                        <div class="actions">

                            <a
                                class="modifier"
                                href="modifier-logement.php?id=<?= (int) $l['id_logement'] ?>"
                            >
                                Modifier
                            </a>


                            <!-- Supprimer -->

                            <form
                                action="supprimer-logement.php"
                                method="POST"
                                onsubmit="return confirm('Veux-tu vraiment supprimer ce logement ?');"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= h($_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="id_logement"
                                    value="<?= (int) $l['id_logement'] ?>"
                                >

                                <button type="submit" class="supprimer">
                                    Supprimer
                                </button>

                            </form>


                            <!-- Publier -->

                            <?php if ($l['statut'] === 'brouillon'): ?>

                                <form
                                    action="publier-logement.php"
                                    method="POST"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h($_SESSION['csrf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id_logement"
                                        value="<?= (int) $l['id_logement'] ?>"
                                    >

                                    <button class="publier" type="submit">
                                        Publier
                                    </button>

                                </form>

                            <?php endif; ?>


                            <!-- Gestion de la disponibilité -->

                            <?php if (($l['disponibilite'] ?? 'disponible') === 'disponible'): ?>

                                <form
                                    action="changer-disponibilite.php"
                                    method="POST"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?= h($_SESSION['csrf_token']) ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="id_logement"
                                        value="<?= (int) $l['id_logement'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="rendre_indisponible"
                                    >

                                    <button
                                        type="submit"
                                        class="indisponible-btn"
                                        onclick="return confirm('Ce logement sera marqué comme indisponible et ne sera plus proposé aux chercheurs. Continuer ?');"
                                    >
                                        Rendre indisponible
                                    </button>

                                </form>


                            <?php elseif (($l['disponibilite'] ?? '') === 'indisponible'): ?>

                                <a
                                    href="reactiver-logement.php?id=<?= (int) $l['id_logement'] ?>"
                                    class="disponible-btn"
                                >
                                    Rendre disponible — 500 FCFA
                                </a>

                            <?php endif; ?>
                            <?php if ($l['disponibilite'] === 'indisponible'): ?>

    <form action="reactiver-logement.php" method="POST">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= h($_SESSION['csrf_token']) ?>"
        >

        <input
            type="hidden"
            name="id_logement"
            value="<?= (int) $l['id_logement'] ?>"
        >

        <button
            type="submit"
            class="disponible-btn"
        >
            Réactiver — 500 FCFA
        </button>

    </form>

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