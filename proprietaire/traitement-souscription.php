<?php
session_start();
if (empty($_SESSION['utilisateur_id'])) { header('Location: ../connexion.php'); exit; }
$roles=$_SESSION['utilisateur_roles']??[]; if(!is_array($roles)||!in_array('proprietaire',$roles,true)){http_response_code(403);exit('Accès refusé.');}
if($_SERVER['REQUEST_METHOD']!=='POST'){header('Location: souscrire-abonnement.php');exit;}
$token=$_POST['csrf_token']??''; if(empty($_SESSION['csrf_souscription'])||!is_string($token)||!hash_equals($_SESSION['csrf_souscription'],$token)){http_response_code(403);exit('Formulaire expiré. Rechargez la page.');}
$methode=$_POST['methode_paiement']??'';$reference=trim((string)($_POST['reference_paiement']??''));$autorise=['mobile_money','virement','especes','autre'];
if(!in_array($methode,$autorise,true)||strlen($reference)>190){exit('Informations de paiement invalides.');}
require_once __DIR__.'/../classes/Database.php';$pdo=(new Database())->getConnection();$id=(int)$_SESSION['utilisateur_id'];
try{$pdo->beginTransaction();$q=$pdo->prepare("SELECT COUNT(*) FROM abonnement WHERE id_proprietaire=? AND statut='actif' AND date_fin>CURRENT_TIMESTAMP FOR UPDATE");$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Un abonnement est déjà actif.');
$q=$pdo->prepare("SELECT COUNT(*) FROM abonnement WHERE id_proprietaire=? AND statut='en_attente'");$q->execute([$id]);if((int)$q->fetchColumn()>0)throw new RuntimeException('Une demande de paiement est déjà en attente.');
$q=$pdo->prepare("INSERT INTO abonnement (id_proprietaire,montant,devise,statut,quota_publications) VALUES (?,2000,'XOF','en_attente',30)");$q->execute([$id]);$idAb=(int)$pdo->lastInsertId();
$q=$pdo->prepare("INSERT INTO paiement_abonnement (id_abonnement,montant,devise,methode_paiement,reference_paiement,statut_paiement) VALUES (?,2000,'XOF',?,?,'en_attente')");$q->execute([$idAb,$methode,$reference!==''?$reference:null]);$pdo->commit();unset($_SESSION['csrf_souscription']);$_SESSION['succes_souscription']='Votre demande de paiement a été enregistrée. L’administration doit maintenant valider le paiement.';header('Location: abonnement.php');exit;
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('Souscription MIGON: '.$e->getMessage());$_SESSION['erreur_souscription']=$e->getMessage();header('Location: souscrire-abonnement.php');exit;}
