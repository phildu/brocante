# Vidéos du centre d'aide

Cinq tutoriels ont un storyboard minuté et des sous-titres. **Aucun fichier MP4 n'est fourni** : les vidéos se tournent sur le serveur local avec des données de démonstration. Ne jamais livrer un fichier vidéo factice.

## Format attendu

- Durée 60 à 90 secondes, format 16:9, 1280 × 720, MP4 H.264.
- Pas de musique sous droits ; une musique libre ou aucun son.
- Sous-titres incrustés et fichier .vtt séparé.
- Flouter les noms, e-mails, téléphones et IBAN.
- Tourner sur le serveur local avec des données de démonstration.
- Chemin : `aide/assets/videos/<slug>.mp4`.
- Sous-titres : `aide/assets/videos/<slug>.vtt` (déjà fournis).
- Le lecteur du tutoriel affiche automatiquement la vidéo et les sous-titres dès que le MP4 existe.

## Fichiers

| Tutoriel | Slug | Durée | MP4 | VTT |
|---|---|---|---|---|
| Créer le plan d'implantation | `creer-son-plan` | 90 s | à tourner | `creer-son-plan.vtt` |
| Ouvrir les demandes d'emplacement | `ouvrir-les-demandes` | 75 s | à tourner | `ouvrir-les-demandes.vtt` |
| Publier une pièce avec le Studio | `publier-une-piece-studio` | 80 s | à tourner | `publier-une-piece-studio.vtt` |
| Encaisser les paiements | `encaisser` | 90 s | à tourner | `encaisser.vtt` |
| Exporter la liste et le registre | `exporter-un-plan` | 60 s | à tourner | `exporter-un-plan.vtt` |

## Storyboards

### Créer le plan d'implantation (`creer-son-plan.mp4`, 90 s)

| Temps | Plan | Action | Texte à l'écran |
|---|---|---|---|
| 00:00–00:04 | Titre plein écran | Fondu depuis le portail | Créer le plan d'implantation |
| 00:04–00:12 | Vue bureau du portail | Ouvrir Galerie puis Plan | Portail > Galerie > Plan |
| 00:12–00:24 | Barre d'adresse et carte | Saisir l'adresse, centrer, ou cliquer sur Ma position | Cadrez le lieu |
| 00:24–00:36 | Sélecteur de fond | Basculer Plan IGN / Satellite IGN | Plan ou satellite IGN |
| 00:36–00:48 | Catalogue et carte | Créer une surface, cliquer sur Poser, placer un stand | Posez les surfaces |
| 00:48–01:00 | Fiche d'un emplacement | Saisir un numéro puis utiliser Numéroter tout | Numérotez les emplacements |
| 01:00–01:12 | Fiche d'une zone | Ouvrir Tarifs de cette zone et saisir les prix | Réglez les tarifs |
| 01:12–01:22 | Barre d'actions | Cliquer sur Enregistrer le plan | Enregistrez le plan |
| 01:22–01:30 | Page publique | Cliquer sur Voir sur la page publique | Vérifiez la page publique |

### Ouvrir les demandes d'emplacement (`ouvrir-les-demandes.mp4`, 75 s)

| Temps | Plan | Action | Texte à l'écran |
|---|---|---|---|
| 00:00–00:04 | Titre plein écran | Ouverture | Ouvrir les demandes d'emplacement |
| 00:04–00:14 | Formulaire Événement | Remplir nom, date, lieu et horaires | Renseignez l'événement |
| 00:14–00:24 | Section places et tarifs | Saisir le nombre de places et les prix | Places et tarifs |
| 00:24–00:38 | Section Réserver en ligne | Cocher Accepter les demandes d'emplacement en ligne | Activez les demandes |
| 00:38–00:48 | Champ du délai | Fixer le nombre de jours de réservation | Une demande retient la place |
| 00:48–00:58 | Bouton de publication | Publier puis copier le lien public | Publiez et partagez |
| 00:58–01:08 | Page Exposants | Ouvrir Demandes d'emplacement et cliquer sur Accepter | Validez les demandes |
| 01:08–01:15 | Espace exposant | Afficher le pass et le QR code | Le pass confirme la place |

### Publier une pièce avec le Studio (`publier-une-piece-studio.mp4`, 80 s)

| Temps | Plan | Action | Texte à l'écran |
|---|---|---|---|
| 00:00–00:04 | Titre plein écran | Ouverture | Publier une pièce avec le Studio |
| 00:04–00:14 | Téléphone, navigateur | Ouvrir /studio.php et se connecter | Ouvrez le Studio sur le téléphone |
| 00:14–00:28 | Appareil photo | Prendre la photo de face, puis un gros plan | Au moins la photo de face |
| 00:28–00:42 | Fiche proposée | Laisser l'IA préparer le titre et la description | L'IA prépare la fiche |
| 00:42–00:55 | Champs de la fiche | Corriger le prix, l'état et les dimensions | Relisez et corrigez |
| 00:55–01:08 | Validation | Publier la pièce | Publiez la pièce |
| 01:08–01:20 | Catalogue | Retrouver la pièce dans Catalogue, ou utiliser le lot | Retrouvez-la dans le catalogue |

### Encaisser les paiements (`encaisser.mp4`, 90 s)

| Temps | Plan | Action | Texte à l'écran |
|---|---|---|---|
| 00:00–00:04 | Titre plein écran | Ouverture | Encaisser les paiements |
| 00:04–00:15 | Réglages de la boutique | Ouvrir Paiement & livraison | Réglages > Paiement & livraison |
| 00:15–00:30 | Section Stripe | Choisir Connect ou coller ses clés | Stripe Connect ou vos clés |
| 00:30–00:45 | Webhook et mode test | Vérifier l'URL du webhook et le secret | Déclarez le webhook |
| 00:45–01:00 | Commande de test | Payer avec une carte de test et vérifier la commande | Testez avant le réel |
| 01:00–01:12 | Bascule de mode | Passer en mode réel | Passez en mode réel |
| 01:12–01:22 | Commandes | Suivre les paiements et les versements | Suivez les commandes |
| 01:22–01:30 | Autres prestataires | Montrer Monetico, PayPal, Revolut et SEPA | Ou un autre prestataire |

### Exporter la liste et le registre (`exporter-un-plan.mp4`, 60 s)

| Temps | Plan | Action | Texte à l'écran |
|---|---|---|---|
| 00:00–00:04 | Titre plein écran | Ouverture | Exporter la liste et le registre |
| 00:04–00:15 | Page Exposants | Cliquer sur Exporter (CSV) | Exporter la liste en CSV |
| 00:15–00:28 | Page Registre | Montrer Télécharger Excel et CSV | Registre en Excel ou CSV |
| 00:28–00:40 | Aperçu du registre | Cliquer sur Imprimer / PDF | Imprimer le registre |
| 00:40–00:50 | Boîte d'impression | Choisir Enregistrer au format PDF, A4 paysage | Enregistrer en PDF |
| 00:50–01:00 | Page publique | Rappeler qu'il n'y a pas d'export direct du plan | Le plan s'imprime depuis la page publique |

## Tournage

- Utiliser le serveur local et un compte de test, jamais la production avec de vraies données.
- Suivre le storyboard et les sous-titres ; la voix off est facultative.
- Masquer les noms, e-mails, téléphones, IBAN et secrets.
- Déposer le MP4 au nom exact du slug, puis vérifier le lecteur et les sous-titres dans le tutoriel.

## Statut

- Storyboards : 5.
- Sous-titres VTT : 5 fichiers fournis.
- Vidéos MP4 : 0, à tourner.
