<?php
session_start();
if (empty($_SESSION['utilisateur_id'])) { header('Location: ../connexion.php'); exit; }
$roles = $_SESSION['utilisateur_roles'] ?? [];
if (!is_array($roles) || !in_array('proprietaire', $roles, true)) { header('Location: ../index.php'); exit; }
require_once __DIR__ . '/../classes/Database.php';
$pdo = (new Database())->getConnection();
$idUtilisateur = (int) $_SESSION['utilisateur_id'];
if (empty($_SESSION['csrf_souscription'])) $_SESSION['csrf_souscription'] = bin2hex(random_bytes(32));
$stmt = $pdo->prepare("SELECT COUNT(*) FROM abonnement WHERE id_proprietaire=? AND statut='actif' AND date_fin > CURRENT_TIMESTAMP");
$stmt->execute([$idUtilisateur]);
$abonnementActif = (int)$stmt->fetchColumn() > 0;
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Souscrire | MIGON</title><link rel="stylesheet" href="../public/css/style.css"><style>.page{max-width:760px;margin:40px auto;padding:20px}.card{background:#fff;border:1px solid #e2e8e4;border-radius:14px;padding:28px}.prix{font-size:34px;font-weight:800;color:#087f5b}.bouton{display:inline-block;background:#123b32;color:#fff;border:0;border-radius:8px;padding:12px 18px;text-decoration:none;cursor:pointer}.champ{margin:18px 0}.champ label{display:block;font-weight:700;margin-bottom:7px}.champ select,.champ input{width:100%;padding:12px;border:1px solid #ccd6d0;border-radius:8px}.info{background:#e7f5ee;padding:14px;border-radius:8px;line-height:1.6}.erreur{background:#fff1f0;color:#a61b1b;padding:12px;border-radius:8px}</style></head><body><main class="page"><p><a href="abonnement.php">← Retour à mon abonnement</a></p><section class="card"><h1>Souscrire à MIGON</h1><p class="prix">2 000 FCFA</p><div class="info"><strong>Votre abonnement comprend :</strong><br>• 30 jours après validation du paiement<br>• 30 nouvelles publications<br>• Les publications non utilisées sont perdues à l'expiration<br>• Les logements déjà publiés restent visibles</div>
<?php if ($abonnementActif): ?><p class="erreur">Vous avez déjà un abonnement actif. Vous pourrez souscrire à nouveau après son expiration.</p><?php else: ?>
<form method="post" action="traitement-souscription.php"><input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_souscription']) ?>"><div class="champ"><label for="methode">Méthode de paiement</label><select id="methode" name="methode_paiement" required><option value="">Choisir une méthode</option><option value="mobile_money">Mobile Money</option><option value="virement">Virement</option><option value="especes">Espèces</option><option value="autre">Autre</option></select></div><div class="champ"><label for="reference">Référence du paiement (facultative)</label><input id="reference" name="reference_paiement" maxlength="190"></div><button class="bouton" type="submit">Enregistrer ma demande de paiement</button></form><p><small>Votre abonnement ne sera activé qu'après validation du paiement par l'administration.</small></p><?php endif; ?></section></main></body></html>
