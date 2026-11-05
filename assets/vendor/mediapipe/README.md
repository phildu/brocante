# MediaPipe Tasks Vision — détection de personnes (comptage par caméra)

Fichiers hébergés ici (et non chargés depuis un CDN) pour que le compteur par
caméra fonctionne sans réseau et sans aucun service tiers : les images de la
caméra restent sur le téléphone.

| Fichier | Origine |
|---|---|
| `vision_bundle.js` | paquet npm `@mediapipe/tasks-vision` 0.10.21 (`vision_bundle.mjs`, renommé en `.js` pour le type MIME), licence Apache-2.0 |
| `wasm/vision_wasm_internal.{js,wasm}` | même paquet (`wasm/`), variante avec SIMD uniquement ; licence Apache-2.0 |
| `efficientdet_lite0.tflite` | modèle MediaPipe « EfficientDet-Lite0 » (float16), COCO, licence Apache-2.0 — `https://storage.googleapis.com/mediapipe-models/object_detector/efficientdet_lite0/float16/1/efficientdet_lite0.tflite` |

Les lignes « sourceMappingURL » ont été retirées des fichiers JavaScript
(sinon le navigateur réclame des fichiers .map absents). Rien d'autre n'est modifié.

Empreintes SHA-256 (début) :

| Fichier | SHA-256 |
|---|---|
| `efficientdet_lite0.tflite` | `4b59100025bea123…` |
| `vision_bundle.js` | `c8341c13009c2e75…` |
| `wasm/vision_wasm_internal.js` | `4a97e2520ba506c6…` |
| `wasm/vision_wasm_internal.wasm` | `f00ec4731faa23b3…` |

Mise à jour : télécharger la nouvelle version du paquet npm, remplacer les
fichiers, relancer `node scripts/test-comptage-vision.js` et refaire un essai
sur téléphone.
