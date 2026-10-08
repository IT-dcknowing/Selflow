@extends('admin::gabarits.application')
@section('titre', 'Gestion FNE')
@section('topbar_titre', 'Gestion FNE — Documents normalisés DGI')

@section('styles')
<style>
    .fne-filtres { display:flex; gap:12px; align-items:end; flex-wrap:wrap; background:#fff; border:1px solid var(--border); border-radius:14px; padding:16px 20px; margin-bottom:22px; }
    .fne-filtres .form-group { margin-bottom:0; }
    .fne-filtres label { font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); display:block; margin-bottom:4px; }

    .kpi-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:26px; }
    .kpi-card { background:#fff; border:1px solid var(--border); border-radius:14px; padding:18px 20px; }
    .kpi-card .lbl { font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); letter-spacing:.4px; margin-bottom:8px; }
    .kpi-card .val { font-size:24px; font-weight:800; color:var(--text-1); }
    .kpi-card .sub { font-size:12px; color:var(--text-3); margin-top:4px; }

    /* Carte de comparaison : nos montants, ceux de la DGI, puis l'ecart.
       L'ecart seul ne se juge pas — il faut voir sur quoi il porte. */
    .kpi-card .comparaison { display:flex; flex-direction:column; gap:2px; }
    .kpi-card .comparaison > div { display:flex; justify-content:space-between; align-items:baseline; gap:12px; }
    .kpi-card .comparaison span { font-size:12px; color:var(--text-3); }
    .kpi-card .comparaison strong { font-size:15px; font-weight:700; color:var(--text-1); font-variant-numeric:tabular-nums; }
    .kpi-card .comparaison .ecart { border-top:1px solid var(--border); margin-top:5px; padding-top:5px; }
    .kpi-card .comparaison .ecart span { font-weight:700; color:var(--text-2); }
    .kpi-card .comparaison .ecart strong { font-size:19px; font-weight:800; }

    .section-titre { font-size:14px; font-weight:700; color:var(--text-1); text-transform:uppercase; letter-spacing:.4px; margin:26px 0 14px; display:flex; align-items:center; gap:8px; }

    .doc-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--border); border-radius:14px; overflow:hidden; }
    .doc-table th { text-align:left; padding:10px 14px; font-size:11px; text-transform:uppercase; color:var(--text-3); background:#f8fafc; border-bottom:1px solid var(--border); }
    .doc-table td { padding:12px 14px; border-bottom:1px solid var(--border); font-size:13px; }
    .doc-table tr:last-child td { border-bottom:none; }

    /* Modal config fiscale */
    .tax-modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; }
    .tax-modal-overlay.open { display:flex; }
    .tax-modal-box { background:#fff; border-radius:16px; padding:28px; max-width:520px; width:100%; box-shadow:0 25px 60px rgba(0,0,0,.18); animation:fadeSlide .2s ease; }
    @keyframes fadeSlide { from { opacity:0; transform:translateY(-18px); } to { opacity:1; transform:translateY(0); } }

    .tax-toggle-row { display:flex; align-items:flex-start; gap:14px; padding:14px; border-radius:10px; border:1px solid var(--border); cursor:pointer; transition:background .12s; }
    .tax-toggle-row:hover { background:var(--bg2); }
    .tax-toggle-row.disabled-row { opacity:.45; pointer-events:none; }
    .tax-toggle-row input[type=checkbox] { width:18px; height:18px; cursor:pointer; margin-top:2px; flex-shrink:0; }
    .tax-toggle-row .lbl-main { font-weight:700; font-size:13px; color:var(--text); }
    .tax-toggle-row .lbl-sub { font-size:12px; color:var(--text-3); margin-top:2px; }

    /* Badge config status */
    .config-badge { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700; }
    .config-badge.configured { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
    .config-badge.not-configured { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }

    /* Résumé calculs temps réel */
    .tax-resume { background:var(--bg3); border-radius:10px; padding:14px 16px; margin-top:16px; font-size:13px; border:1px solid var(--border); }
    .tax-resume .r-row { display:flex; justify-content:space-between; align-items:center; padding:4px 0; border-bottom:1px solid var(--border); }
    .tax-resume .r-row:last-child { border-bottom:none; padding-top:8px; font-weight:700; font-size:14px; }
</style>
@endsection

@section('contenu')

{{-- ── Barre filtres + bouton Configuration Fiscale ── --}}
@include('admin::fne.partials.filtre-periode', [
    'onChange' => 'rafraichirFneGestion()',
    'apres'    => 'admin::fne.partials.badge-config-fiscale',
])

{{-- ── Cartes Stickers & Timbres (récupérées en temps réel depuis la base / API) ── --}}
<div class="section-titre"><i class="fas fa-stamp"></i> Stickers &amp; Timbres (plateforme DGI)</div>
<div class="kpi-grid">
    {{-- Le mode de facturation (stickers ou provision) se coche dans les
         paramètres de la DGI et l'API n'expose aucun champ pour le lire. Il se
         constate à chaque certification, selon le solde renvoyé. --}}
    <div class="kpi-card"><div class="lbl" id="k-solde-lbl">Stickers disponibles</div><div class="val" id="k-stickers-solde">—</div><div class="sub" id="k-stickers-solde-sub">En attente d'une première normalisation</div></div>
    <div class="kpi-card"><div class="lbl">Stickers achetés</div><div class="val" id="k-stickers-achats">—</div><div class="sub" id="k-stickers-achats-sub">Total acheté Mobile Money</div></div>
    <div class="kpi-card"><div class="lbl">Stickers consommés</div><div class="val" id="k-stickers-consommes">0</div><div class="sub" id="k-stickers-consommes-sub">Total utilisé pour normalisation</div></div>
    <div class="kpi-card"><div class="lbl">Timbre de quittance</div><div class="val" id="k-timbre">0 F</div><div class="sub" id="k-timbre-sub">Droits de timbre fiscaux collectés</div></div>
</div>

{{-- ── Ventes normalisées ── --}}
<div class="section-titre"><i class="fas fa-arrow-down" style="color:#10b981;"></i> Entrées — Ventes normalisées</div>
<div class="kpi-grid">
    <div class="kpi-card"><div class="lbl">Factures</div><div class="val" id="k-v-factures-n">0</div><div class="sub" id="k-v-factures-m">0 F</div></div>
    <div class="kpi-card"><div class="lbl">Reçus</div><div class="val" id="k-v-recus-n">0</div><div class="sub" id="k-v-recus-m">0 F</div></div>
    <div class="kpi-card"><div class="lbl">Avoirs clients</div><div class="val" id="k-v-avoirs-n">0</div><div class="sub" id="k-v-avoirs-m">0 F</div></div>
    <div class="kpi-card"><div class="lbl">Proforma</div><div class="val" id="k-v-proforma-n">0</div><div class="sub" id="k-v-proforma-m">0 F</div></div>
</div>
<div class="kpi-grid" style="grid-template-columns:repeat(3,1fr);">
    <div class="kpi-card">
        <div class="lbl">Alertes stock de stickers</div>
        <div class="val" id="k-alertes-stickers">0</div>
        <div class="sub">Pièces certifiées avec une alerte renvoyée par la DGI</div>
    </div>
    {{-- Les deux termes de la comparaison precedent l'ecart : un ecart de
         2 637 F ne dit rien tant qu'on ignore s'il porte sur 10 000 F ou sur
         deux millions. --}}
    <div class="kpi-card">
        <div class="lbl">TVA — Selflow vs DGI</div>
        <div class="comparaison">
            <div><span>Selflow</span><strong id="k-tva-selflow">0 F</strong></div>
            <div><span>DGI</span><strong id="k-tva-dgi">0 F</strong></div>
            <div class="ecart"><span>Écart</span><strong id="k-ecart-tva">0 F</strong></div>
        </div>
        <div class="sub" id="k-ecart-tva-sub">Comparaison de nos montants à ceux retenus par la plateforme</div>
    </div>
    <div class="kpi-card">
        <div class="lbl">TTC — Selflow vs DGI</div>
        <div class="comparaison">
            <div><span>Selflow</span><strong id="k-ttc-selflow">0 F</strong></div>
            <div><span>DGI</span><strong id="k-ttc-dgi">0 F</strong></div>
            {{-- Le droit de timbre est dû par le débiteur en sus du prix
                 (art. 875 du CGI) : il gonfle le total de la DGI sans figurer
                 dans le TTC de Selflow. L'afficher évite de prendre un écart
                 légitime pour une erreur de calcul. --}}
            <div><span>dont timbre DGI</span><strong id="k-ttc-timbre">0 F</strong></div>
            <div class="ecart"><span>Écart</span><strong id="k-ecart-ttc">0 F</strong></div>
        </div>
        <div class="sub" id="k-ecart-ttc-sub">—</div>
    </div>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(4,1fr);">
    <div class="kpi-card"><div class="lbl">Total HT</div><div class="val" id="k-v-ht">0 F</div></div>
    <div class="kpi-card"><div class="lbl">TVA collectée</div><div class="val" id="k-v-tva">0 F</div></div>
    <div class="kpi-card"><div class="lbl">Total TTC</div><div class="val" id="k-v-ttc">0 F</div></div>
    <div class="kpi-card"><div class="lbl">Remises accordées</div><div class="val" id="k-v-remises">0 F</div></div>
</div>

{{-- ── Achats normalisés + CA HT/TTC ── --}}
<div class="section-titre"><i class="fas fa-arrow-up" style="color:#ef4444;"></i> Sorties — Achats normalisés{{ auth()->user()->entreprise?->bapa ? ' (BAPA inclus)' : '' }}</div>
<div class="kpi-grid">
    <div class="kpi-card"><div class="lbl">Factures reçues</div><div class="val" id="k-a-factures-n">0</div><div class="sub" id="k-a-factures-m">0 F</div></div>
    <div class="kpi-card"><div class="lbl">Avoirs fournisseurs</div><div class="val" id="k-a-avoirs-n">0</div><div class="sub" id="k-a-avoirs-m">0 F</div></div>
    <div class="kpi-card"><div class="lbl">TVA déductible</div><div class="val" id="k-a-tva">0 F</div></div>
    <div class="kpi-card"><div class="lbl">Total TTC</div><div class="val" id="k-a-ttc">0 F</div></div>
</div>
{{-- Cartes CA HT & CA TTC (P4) --}}
<div class="kpi-grid" style="grid-template-columns:1fr 1fr; margin-top:-10px;">
    <div class="kpi-card" style="border-left:4px solid #6366f1;">
        <div class="lbl"><i class="fas fa-chart-line" style="color:#6366f1;"></i> CA HT Achats normalisés</div>
        <div class="val" id="k-a-ca-ht" style="color:#6366f1;">0 F</div>
        <div class="sub">Chiffre d'affaires hors-taxes — achats déclarés DGI</div>
    </div>
    <div class="kpi-card" style="border-left:4px solid #8b5cf6;">
        <div class="lbl"><i class="fas fa-chart-bar" style="color:#8b5cf6;"></i> CA TTC Achats normalisés</div>
        <div class="val" id="k-a-ca-ttc" style="color:#8b5cf6;">0 F</div>
        <div class="sub">Chiffre d'affaires toutes-taxes comprises — achats DGI</div>
    </div>
</div>

{{-- ─────────────── Modal Configuration Fiscale ─────────────── --}}
<div class="tax-modal-overlay" id="modalConfigFiscale">
    <div class="tax-modal-box">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
            <h3 style="margin:0;font-size:17px;font-weight:800;color:var(--text-1);">
                <i class="fas fa-cog" style="color:var(--primary);margin-right:8px;"></i>Configuration Fiscale
            </h3>
            <button onclick="fermerModalConfig()" style="background:none;border:none;cursor:pointer;font-size:20px;color:var(--text-3);">✕</button>
        </div>

        {{-- Régime + Catégorie (lecture seule depuis $entreprise) --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
            <div style="padding:12px 14px;background:var(--bg2);border-radius:10px;border:1px solid var(--border);">
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-3);letter-spacing:.4px;">Régime d'imposition</div>
                <div style="font-weight:700;font-size:14px;color:var(--text);margin-top:4px;" id="cfg-regime">
                    {{ $entreprise->regime_imposition ?? 'Non renseigné' }}
                </div>
            </div>
            <div style="padding:12px 14px;border-radius:10px;border:1px solid var(--border);" id="cfg-categorie-block">
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-3);letter-spacing:.4px;">Catégorie fiscale</div>
                <div style="font-weight:700;font-size:13px;margin-top:4px;" id="cfg-categorie-label">—</div>
            </div>
        </div>

        {{-- Spinner chargement --}}
        <div id="cfg-loading" style="text-align:center;padding:30px;color:var(--text-3);">
            <i class="fas fa-spinner fa-spin" style="font-size:24px;"></i><br>Chargement de la configuration…
        </div>

        <div id="cfg-form-wrapper" style="display:none;">
            {{-- TVA --}}
            <label class="tax-toggle-row" id="row-tva">
                <input type="checkbox" id="cfg-tva-active">
                <div>
                    <div class="lbl-main"><i class="fas fa-percent" style="color:#6366f1;margin-right:6px;"></i>TVA — Taxe sur la Valeur Ajoutée</div>
                    <div class="lbl-sub">Taux : 18% (CAS A uniquement — RNI / RSI). Masquée en CAS B.</div>
                </div>
            </label>

            {{-- TSE --}}
            <label class="tax-toggle-row" id="row-tse" style="margin-top:10px;">
                <input type="checkbox" id="cfg-tse-active">
                <div>
                    <div class="lbl-main"><i class="fas fa-building" style="color:#f59e0b;margin-right:6px;"></i>TSE — Taxe Spéciale sur l'Entreprise</div>
                    <div class="lbl-sub">Taux : 0,1% du CA HT (CAS A uniquement). Désactivée automatiquement en CAS B (non-assujettis à la TVA).</div>
                </div>
            </label>

            {{-- TDT --}}
            <label class="tax-toggle-row" id="row-tdt" style="margin-top:10px;">
                <input type="checkbox" id="cfg-tdt-active" onchange="mettreAJourResume()">
                <div>
                    <div class="lbl-main">
                        <i class="fas fa-money-bill" style="color:#10b981;margin-right:6px;"></i>TDT — Taxe sur les Droits de Timbre
                        <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#1d4ed8;background:#dbeafe;border-radius:4px;padding:1px 6px;margin-left:6px;">Informatif</span>
                    </div>
                    {{-- Ce reglage ne declenche ni ne calcule quoi que ce soit : le
                         timbre est applique par la DGI seule, a la normalisation, et
                         son montant nous parvient dans `invoice.fiscalStamp`. --}}
                    <div class="lbl-sub">Rappel de l'option cochée sur votre espace FNE. Le timbre est décidé et chiffré par la DGI à la normalisation : ce réglage ne modifie aucun montant.</div>
                </div>
            </label>

            {{-- Résumé calculs temps réel --}}
            <div class="tax-resume" id="cfg-resume">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-3);letter-spacing:.4px;margin-bottom:10px;">
                    <i class="fas fa-calculator"></i> Résumé des taxes applicables
                </div>
                <div class="r-row"><span>TVA (18%)</span><span id="r-tva">Non activée</span></div>
                <div class="r-row"><span>TSE (0,1% du CA HT)</span><span id="r-tse">Non activée</span></div>
                {{-- Le resume annoncait encore « 1,5 % especes > 5 000 F »,
                     taux qui ne figure dans aucun texte : l'article 873 du CGI
                     fixe un bareme forfaitaire par tranche, et c'est la DGI qui
                     applique le timbre a la normalisation. --}}
                <div class="r-row"><span>TDT — timbre de quittance</span><span id="r-tdt">Non déclarée</span></div>
            </div>

            {{-- Feedback --}}
            <div id="cfg-feedback" style="display:none;margin-top:14px;"></div>

            {{-- Boutons --}}
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                <button type="button" class="btn btn-outline" onclick="fermerModalConfig()">Annuler</button>
                <button type="button" class="btn btn-primary" id="btn-sauvegarder-config" onclick="sauvegarderConfigFiscale()">
                    <i class="fas fa-save"></i> Enregistrer la configuration
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function formatF(n) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n || 0)) + ' F';
}

function rafraichirFneGestion() {
    const params = parametresFiltreFne();

    fetch("{{ route('admin.fne.gestion.donnees') }}?" + params.toString())
        .then(r => r.json())
        .then(d => appliquerKpis(d))
        .catch(() => {});
}

function appliquerKpis(d) {
    // Solde DGI. Le mode de facturation est CONSTATE, pas configure : la
    // reponse de certification porte `balance_sticker` en mode stickers et
    // `balance_funds` en mode provision. Tant qu'aucune piece n'a ete
    // normalisee, on l'ignore et on le dit — un « 0 » ressemblerait a une
    // panne ou a un solde epuise.
    //
    // Le solde est exprime dans les deux unites : la quantite en valeur
    // principale, l'equivalent en francs juste dessous. La plateforme ne
    // renvoie qu'une des deux — jamais le prix unitaire — et la conversion
    // s'appuie sur le tarif inscrit en configuration. L'affichage le dit :
    // c'est une equivalence, pas un releve.
    const majDate = d.solde_maj_at ? ` · relevé le ${d.solde_maj_at}` : '';
    const prix = d.sticker_prix ?? 20;
    const tarif = `à ${formatF(prix)} l'unité`;

    if (d.mode_facturation === 'stickers' || d.mode_facturation === 'provision') {
        const releve = d.mode_facturation === 'stickers'
            ? `${d.stickers_solde} vignette(s) relevée(s)`
            : `provision de ${formatF(d.solde_provision)} relevée`;

        document.getElementById('k-solde-lbl').textContent = 'Stickers disponibles';
        document.getElementById('k-stickers-solde').textContent =
            `${d.stickers_solde} · ${formatF(d.solde_valeur)}`;
        document.getElementById('k-stickers-solde-sub').textContent =
            `${releve}, ${tarif}${majDate}`;

        document.getElementById('k-stickers-achats').textContent =
            `${d.stickers_achats} · ${formatF(d.stickers_achats_valeur)}`;
        document.getElementById('k-stickers-achats-sub').textContent =
            `Solde + consommés, ${tarif}`;
    } else {
        document.getElementById('k-solde-lbl').textContent = 'Stickers disponibles';
        document.getElementById('k-stickers-solde').textContent = '—';
        document.getElementById('k-stickers-solde-sub').textContent =
            'Solde inconnu : la DGI ne le communique qu\'en réponse à une normalisation';

        document.getElementById('k-stickers-achats').textContent = '—';
        document.getElementById('k-stickers-achats-sub').textContent =
            'Connu une fois le premier solde relevé';
    }

    // Stickers consommés : une pièce certifiée = une vignette, quel que soit
    // le mode. Le décompte reste valable.
    document.getElementById('k-stickers-consommes').textContent =
        `${d.stickers_consommes} · ${formatF(d.stickers_consommes_valeur)}`;
    document.getElementById('k-stickers-consommes-sub').textContent =
        `${d.stickers_consommes} pièce(s) certifiée(s), ${tarif}`;

    // Timbre de quittance
    // Timbre de quittance : uniquement ce que la DGI a reellement retenu.
    // L'estimation locale a ete retiree — la plateforme applique un montant
    // forfaitaire que le calcul « TTC x taux » ne reproduisait pas, et le
    // declenchement depend d'un reglage que l'API n'expose pas.
    document.getElementById('k-timbre').textContent = formatF(d.timbre_quittance);
    document.getElementById('k-timbre-sub').textContent =
        `${d.timbre_quantite} timbre(s) appliqué(s) par la DGI`
        + (d.timbre_inconnu > 0 ? ` · ${d.timbre_inconnu} pièce(s) sans retour de timbre` : '');

    document.getElementById('k-v-factures-n').textContent = d.ventes.factures.nombre;
    document.getElementById('k-v-factures-m').textContent = formatF(d.ventes.factures.montant);
    document.getElementById('k-v-recus-n').textContent = d.ventes.recus.nombre;
    document.getElementById('k-v-recus-m').textContent = formatF(d.ventes.recus.montant);
    document.getElementById('k-v-avoirs-n').textContent = d.ventes.avoirs.nombre;
    document.getElementById('k-v-avoirs-m').textContent = formatF(d.ventes.avoirs.montant);
    document.getElementById('k-v-proforma-n').textContent = d.ventes.proforma.nombre;
    document.getElementById('k-v-proforma-m').textContent = formatF(d.ventes.proforma.montant);

    // Indicateurs issus des reponses de certification
    document.getElementById('k-alertes-stickers').textContent = d.alertes_stickers ?? 0;

    const controlees = d.pieces_controlees ?? 0;
    const ecartTva = d.ecart_tva_dgi ?? 0;
    const ecartTtc = d.ecart_ttc_dgi ?? 0;

    // Chaque carte porte les deux montants compares, puis leur ecart : un
    // ecart isole ne se juge pas sans savoir sur quelle assiette il porte.
    document.getElementById('k-tva-selflow').textContent = formatF(d.tva_selflow ?? 0);
    document.getElementById('k-tva-dgi').textContent     = formatF(d.tva_dgi ?? 0);
    document.getElementById('k-ecart-tva').textContent   = formatF(ecartTva);
    document.getElementById('k-ecart-tva').style.color   = Math.abs(ecartTva) > 1 ? '#dc2626' : '#059669';
    document.getElementById('k-ecart-tva-sub').textContent = controlees > 0
        ? `${controlees} pièce(s) comparée(s) aux montants de la DGI`
        : 'Aucune pièce certifiée ne remonte encore ses montants';

    // Le timbre est du en sus du prix : il explique une part de l'ecart, et le
    // residuel est le seul qui interroge vraiment le calcul.
    const timbre    = d.timbre_comparables ?? 0;
    const residuel  = ecartTtc - timbre;

    document.getElementById('k-ttc-selflow').textContent = formatF(d.ttc_selflow ?? 0);
    document.getElementById('k-ttc-dgi').textContent     = formatF(d.ttc_dgi ?? 0);
    document.getElementById('k-ttc-timbre').textContent  = formatF(timbre);
    document.getElementById('k-ecart-ttc').textContent   = formatF(ecartTtc);
    document.getElementById('k-ecart-ttc').style.color   = Math.abs(residuel) > 1 ? '#dc2626' : '#059669';
    document.getElementById('k-ecart-ttc-sub').textContent = Math.abs(residuel) > 1
        ? `Dont ${formatF(timbre)} de timbre dû en sus du prix ; reste ${formatF(residuel)} à expliquer`
        : 'L\'écart correspond au timbre de quittance retenu par la DGI';
    document.getElementById('k-v-ht').textContent = formatF(d.ventes.total_ht);
    document.getElementById('k-v-tva').textContent = formatF(d.ventes.total_tva);
    document.getElementById('k-v-ttc').textContent = formatF(d.ventes.total_ttc);
    document.getElementById('k-v-remises').textContent = formatF(d.ventes.total_remises);

    document.getElementById('k-a-factures-n').textContent = d.achats.factures.nombre;
    document.getElementById('k-a-factures-m').textContent = formatF(d.achats.factures.montant);
    document.getElementById('k-a-avoirs-n').textContent = d.achats.avoirs.nombre;
    document.getElementById('k-a-avoirs-m').textContent = formatF(d.achats.avoirs.montant);
    document.getElementById('k-a-tva').textContent = formatF(d.achats.total_tva_deductible);
    document.getElementById('k-a-ttc').textContent = formatF(d.achats.total_ttc);
    // CA HT & CA TTC
    if (document.getElementById('k-a-ca-ht')) document.getElementById('k-a-ca-ht').textContent = formatF(d.achats.ca_ht ?? d.achats.total_ht);
    if (document.getElementById('k-a-ca-ttc')) document.getElementById('k-a-ca-ttc').textContent = formatF(d.achats.ca_ttc ?? d.achats.total_ttc);
}

document.addEventListener('DOMContentLoaded', () => {
    const initial = @json($kpis);
    appliquerKpis(initial);
});

// ── Modal Configuration Fiscale ──────────────────────────────────────
let cfgCategorieActuelle = 'CAS_B';

function ouvrirModalConfig() {
    document.getElementById('modalConfigFiscale').classList.add('open');
    document.getElementById('cfg-loading').style.display  = 'block';
    document.getElementById('cfg-form-wrapper').style.display = 'none';
    document.getElementById('cfg-feedback').style.display = 'none';

    fetch("{{ route('admin.fne.config') }}", {
        headers: { 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        cfgCategorieActuelle = data.categorie;

        // Afficher la catégorie
        const catBlock = document.getElementById('cfg-categorie-block');
        const catLabel = document.getElementById('cfg-categorie-label');
        catLabel.textContent = data.label_categorie;
        if (data.categorie === 'CAS_A') {
            catBlock.style.background = '#eff6ff';
            catBlock.style.borderColor = '#bfdbfe';
            catLabel.style.color = '#1d4ed8';
        } else {
            catBlock.style.background = '#fefce8';
            catBlock.style.borderColor = '#fde68a';
            catLabel.style.color = '#92400e';
        }

        // Checkboxes
        document.getElementById('cfg-tva-active').checked = data.tva_active;
        document.getElementById('cfg-tse-active').checked = data.tse_active;
        document.getElementById('cfg-tdt-active').checked = data.tdt_active;

        // Désactiver TVA et TSE en CAS B
        const isCasB = data.categorie === 'CAS_B';
        document.getElementById('row-tva').classList.toggle('disabled-row', isCasB);
        document.getElementById('row-tse').classList.toggle('disabled-row', isCasB);
        if (isCasB) {
            document.getElementById('cfg-tva-active').checked = false;
            document.getElementById('cfg-tse-active').checked = false;
        }

        mettreAJourResume();
        document.getElementById('cfg-loading').style.display = 'none';
        document.getElementById('cfg-form-wrapper').style.display = 'block';
    })
    .catch(() => {
        document.getElementById('cfg-loading').innerHTML = '<span style="color:#e53e3e"><i class="fas fa-times-circle"></i> Erreur de chargement.</span>';
    });
}

function fermerModalConfig() {
    document.getElementById('modalConfigFiscale').classList.remove('open');
}
document.getElementById('modalConfigFiscale').addEventListener('click', function(e) {
    if (e.target === this) fermerModalConfig();
});

function mettreAJourResume() {
    const tva = document.getElementById('cfg-tva-active').checked;
    const tse = document.getElementById('cfg-tse-active').checked;
    const tdt = document.getElementById('cfg-tdt-active').checked;

    document.getElementById('r-tva').textContent = tva ? '✓ 18% sur ventes HT' : 'Non activée';
    document.getElementById('r-tva').style.color  = tva ? '#10b981' : 'var(--text-3)';
    document.getElementById('r-tse').textContent = tse ? '✓ 0,1% du CA HT' : 'Non activée';
    document.getElementById('r-tse').style.color  = tse ? '#10b981' : 'var(--text-3)';
    document.getElementById('r-tdt').textContent = tdt
        ? '✓ Barème art. 873 (espèces)'
        : 'Non déclarée';
    document.getElementById('r-tdt').style.color  = tdt ? '#10b981' : 'var(--text-3)';
}

// Écouter les changements des checkboxes
['cfg-tva-active', 'cfg-tse-active', 'cfg-tdt-active'].forEach(id => {
    document.getElementById(id).addEventListener('change', mettreAJourResume);
});

function sauvegarderConfigFiscale() {
    const btn      = document.getElementById('btn-sauvegarder-config');
    const feedback = document.getElementById('cfg-feedback');

    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Enregistrement…';

    const payload = {
        tva_active: document.getElementById('cfg-tva-active').checked ? 1 : 0,
        tva_taux:   18,
        tse_active: document.getElementById('cfg-tse-active').checked ? 1 : 0,
        tse_taux:   0.10,
        tdt_active: document.getElementById('cfg-tdt-active').checked ? 1 : 0,
        tdt_taux:   1.50,
        tdt_seuil:  5000,
    };

    fetch("{{ route('admin.fne.config.sauvegarder') }}", {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}',
            'Accept': 'application/json',
        },
        body: JSON.stringify(payload),
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer la configuration';

        if (res.success) {
            feedback.style.cssText = 'display:block;padding:12px;border-radius:8px;background:#d1fae5;border:1px solid #6ee7b7;color:#065f46;font-weight:600;font-size:13px;';
            feedback.innerHTML = '<i class="fas fa-check-circle"></i> ' + res.message;
            setTimeout(() => { fermerModalConfig(); window.location.reload(); }, 1500);
        } else {
            feedback.style.cssText = 'display:block;padding:12px;border-radius:8px;background:#fff5f5;border:1px solid #fed7d7;color:#c53030;font-weight:600;font-size:13px;';
            feedback.innerHTML = '<i class="fas fa-times-circle"></i> ' + (res.message || 'Erreur.');
        }
    })
    .catch(() => {
        btn.disabled  = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Enregistrer la configuration';
        feedback.style.cssText = 'display:block;padding:12px;border-radius:8px;background:#fff5f5;border:1px solid #fed7d7;color:#c53030;font-weight:600;font-size:13px;';
        feedback.innerHTML = '<i class="fas fa-times-circle"></i> Erreur réseau.';
    });
}
</script>
@endsection
