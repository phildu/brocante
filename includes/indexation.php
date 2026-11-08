<?php

// Interrupteur d'indexation par les moteurs de recherche (Google, Bing…) — UNIQUEMENT pour la production.
//
//   false  → la production est fermée aux robots : en-tête « X-Robots-Tag: noindex, nofollow » sur chaque page PHP
//            et /robots.txt = « Disallow: / » (voir includes/app-env.php et robots.php). C'est l'état voulu tant que
//            l'utilisateur n'a pas donné son « go » à l'ouverture.
//   true   → la production devient indexable (les zones privées restent interdites dans robots.txt).
//
// Ne passer à true QUE sur demande explicite de l'utilisateur (« go indexation »), puis déployer. La local et la préprod
// restent fermées quoi qu'il arrive : ce fichier ne les concerne pas. Voir DEPLOIEMENT.md.
return false;
