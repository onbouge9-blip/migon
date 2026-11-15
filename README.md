# MIGON

Plateforme de mise en relation autour du logement à **Cotonou–Godomey** :
les propriétaires publient des logements, les chercheurs les trouvent grâce
à la recherche, aux filtres et à la géolocalisation (« autour de moi »).
Le paiement du loyer ne se fait pas sur la plateforme.

Technologies : PHP 8.1+ (testé avec 8.3), MySQL 8 (ou MariaDB récent), WAMP.

## Installation locale sous WAMP

1. Copier le dossier du projet dans `C:\wamp64\www\migon`.
2. Créer les deux dossiers **hors** du dossier web (ils ne doivent jamais être
   accessibles directement depuis le navigateur) :
   - `C:\wamp64\migon_photos` : photos des logements (noms aléatoires) ;
   - `C:\wamp64\migon_prive`  : pièces d'identité (accès réservé à l'administration).
3. Dans phpMyAdmin, créer la base `migon` (interclassement `utf8mb4_unicode_ci`)
   puis importer le fichier SQL de la base.
4. Vérifier les accès MySQL dans `config/database.php`
   (par défaut : `localhost`, base `migon`, utilisateur `root`, sans mot de passe).
5. Ouvrir `http://localhost/migon/`.

> La géolocalisation du navigateur ne fonctionne que sur **HTTPS** ou sur
> `localhost`. Pour tester depuis un téléphone sur le réseau local, passer par
> HTTPS (ou un tunnel HTTPS) ; sinon, placer le repère sur la carte à la main.

## Arborescence

```
admin/        identités, paiements d'abonnement et de réactivation
chercheur/    espace chercheur : accueil et recherche de logements
classes/      Database, Auth, Utilisateur, Notifications, Geolocalisation
config/       paramètres de connexion à la base
proprietaire/ espace propriétaire : logements, photos, quotas, abonnement
public/       css/ (style.css) et js/ (position-logement.js)
```

## Rôles

| Rôle | Fonction |
|------|----------|
| Propriétaire | Publier et gérer ses logements, tarifs, photos, demandes |
| Chercheur | Rechercher, filtrer, consulter, contacter les propriétaires |
| Administrateur | Vérifier les identités, valider les paiements |

## Géolocalisation

- La table `logement` possède les colonnes `latitude` et `longitude`
  (`DECIMAL(10,7)`) et l'index `idx_logement_geo` : **aucune modification de la
  base n'est nécessaire** (ne pas relancer un `ALTER TABLE ... ADD COLUMN`).
  Pour contrôler la structure : `DESCRIBE logement;`
- **Propriétaire** : à la création (obligatoire) et à la modification d'un
  logement, bouton « Utiliser ma position actuelle » et/ou clic sur la carte,
  repère déplaçable. Les coordonnées sont validées côté serveur
  (`classes/Geolocalisation.php`).
- **Publication** : un logement sans position ne peut pas être publié.
- **Chercheur** : sur `chercheur/logements.php`, bouton « Autour de moi »
  avec rayon de 1, 2, 5, 10 ou 20 km. Les résultats sont classés du plus proche
  au plus éloigné avec la distance affichée. Les filtres ville, quartier,
  durée et budget restent utilisables en même temps. Bascule Liste / Carte.
- Les cartes utilisent Leaflet et OpenStreetMap (connexion Internet requise
  pour afficher les cartes ; le reste du site fonctionne hors ligne).
- Seuls les logements **publiés, disponibles et non supprimés** apparaissent
  dans la recherche et sur la page d'accueil.

## Quotas et abonnement

- 3 publications gratuites au total par propriétaire.
- Ensuite, abonnement de 30 jours = 30 nouvelles publications.
- Une publication reste comptée même si le logement est supprimé ; modifier un
  logement existant ne consomme rien.
- Réactivation d'un logement indisponible : paiement de 500 FCFA validé par
  l'administration.

## Liste de contrôle avant démonstration

- [ ] Trois comptes distincts : propriétaire, chercheur, administrateur.
- [ ] Création d'au moins 3 logements à des endroits différents (Cotonou, Godomey…).
- [ ] Publication : 3 gratuites, puis refus sans abonnement, puis abonnement validé.
- [ ] Recherche « autour de moi » avec chaque rayon, puis avec filtres combinés.
- [ ] Logement rendu indisponible : disparaît de la recherche ; réactivation
      payée (500 FCFA) puis confirmée : il réapparaît.
- [ ] Paiements confirmés, refusés et en attente ; notifications associées.
- [ ] Identité vérifiée / refusée : notification reçue.
- [ ] Messagerie entre chercheur et propriétaire ; notifications lues / non lues.
- [ ] Sécurité : accès aux pages d'un autre rôle refusé, jetons CSRF,
      documents privés inaccessibles sans être administrateur.
