<?php
session_start();

if (!isset($_SESSION['utilisateur_id'])) {
    header('Location: ../connexion.php');
    exit;
}

$roles = $_SESSION['utilisateur_roles'] ?? [];
if (!in_array('proprietaire', $roles, true)) {
    header('Location: ../index.php');
    exit;
}

function h($valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/../classes/Database.php';
$pdo = (new Database())->getConnection();
$idUtilisateur = (int) $_SESSION['utilisateur_id'];

$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM publication p
     INNER JOIN logement l ON l.id_logement = p.id_logement
     WHERE l.id_proprietaire = ? AND p.type_quota = 'gratuit'"
);
$stmt->execute([$idUtilisateur]);
$gratuitesUtilisees = (int) $stmt->fetchColumn();
$gratuitesRestantes = max(0, 3 - $gratuitesUtilisees);

$stmt = $pdo->prepare(
    "SELECT a.*,
            (SELECT COUNT(*) FROM publication p
             WHERE p.id_abonnement = a.id_abonnement
               AND p.type_quota = 'abonnement') AS publications_utilisees
     FROM abonnement a
     WHERE a.id_proprietaire = ?
     ORDER BY a.date_creation DESC, a.id_abonnement DESC"
);
$stmt->execute([$idUtilisateur]);
$abonnements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT pa.*, a.date_debut, a.date_fin, a.statut AS statut_abonnement
     FROM paiement_abonnement pa
     INNER JOIN abonnement a ON a.id_abonnement = pa.id_abonnement
     WHERE a.id_proprietaire = ?
     ORDER BY pa.date_paiement DESC, pa.id_paiement DESC"
);
$stmt->execute([$idUtilisateur]);
$paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);

function dateFr(?string $date): string
{
    if (!$date) return 'Non définie';
    $timestamp = strtotime($date);
    return $timestamp ? date('d/m/Y à H:i', $timestamp) : 'Non définie';
}

function statutLibelle(string $statut): string
{
    $libelles = [
        'en_attente' => 'En attente',
        'actif' => 'Actif',
        'expire' => 'Expiré',
        'annule' => 'Annulé',
        'refuse' => 'Refusé',
        'confirme' => 'Confirmé',
    ];
    return $libelles[$statut] ?? ucfirst(str_replace('_', ' ', $statut));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon abonnement | MIGON</title>
    <link rel="stylesheet" href="../public/css/style.css">
    <style>
        .page-abonnement{max-width:1100px;margin:35px auto;padding:20px;color:#24332d}
        .entete{display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap;margin-bottom:22px}
        .carte{background:#fff;border:1px solid #e2e8e4;border-radius:12px;padding:20px;margin:18px 0}
        .grille{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px}
        .indicateur{background:#f4f8f5;padding:16px;border-radius:10px}
        .indicateur strong{display:block;font-size:25px;color:#123b32;margin-top:7px}
        .table-responsive{overflow-x:auto}
        table{width:100%;border-collapse:collapse;min-width:680px}
        th,td{text-align:left;padding:12px;border-bottom:1px solid #e6ebe8;vertical-align:top}
        th{color:#123b32;background:#f5f8f6}
        .etat{font-weight:700}
        .actif,.confirme{color:#087443}.attente{color:#9a5700}.expire,.refuse,.annule{color:#b42318}
        .retour{display:inline-block;background:#123b32;color:#fff;padding:10px 15px;border-radius:8px;text-decoration:none}
        .note{background:#f4f8f5;border-left:4px solid #123b32;padding:13px 15px;border-radius:6px;line-height:1.55}
        .vide{color:#68756e;padding:12px 0}
    </style>
</head>
<body>
<main class="page-abonnement">
    <div class="entete">
        <div><h1>Mon abonnement</h1><p>Consultez vos droits de publication, vos abonnements et vos paiements.</p></div>
        <div><a class="retour" href="souscrire-abonnement.php">Souscrire pour 2 000 FCFA</a> <a class="retour" href="index.php">← Retour au tableau de bord</a></div>
    </div>

    <?php if (!empty($_SESSION['succes_souscription'])): ?><p class="note"><?= h($_SESSION['succes_souscription']) ?></p><?php unset($_SESSION['succes_souscription']); endif; ?>
    <?php if (!empty($_SESSION['erreur_souscription'])): ?><p class="note"><?= h($_SESSION['erreur_souscription']) ?></p><?php unset($_SESSION['erreur_souscription']); endif; ?>
    <section class="carte">
        <h2>Mes droits de publication</h2>
        <div class="grille">
            <div class="indicateur"><span>Publications gratuites restantes</span><strong><?= $gratuitesRestantes ?> / 3</strong><small><?= $gratuitesUtilisees ?> déjà utilisée(s)</small></div>
            <div class="indicateur"><span>Nombre d'abonnements</span><strong><?= count($abonnements) ?></strong></div>
            <div class="indicateur"><span>Paiements enregistrés</span><strong><?= count($paiements) ?></strong></div>
        </div>
    </section>

    <div class="note">
        Les logements déjà publiés restent visibles même lorsque votre abonnement expire. En revanche, les publications payantes non utilisées à la date d'expiration sont perdues. La modification d'un logement déjà enregistré ne consomme pas une nouvelle publication.
    </div>

    <section class="carte">
        <h2>Historique de mes abonnements</h2>
        <?php if (!$abonnements): ?>
            <p class="vide">Vous n'avez pas encore d'abonnement enregistré.</p>
        <?php else: ?>
            <div class="table-responsive"><table>
                <thead><tr><th>Référence</th><th>Créé le</th><th>Période</th><th>Montant</th><th>Publications</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ($abonnements as $a):
                    $quota = (int) ($a['quota_publications'] ?? 30);
                    $utilisees = (int) $a['publications_utilisees'];
                    $estValide = $a['statut'] === 'actif' && !empty($a['date_debut']) && !empty($a['date_fin']) && strtotime($a['date_debut']) <= time() && strtotime($a['date_fin']) > time();
                    $statutAffiche = $a['statut'];
                    if ($a['statut'] === 'actif' && !$estValide && !empty($a['date_fin']) && strtotime($a['date_fin']) <= time()) $statutAffiche = 'expire';
                ?>
                    <tr>
                        <td>#<?= (int) $a['id_abonnement'] ?></td>
                        <td><?= h(dateFr($a['date_creation'])) ?></td>
                        <td>Du <?= h(dateFr($a['date_debut'])) ?><br>Au <?= h(dateFr($a['date_fin'])) ?></td>
                        <td><?= h(number_format((float) $a['montant'], 0, ',', ' ')) ?> <?= h($a['devise']) ?></td>
                        <td><?= $utilisees ?> / <?= $quota ?><br><small><?= $estValide ? max(0, $quota - $utilisees) . ' restante(s)' : 'Droits non actifs' ?></small></td>
                        <td class="etat <?= h($statutAffiche) ?>"><?= h(statutLibelle($statutAffiche)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="carte">
        <h2>Historique des paiements</h2>
        <?php if (!$paiements): ?>
            <p class="vide">Aucun paiement enregistré pour le moment.</p>
        <?php else: ?>
            <div class="table-responsive"><table>
                <thead><tr><th>Date</th><th>Abonnement</th><th>Montant</th><th>Méthode</th><th>Référence</th><th>Statut</th></tr></thead>
                <tbody>
                <?php foreach ($paiements as $p): ?>
                    <tr>
                        <td><?= h(dateFr($p['date_paiement'])) ?></td>
                        <td>#<?= (int) $p['id_abonnement'] ?></td>
                        <td><?= h(number_format((float) $p['montant'], 0, ',', ' ')) ?> <?= h($p['devise']) ?></td>
                        <td><?= h(ucfirst(str_replace('_', ' ', $p['methode_paiement']))) ?></td>
                        <td><?= h($p['reference_paiement'] ?: '—') ?></td>
                        <td class="etat <?= h($p['statut_paiement']) ?>"><?= h(statutLibelle($p['statut_paiement'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
