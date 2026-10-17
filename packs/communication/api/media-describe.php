<?php
// POST {path} : propose un titre, un texte alternatif et des mots-clés pour une image, par Gemini (même consigne que la
// médiathèque de la plateforme). Coût compté dans la consommation d'IA de la boutique ; refusé si son quota est épuisé.
// Rien n'est enregistré : le navigateur remplit le formulaire, la personne valide.
defined('PLUGIN_RUNTIME') || exit;
require_once __DIR__ . '/../lib/media.php';

$item = com_find_item((string) (plugin_input()['path'] ?? ''));
if (!$item || $item['type'] !== 'photo' || $item['missing']) plugin_json(['error' => 'Image introuvable dans la médiathèque.'], 404);
if (GEMINI_API_KEY === '') plugin_json(['error' => 'Clé Gemini absente : suggestions de l\'IA indisponibles.'], 422);
if (($gate = ai_gate()) !== null) plugin_json(['error' => $gate], 402);

$abs = com_root() . '/' . $item['path'];
$small = downscale_for_ai($abs) ?? $abs;
$text = gemini_describe_image($small, build_media_describe_prompt());
if ($small !== $abs) @unlink($small);
$suggestion = $text ? parse_media_describe_response($text) : null;
if (!$suggestion) plugin_json(['error' => 'Suggestion de l\'IA indisponible pour le moment, réessayez.'], 502);
plugin_json(['ok' => true, 'title' => $suggestion['label'], 'alt' => $suggestion['label'], 'tags' => com_tags(is_array($suggestion['tags']) ? implode(', ', $suggestion['tags']) : (string) $suggestion['tags'])]);
