-- Sélection persistante des produits à récupérer depuis l'application locale.
CREATE TABLE IF NOT EXISTS am_export_selection (
  product_ref TEXT PRIMARY KEY,
  selected_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS am_export_selection_selected_at
  ON am_export_selection (selected_at);
