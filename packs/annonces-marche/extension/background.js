/* Service worker minimal : relais éventuel, pas de publication automatique. */
chrome.runtime.onInstalled.addListener(() => {
  // Rien à initialiser : la config vit dans chrome.storage.sync (popup).
});
