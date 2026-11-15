<?php
session_start();

require_once __DIR__ . '/../classes/Database.php';

// Vérifier que l'utilisateur est connecté.
if (empty($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

// Vérifier que l'utilisateur est administrateur.
$roles = $_SESSION['utilisateur_roles'] ?? [];

if (!is_array($roles) || !in_array('administrateur', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

// Créer le jeton CSRF pour la validation.
if (empty($_SESSION['csrf_admin_paiement'])) {
    $_SESSION['csrf_admin_paiement'] = bin2hex(random_bytes(32));
}

function h($valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
}

try {
    $databaseClass = '\\Database';
    $pdo = (new $databaseClass())->getConnection();

    // Récupérer toutes les demandes de paiement de réactivation.
    $stmt = $pdo->query("
        SELECT
            pr.id_paiement_reactivation,
            pr.id_logement,
            pr.id_proprietaire,
            pr.montant,
            pr.devise,
            pr.methode_paiement,
            pr.reference_paiement,
            pr.statut_paiement,
            pr.date_creation,
            pr.date_paiement,

            l.titre AS titre_logement,
            l.quartier,
            l.ville,

            u.nom AS nom_proprietaire,
            u.telephone AS telephone_proprietaire

        FROM paiement_reactivation pr

        INNER JOIN logement l
            ON l.id_logement = pr.id_logement

        INNER JOIN utilisateur u
            ON u.id_utilisateur = pr.id_proprietaire

        ORDER BY
            CASE
                WHEN pr.statut_paiement = 'en_attente' THEN 0
                ELSE 1
            END,
            pr.date_creation DESC
    ");

    $paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Throwable $e) {

    error_log(
        'Erreur liste paiements réactivation MIGON : '
        . $e->getMessage()
    );

    $paiements = [];
    $erreurPage = 'Impossible de charger les demandes de paiement.';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Paiements de réactivation | MIGON</title>

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
    gap: 15px;
    margin-bottom: 30px;
}

.bouton-retour {
    display: inline-block;
    padding: 11px 16px;
    border-radius: 8px;
    background: #eef2ef;
    color: #0f4d3f;
    text-decoration: none;
    font-weight: 600;
}

.table-conteneur {
    overflow-x: auto;
    background: white;
    border: 1px solid #e3e8e5;
    border-radius: 14px;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th,
td {
    padding: 14px;
    text-align: left;
    border-bottom: 1px solid #e8ece9;
}

th {
    background: #f5f8f6;
    color: #0f4d3f;
}

.badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: bold;
}

.en_attente {
    background: #fff5d6;
    color: #8a6700;
}

.confirme {
    background: #d9f5e5;
    color: #0f7a46;
}

.refuse {
    background: #ffe4e4;
    color: #b22222;
}

.bouton-confirmer {
    border: none;
    background: #087f5b;
    color: white;
    padding: 9px 13px;
    border-radius: 8px;
    cursor: pointer;
    font: inherit;
    font-weight: 600;
}

.bouton-confirmer:hover {
    background: #066b4c;
}

.message {
    margin-bottom: 20px;
    padding: 14px 16px;
    border-radius: 8px;
    font-weight: 600;
}

.succes {
    background: #d9f5e5;
    color: #0f7a46;
}

.erreur {
    background: #ffe4e4;
    color: #b22222;
}

.vide {
    padding: 30px;
    text-align: center;
    color: #666;
}
.actions-paiement {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.actions-paiement form {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
}

.actions-paiement input[type="text"] {
    width: 180px;
    padding: 8px;
    border: 1px solid #d8dfda;
    border-radius: 7px;
}

.bouton-refuser {
    border: none;
    background: #b22222;
    color: white;
    padding: 9px 13px;
    border-radius: 8px;
    cursor: pointer;
    font: inherit;
    font-weight: 600;
}

.bouton-refuser:hover {
    background: #8f1b1b;
}
</style>
</head>

<body>

<main class="page">

<?php if (!empty($_SESSION['succes_admin_paiement'])): ?>
    <div class="message succes">
        <?= h($_SESSION['succes_admin_paiement']) ?>
    </div>
    <?php unset($_SESSION['succes_admin_paiement']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['erreur_admin_paiement'])): ?>
    <div class="message erreur">
        <?= h($_SESSION['erreur_admin_paiement']) ?>
    </div>
    <?php unset($_SESSION['erreur_admin_paiement']); ?>
<?php endif; ?>

<?php if (!empty($erreurPage)): ?>
    <div class="message erreur">
        <?= h($erreurPage) ?>
    </div>
<?php endif; ?>

<div class="entete">
    <div>
        <h1>Paiements de réactivation</h1>
        <p>Validation des paiements de 500 FCFA pour remettre les logements disponibles.</p>
    </div>

    <a class="bouton-retour" href="index.php">
        ← Retour à l'administration
    </a>
</div>

<div class="table-conteneur">

<?php if (empty($paiements)): ?>

    <div class="vide">
        Aucune demande de paiement pour le moment.
    </div>

<?php else: ?>

<table>
<thead>
<tr>
    <th>Logement</th>
    <th>Propriétaire</th>
    <th>Montant</th>
    <th>Méthode</th>
    <th>Référence</th>
    <th>Date</th>
    <th>Statut</th>
    <th>Action</th>
</tr>
</thead>

<tbody>

<?php foreach ($paiements as $paiement): ?>

<tr>
    <td>
        <strong><?= h($paiement['titre_logement']) ?></strong><br>
        <small>
            <?= h($paiement['quartier'] ?: $paiement['ville']) ?>
        </small>
    </td>

    <td>
        <?= h($paiement['nom_proprietaire']) ?><br>
        <small><?= h($paiement['telephone_proprietaire']) ?></small>
    </td>

    <td>
        <strong>
            <?= number_format(
                (float) $paiement['montant'],
                0,
                ',',
                ' '
            ) ?>
            <?= h($paiement['devise']) ?>
        </strong>
    </td>

    <td>
        <?= h($paiement['methode_paiement'] ?: '-') ?>
    </td>

    <td>
        <?= h($paiement['reference_paiement'] ?: '-') ?>
    </td>

    <td>
        <?= h($paiement['date_paiement'] ?: $paiement['date_creation']) ?>
    </td>

    <td>
        <span class="badge <?= h($paiement['statut_paiement']) ?>">
            <?php
            if ($paiement['statut_paiement'] === 'en_attente') {
                echo 'En attente';
            } elseif ($paiement['statut_paiement'] === 'confirme') {
                echo 'Confirmé';
            } else {
                echo 'Refusé';
            }
            ?>
        </span>
    </td>

    <td>

       <?php if ($paiement['statut_paiement'] === 'en_attente'): ?>

    <div class="actions-paiement">

        <!-- Confirmation du paiement -->
        <form
            action="valider-paiement-reactivation.php"
            method="POST"
            onsubmit="return confirm(
                'Confirmer ce paiement et rendre le logement disponible ?'
            );"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['csrf_admin_paiement']) ?>"
            >

            <input
                type="hidden"
                name="id_paiement"
                value="<?= (int) $paiement['id_paiement_reactivation'] ?>"
            >

            <button
                type="submit"
                class="bouton-confirmer"
            >
                Confirmer
            </button>

        </form>

        <!-- Refus du paiement -->
        <form
            action="refuser-paiement-reactivation.php"
            method="POST"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['csrf_admin_paiement']) ?>"
            >

            <input
                type="hidden"
                name="id_paiement"
                value="<?= (int) $paiement['id_paiement_reactivation'] ?>"
            >

            <input
                type="text"
                name="commentaire"
                placeholder="Motif du refus (facultatif)"
                maxlength="500"
            >

            <button
                type="submit"
                class="bouton-refuser"
                onclick="return confirm('Refuser ce paiement ?');"
            >
                Refuser
            </button>

        </form>

    </div>

<?php else: ?>

    —

<?php endif; ?>

    </td>
</tr>

<?php endforeach; ?>

</tbody>
</table>

<?php endif; ?>

</div>

</main>

</body>
</html>