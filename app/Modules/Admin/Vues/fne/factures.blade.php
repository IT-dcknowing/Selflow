@extends('admin::gabarits.application')
@section('titre', 'Factures & Reçus')
@section('topbar_titre', 'Factures & Reçus émis / reçus')

@section('styles')
<style>
    .fne-filtres { display:flex; gap:12px; align-items:end; flex-wrap:wrap; background:#fff; border:1px solid var(--border); border-radius:14px; padding:16px 20px; margin-bottom:18px; }
    .fne-filtres .form-group { margin-bottom:0; }
    .fne-filtres label { font-size:11px; text-transform:uppercase; font-weight:700; color:var(--text-3); display:block; margin-bottom:4px; }

    .flux-toggle { display:flex; background:var(--bg3); border-radius:10px; padding:4px; gap:4px; margin-bottom:14px; width:fit-content; }
    .flux-toggle button { border:none; background:transparent; padding:8px 20px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; color:var(--text-2); }
    .flux-toggle button.active { background:#fff; color:var(--primary); box-shadow:0 1px 3px rgba(0,0,0,.08); }

    .categorie-tabs { display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap; }
    .categorie-tabs button { border:1px solid var(--border); background:#fff; padding:7px 16px; border-radius:20px; font-size:12px; font-weight:600; cursor:pointer; color:var(--text-2); }
    .categorie-tabs button.active { background:var(--primary); color:#fff; border-color:var(--primary); }

    .doc-table { width:100%; border-collapse:collapse; background:#fff; border:1px solid var(--border); border-radius:14px; overflow:hidden; }
    .doc-table th { text-align:left; padding:10px 14px; font-size:11px; text-transform:uppercase; color:var(--text-3); background:#f8fafc; border-bottom:1px solid var(--border); white-space:nowrap; }
    .doc-table td { padding:11px 14px; border-bottom:1px solid var(--border); font-size:13px; white-space:nowrap; }
    .doc-table tr:last-child td { border-bottom:none; }
    .doc-table tr:hover td { background:#fafbfc; }

    .statut-fne { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
    .statut-oui { background:#ecfdf5; color:#065f46; }
    .statut-non { background:#fef2f2; color:#991b1b; }

    .pagination-bar { display:flex; justify-content:space-between; align-items:center; padding:14px 4px; font-size:13px; color:var(--text-3); }
    .pagination-bar button { border:1px solid var(--border); background:#fff; padding:6px 14px; border-radius:8px; cursor:pointer; font-weight:600; }
    .pagination-bar button:disabled { opacity:.4; cursor:not-allowed; }
</style>
@endsection

@section('contenu')

@include('admin::fne.partials.filtre-periode', [
    'onChange' => 'page=1;rafraichirFactures();',
    'avant'    => 'admin::fne.partials.champ-recherche',
])

<div class="flux-toggle">
    <button id="btn-flux-ventes" class="active" onclick="changerFlux('ventes')"><i class="fas fa-arrow-down" style="color:#10b981;"></i> Ventes</button>
    <button id="btn-flux-achats" onclick="changerFlux('achats')"><i class="fas fa-arrow-up" style="color:#ef4444;"></i> Achats</button>
</div>

<div class="categorie-tabs" id="onglets-ventes">
    <button class="active" data-cat="emis" onclick="changerCategorie('emis')">Factures Émises</button>
    <button data-cat="recu_recu" onclick="changerCategorie('recu_recu')">Reçus (comptant)</button>
    <button data-cat="avoir_client" onclick="changerCategorie('avoir_client')">Avoirs Clients</button>
</div>
<div class="categorie-tabs" id="onglets-achats" style="display:none;">
    <button class="active" data-cat="recu" onclick="changerCategorie('recu')">Factures Reçues</button>
    <button data-cat="emis" onclick="changerCategorie('emis')">BAPA (émis par nous)</button>
    <button data-cat="avoir_fournisseur" onclick="changerCategorie('avoir_fournisseur')">Avoirs Fournisseurs</button>
</div>

<table class="doc-table">
    <thead>
        <tr>
            <th>Date</th>
            <th>Type</th>
            <th>N° Pièce</th>
            <th>N° FNE (DGI)</th>
            <th id="th-tiers">Client</th>
            <th style="text-align:right;">HT</th>
            <th style="text-align:right;">TVA</th>
            <th style="text-align:right;">TTC</th>
            <th>Statut DGI</th>
            <th>Site</th>
            <th>Reçu lié</th>
            <th>Fichier reçu</th>
            <th>Facture origine</th>
            <th style="text-align:center;">Actions</th>
        </tr>
    </thead>
    <tbody id="corps-table">
        <tr><td colspan="14" style="text-align:center; padding:30px; color:var(--text-3);">Chargement...</td></tr>
    </tbody>
</table>

<div class="pagination-bar">
    <div id="pagination-info">—</div>
    <div style="display:flex; gap:8px;">
        <button id="btn-precedent" onclick="pagePrecedente()"><i class="fas fa-chevron-left"></i> Précédent</button>
        <button id="btn-suivant" onclick="pageSuivante()">Suivant <i class="fas fa-chevron-right"></i></button>
    </div>
</div>

<script>
/**
 * D'où vient la ligne : de la saisie Selflow, du portail de la DGI, ou des deux.
 *
 * Sans ce repère, une facture relevée au portail et une facture saisie ici se
 * ressemblaient trait pour trait, alors qu'on ne peut pas en faire la même
 * chose : la première n'est pas notre pièce, elle ne s'imprime ni ne se
 * normalise — elle se rapproche d'un achat.
 */
function badgeOrigine(origine) {
    if (origine === 'portail') {
        return '<span title="Relevée au portail de la DGI, pas encore rattachée à un achat de Selflow" style="background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; padding:2px 7px; border-radius:12px; font-size:10px; font-weight:700; margin-left:6px; white-space:nowrap;"><i class="fas fa-cloud-arrow-down"></i> Portail DGI</span>';
    }
    if (origine === 'selflow_dgi') {
        return '<span title="Saisie dans Selflow et retrouvée au portail de la DGI" style="background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; padding:2px 7px; border-radius:12px; font-size:10px; font-weight:700; margin-left:6px; white-space:nowrap;"><i class="fas fa-link"></i> Rapprochée</span>';
    }
    return '';
}

function telechargerDirectement(url) {
    const iframe = document.createElement('iframe');
    iframe.style.position = 'absolute';
    iframe.style.width = '1024px';
    iframe.style.height = '768px';
    iframe.style.top = '-9999px';
    iframe.style.left = '-9999px';
    iframe.style.border = 'none';
    iframe.src = url;
    document.body.appendChild(iframe);
    setTimeout(() => { iframe.remove(); }, 15000);
}

let flux = 'ventes';
let categorie = 'emis';
let page = 1;
let dernierePagination = { derniere_page: 1 };
let debounceTimer = null;

function formatF(n) {
    return new Intl.NumberFormat('fr-FR').format(Math.round(n || 0)) + ' F';
}

function debouncedRafraichir() {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(rafraichirFactures, 400);
}

function changerFlux(f) {
    flux = f;
    document.getElementById('btn-flux-ventes').classList.toggle('active', f === 'ventes');
    document.getElementById('btn-flux-achats').classList.toggle('active', f === 'achats');
    document.getElementById('onglets-ventes').style.display = f === 'ventes' ? 'flex' : 'none';
    document.getElementById('onglets-achats').style.display = f === 'achats' ? 'flex' : 'none';
    document.getElementById('th-tiers').textContent = f === 'ventes' ? 'Client' : 'Fournisseur';
    categorie = f === 'ventes' ? 'emis' : 'recu';
    document.querySelectorAll(`#onglets-${f} button`).forEach((b, i) => b.classList.toggle('active', i === 0));
    page = 1;
    rafraichirFactures();
}

function changerCategorie(cat) {
    categorie = cat;
    const conteneurId = flux === 'ventes' ? 'onglets-ventes' : 'onglets-achats';
    document.querySelectorAll(`#${conteneurId} button`).forEach(b => b.classList.toggle('active', b.dataset.cat === cat));
    page = 1;
    rafraichirFactures();
}

function pagePrecedente() { if (page > 1) { page--; rafraichirFactures(); } }
function pageSuivante() { if (page < dernierePagination.derniere_page) { page++; rafraichirFactures(); } }

function rafraichirFactures() {
    const params = parametresFiltreFne();
    params.set('flux', flux);
    params.set('categorie', categorie);
    params.set('recherche', document.getElementById('f-recherche').value);
    params.set('page', page);

    document.getElementById('corps-table').innerHTML = '<tr><td colspan="14" style="text-align:center; padding:30px; color:var(--text-3);"><i class="fas fa-spinner fa-spin"></i> Chargement...</td></tr>';

    fetch("{{ route('admin.fne.factures.donnees') }}?" + params.toString())
        .then(r => r.json())
        .then(d => {
            dernierePagination = d.pagination;
            const corps = document.getElementById('corps-table');

            if (d.documents.length === 0) {
                corps.innerHTML = '<tr><td colspan="14" style="text-align:center; padding:30px; color:var(--text-3);">Aucun document pour cette période/catégorie.</td></tr>';
            } else {
                corps.innerHTML = d.documents.map(doc => `
                    <tr>
                        <td>${doc.date ? new Date(doc.date).toLocaleDateString('fr-FR') : '—'}</td>
                        <td style="white-space:nowrap;">${doc.type_doc}${badgeOrigine(doc.origine)}</td>
                        <td style="font-weight:700; color:var(--primary);">${doc.num_piece}</td>
                        <td>${doc.num_fne ?? '<span style="color:var(--text-3);">—</span>'}</td>
                        <td>${doc.tiers}</td>
                        <td style="text-align:right;">${formatF(doc.ht)}</td>
                        <td style="text-align:right;">${formatF(doc.tva)}</td>
                        <td style="text-align:right; font-weight:700;">${formatF(doc.ttc)}</td>
                        <td>
                            <span class="statut-fne ${doc.normalise ? 'statut-oui' : 'statut-non'}">
                                <i class="fas ${doc.normalise ? 'fa-circle-check' : 'fa-circle-xmark'}"></i>
                                ${doc.normalise ? 'Normalisée' : 'Non normalisée'}
                            </span>
                        </td>
                        <td>${doc.pdv ?? (doc.origine === 'portail'
                            ? '<span style="color:var(--text-3); font-style:italic;" title="Le portail ne dit pas à quel site de l\'entreprise cette facture se rattache. Le site vient de l\'achat auquel on la rapproche.">non affecté</span>'
                            : '—')}</td>
                        <td>${doc.recu_lie
                            ? `<a href="${doc.recu_lie_url}" target="_blank" style="font-weight:600;">${doc.recu_lie}</a>`
                            : '<span style="color:var(--text-3);">—</span>'}</td>
                        <td style="text-align:center;">${doc.fichier_recu_url
                            ? `<a href="${doc.fichier_recu_url}" target="_blank" class="btn btn-outline" style="padding:4px 8px; font-size:11px;" title="Ouvrir le reçu"><i class="fas fa-receipt"></i></a>`
                            : '<span style="color:var(--text-3);">—</span>'}</td>
                        <td>${doc.local_url ? `
                            <div style="display:flex; gap:6px; align-items:center; justify-content:center;">
                                <span style="font-weight:600; margin-right:4px;">${doc.facture_origine ? doc.facture_origine : doc.num_piece}</span>
                                <a href="${doc.local_url}" class="btn btn-outline" style="padding:4px 8px; font-size:11px;" title="Voir la facture d'origine (4 modèles)">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <button type="button" onclick="telechargerDirectement('${doc.local_url}?download=1')" class="btn btn-outline" style="padding:4px 8px; font-size:11px; cursor:pointer; height:auto; display:inline-flex; align-items:center; justify-content:center;" title="Télécharger la facture d'origine (4 modèles)">
                                    <i class="fas fa-download"></i>
                                </button>
                            </div>
                        ` : '<span style="color:var(--text-3);">—</span>'}</td>
                        <td style="text-align:center;">
                            <div style="display:flex; gap:6px; justify-content:center; align-items:center;">
                                ${doc.origine === 'portail' ? `
                                    ${doc.voir_url ? `
                                        <a href="${doc.voir_url}" target="_blank" class="btn btn-outline" style="padding:5px 10px; font-size:12px;" title="Voir la pièce chez la DGI, qui la détient">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="${doc.telechargement_url}" target="_blank" class="btn btn-outline" style="padding:5px 10px; font-size:12px;" title="Ouvrir la page de vérification DGI de cette pièce">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    ` : ''}
                                    <a href="${doc.rapprocher_url}" class="btn btn-outline" style="padding:5px 10px; font-size:12px; white-space:nowrap;" title="Cette pièce est détenue par la DGI mais n'est rattachée à aucun achat de Selflow. La rapprocher d'un achat existant.">
                                        <i class="fas fa-link"></i> Rapprocher
                                    </a>
                                ` : doc.normalise ? `
                                    <a href="${doc.voir_url}" target="_blank" class="btn btn-outline" style="padding:5px 10px; font-size:12px;" title="Voir le document original / FNE">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="${doc.telechargement_url}" target="_blank" class="btn btn-outline" style="padding:5px 10px; font-size:12px;" title="Télécharger le PDF">
                                        <i class="fas fa-download"></i>
                                    </a>
                                ` : `
                                    <button class="btn btn-outline" style="padding:5px 10px; font-size:12px; opacity:0.5; cursor:not-allowed;" disabled title="Indisponible pour les factures non normalisées">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <form method="POST" action="${doc.normaliser_url}" style="display:inline; margin:0;">
                                        <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                        <button type="submit" class="btn btn-success" style="padding:5px 10px; font-size:12px; color:#fff;" title="Normaliser auprès de la DGI">
                                            <i class="fas fa-share-nodes"></i> Normaliser
                                        </button>
                                    </form>
                                `}
                            </div>
                        </td>
                    </tr>
                `).join('');
            }

            document.getElementById('pagination-info').textContent =
                d.pagination.total > 0 ? `${d.pagination.de}–${d.pagination.a} sur ${d.pagination.total} document(s) — Total TTC affiché : ${formatF(d.totaux.ttc)}` : 'Aucun résultat';
            document.getElementById('btn-precedent').disabled = d.pagination.page_courante <= 1;
            document.getElementById('btn-suivant').disabled = d.pagination.page_courante >= d.pagination.derniere_page;
        })
        .catch(() => {
            document.getElementById('corps-table').innerHTML = '<tr><td colspan="14" style="text-align:center; padding:30px; color:#991b1b;">Erreur de chargement. Réessayez.</td></tr>';
        });
}

document.addEventListener('DOMContentLoaded', rafraichirFactures);
</script>
@endsection
