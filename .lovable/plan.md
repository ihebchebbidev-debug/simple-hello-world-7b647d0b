# Customer remarks — full implementation plan

Consolidated from `Remarques_sur_la_2ème_version` and `Remarques_sur_la_3ème_version`. Items deduplicated; v3 supersedes v2 where they conflict.

## A. Terminology (global)
1. Replace **"Ravageurs" → "Bioagresseurs"** everywhere: sidebar, page titles, table headers, report columns, i18n FR/EN, mobile app.

## B. Sidebar / Navigation
2. Reorder admin sidebar to: **Tableau de bord · Configuration · Campagnes · Parcelles · Main d'œuvre · Eau · Engrais · Pesticides · Bioagresseurs · Rapports** (+ Utilisateurs / Notifications / Sync / Logs / Aide kept after).

## C. Dashboard
3. Rename **"Activité récente" → "Liste des opérations culturales"**.
4. Show **exact date** (dd/mm/yyyy) instead of "today / yesterday".
5. **Click a row** → open the technician's entry form pre-filled, with **Modifier** and **Supprimer** actions (reuse `OperationDetailsModal`).
6. Add **filters: date · parcelle · type d'opération** above the list.
7. Replace the four stat cards' icons with **shortcut tiles to the 5 reports** (Irrigation, Fertilisation, Phyto, Récolte, Coût) — clicking navigates to the report.

## D. Configuration pages
8. **Parcelles**: remove column **"Début campagne"**.
9. **Parcelles / Engrais / Pesticides / Campagnes**: the **"Supprimer"** button currently deactivates — must actually DELETE (hard delete via API). Keep a separate "Désactiver / Activer" toggle.
10. **Engrais & Pesticides** create/edit modals: add **price field** so the price can be entered when adding the item (calls existing price-history endpoint on save).

## E. Numeric formatting
11. **Prices & rates**: display & input with **3 decimals** (not 4) — Tunisia convention. Audit `format.ts`, all price inputs/tables.

## F. Reports — shared
12. Reorder filters left→right: **Campagne · Culture · Parcelle · Date** in `ReportToolbar`.
13. **Double-click** a plot row → open the per-plot operations history page (already exists at `/reports/history/:type/:plotId`) — wire it on every report.
14. Fix the **Campagne filter** so it actually scopes the data (it's currently broken on Irrigation, Fertilisation, Phyto).

## G. Irrigation report
15. Add the missing **Parcelle filter**.
16. Fix **Culture filter**.
17. Rename column header **"CUMUL (M³)" → "m³/parcelle"** and **"EAU" → "m³/ha"** (lowercase m³).

## H. Fertilisation report
18. Fix **quantité/ha** calculation (currently wrong).
19. Fix **cumul par parcelle** calculation.
20. Monthly pivot (parcelles × mois): **default to current campaign only** so it doesn't blow up across multiple years.
21. Fix all filters (campagne / culture / parcelle).

## I. Phytosanitaire report
22. Fix **pesticide/ha** calculation.
23. Fix all filters incl. campagne.
24. Rename "Ravageur ciblé" header → "Bioagresseur ciblé".

## J. Mobile app
25. **Phytosanitaire**: allow **3 decimals** for the product quantity input (`step="0.001"`, validation).
26. **Récolte**: remove the **"Jours travaillés"** field from the form.
27. Replace "Ravageurs" wording with "Bioagresseurs" in mobile i18n.

## K. Backend (if needed)
- Confirm DELETE endpoints exist for plots, fertilizers, pesticides, campaigns (and are not soft-deletes). Add hard-delete routes if missing.
- Verify `campaign_id` filter is accepted on `/reports/irrigation`, `/reports/fertilization`, `/reports/phytosanitary` and propagated to queries.

---

## Execution order
1. Terminology + sidebar reorder (A, B) — low risk, touches many files.
2. Configuration fixes (D) + price decimals (E).
3. Dashboard rework (C).
4. Reports shared + per-report fixes (F–I).
5. Mobile (J).
6. Backend touch-ups discovered along the way (K).

## Out of scope (will flag, not change)
- Visual redesign of report tables.
- Adding new report types.
- Mobile app native shell changes (only web bundle under `mobile_app/`).

After approval I'll execute top-to-bottom, committing logical batches and verifying after each.