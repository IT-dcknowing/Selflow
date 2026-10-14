@extends('admin::gabarits.application')
@section('titre', 'Opération & Écriture Globale')
@section('topbar_titre', 'Comptabilité — Grand Livre & Opérations')

@section('styles')
<style>
    .comp-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 24px;
    }
    
    .mode-selector {
        display: flex;
        background: var(--border);
        padding: 4px;
        border-radius: 8px;
    }
    
    .mode-btn {
        padding: 8px 16px;
        border-radius: 6px;
        border: none;
        font-weight: 600;
        font-size: 13.5px;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .mode-btn.active {
        background: #fff;
        color: var(--primary);
        box-shadow: 0 2px 6px rgba(0,0,0,0.06);
    }
    
    .mode-btn:not(.active) {
        background: transparent;
        color: var(--text-2);
    }
    
    .mode-btn:not(.active):hover {
        color: var(--text);
    }

    .filter-card {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 20px;
        display: flex;
        gap: 16px;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .filter-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--text-3);
    }
</style>
@endsection

@section('contenu')
<div class="comp-header">
    <div>
        <h1><i class="fas fa-book-open"></i> Opération & Écriture Globale</h1>
        <p>Consultez le journal des opérations de trésorerie ou le Grand Livre des écritures comptables.</p>
    </div>
    
    <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
        @if($mode === 'ecritures')
        <button type="button" onclick="ouvrirModalEcriture()" class="btn btn-primary btn-sm" style="font-weight:700;">
            <i class="fas fa-plus"></i> Saisir une écriture manuelle
        </button>
        @endif

        <div class="mode-selector">
            <a href="{{ route('admin.comptabilite.globale', ['mode' => 'operations', 'point_de_vente_id' => $pdvFilter]) }}" 
               class="mode-btn {{ $mode === 'operations' ? 'active' : '' }}">
                <i class="fas fa-wallet"></i> Afficher les opérations
            </a>
            <a href="{{ route('admin.comptabilite.globale', ['mode' => 'ecritures', 'point_de_vente_id' => $pdvFilter]) }}" 
               class="mode-btn {{ $mode === 'ecritures' ? 'active' : '' }}">
                <i class="fas fa-file-invoice-dollar"></i> Afficher les écritures
            </a>
        </div>
    </div>
</div>

{{-- FILTRE --}}
<form method="GET" action="{{ route('admin.comptabilite.globale') }}" class="filter-card" id="formGlobale">
    <input type="hidden" name="mode" value="{{ $mode }}">

    {{-- Recherche --}}
    <div class="filter-group" style="flex:1; min-width:220px;">
        <span class="filter-label">Recherche</span>
        <div style="position:relative;">
            <i class="fas fa-search" style="position:absolute; left:10px; top:50%; transform:translateY(-50%); color:var(--text-3); font-size:12px;"></i>
            <input type="text" name="search" id="inputSearch" value="{{ $search }}" placeholder="Référence, libellé, compte…" class="form-control" style="padding-left:32px; height:40px;"
                   title="Appuyez sur Entrée pour lancer la recherche" onkeydown="if (event.key === 'Enter') { event.preventDefault(); document.getElementById('formGlobale').submit(); }">
        </div>
    </div>

    @if($mode === 'operations')
    {{-- Filtre Type (Entrée / Sortie) --}}
    <div class="filter-group" style="min-width:170px;">
        <span class="filter-label">Type d'opération</span>
        <select name="type_operation" class="form-control" style="height:40px;" onchange="this.form.submit()">
            <option value="">— Tous —</option>
            <option value="recette" {{ $typeOp === 'recette' ? 'selected' : '' }}>Entrée (Recette)</option>
            <option value="depense" {{ $typeOp === 'depense' ? 'selected' : '' }}>Sortie (Dépense)</option>
        </select>
    </div>
    @endif

    @if($isAdmin)
    <div class="filter-group" style="min-width: 240px;">
        <span class="filter-label">Point de vente</span>
        <select name="point_de_vente_id" class="form-control" style="height:40px;" onchange="this.form.submit()">
            <option value="">— Tous les points de vente —</option>
            @foreach($pointsDeVente as $pdv)
            <option value="{{ $pdv->id }}" {{ $pdvFilter == $pdv->id ? 'selected' : '' }}>
                {{ $pdv->nom }} ({{ $pdv->ville }})
            </option>
            @endforeach
        </select>
    </div>
    @else
    <div class="filter-group">
        <span class="filter-label">Point de vente</span>
        <input type="text" class="form-control" value="{{ Auth::user()->pointDeVente?->nom ?? 'Siège' }}" readonly style="background:var(--bg); max-width: 240px; height:40px;">
    </div>
    @endif

    <div class="filter-group" style="justify-content:flex-end; align-self:flex-end;">
        <button type="submit" class="btn btn-primary" style="height:40px; padding: 0 20px;">
            <i class="fas fa-filter"></i> Filtrer
        </button>
        @if($search || $typeOp || $pdvFilter)
        <a href="{{ route('admin.comptabilite.globale', ['mode' => $mode]) }}" class="btn btn-outline" style="height:40px; padding:0 14px; color:var(--danger); border-color:var(--danger); margin-top:4px;">
            <i class="fas fa-times"></i> Réinitialiser
        </a>
        @endif
    </div>
</form>

<div class="card">
    <div class="table-wrap">
        @if($mode === 'operations')
            {{-- TABLEAU DES OPÉRATIONS DE TRÉSORERIE --}}
            @if($operations->isEmpty())
                <div style="padding: 48px; text-align:center; color:var(--text-3);">
                    <i class="fas fa-wallet" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
                    Aucune opération enregistrée pour le moment.
                </div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>N° Saisie</th>
                            <th>Référence</th>
                            <th>Libellé</th>
                            <th>Mode paiement</th>
                            <th style="text-align: right; color:var(--success);">Entrée</th>
                            <th style="text-align: right; color:var(--danger);">Sortie</th>
                            <th>Tiers</th>
                            <th>PDV</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($operations as $op)
                        <tr>
                            <td style="font-weight: 500; white-space:nowrap;">{{ \Carbon\Carbon::parse($op->date_operation)->format('d/m/Y') }}</td>
                            <td style="font-weight: 700; color: var(--primary);">
                                @if(isset($op->no_saisie))
                                    <a href="{{ route('admin.comptabilite.globale', ['mode' => 'ecritures', 'ref' => $op->reference_document]) }}"
                                       style="color:var(--primary); text-decoration:none;"
                                       title="Voir les écritures liées">
                                        {{ is_numeric($op->no_saisie) ? '#' . $op->no_saisie : $op->no_saisie }}
                                    </a>
                                @else
                                    #{{ $op->id }}
                                @endif
                            </td>
                            <td style="font-weight: 600; color:var(--text-2);">{{ $op->reference_document ?? '—' }}</td>
                            <td style="white-space: normal; min-width: 200px;">{{ $op->libelle }}</td>
                            <td>
                                <span style="font-size:12px;">
                                    @php
                                        $modeIcons = ['Espèces'=>'fa-money-bill-wave','Virement'=>'fa-university','Chèque'=>'fa-file-alt','Carte bancaire'=>'fa-credit-card','Mobile Money'=>'fa-mobile-alt'];
                                        $icon = $modeIcons[$op->mode_paiement] ?? 'fa-wallet';
                                    @endphp
                                    <i class="fas {{ $icon }}" style="margin-right:4px;"></i>
                                    {{ $op->mode_paiement }}
                                    @if($op->moyen_bancaire)
                                        <br><small style="color:var(--text-3);">{{ $op->moyen_bancaire }}</small>
                                    @endif
                                </span>
                            </td>
                            <td style="text-align: right; font-weight: 700;">
                                @if($op->montant_entree > 0)
                                    <span style="color: var(--success);">+{{ number_format($op->montant_entree, 0, ',', ' ') }} F</span>
                                @else
                                    <span style="color:var(--text-3);">—</span>
                                @endif
                            </td>
                            <td style="text-align: right; font-weight: 700;">
                                @if($op->montant_sortie > 0)
                                    <span style="color: var(--danger);">-{{ number_format($op->montant_sortie, 0, ',', ' ') }} F</span>
                                @else
                                    <span style="color:var(--text-3);">—</span>
                                @endif
                            </td>
                            <td style="font-weight: 500;">{{ $op->tier_nom }}</td>
                            <td><span style="background:var(--bg3); color:var(--primary); padding:3px 8px; border-radius:6px; font-weight:600; font-size:12px;">{{ $op->pointDeVente->nom }}</span></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                <div style="padding: 16px;">
                    {{ $operations->appends(request()->query())->links() }}
                </div>
            @endif
        @else
            {{-- GRAND LIVRE DES ÉCRITURES DOUBLE-ENTRÉE --}}
            @if($ecritures->isEmpty())
                <div style="padding: 48px; text-align:center; color:var(--text-3);">
                    <i class="fas fa-file-invoice-dollar" style="font-size:48px; display:block; margin-bottom:12px; opacity:.2;"></i>
                    Aucune écriture comptable enregistrée.
                </div>
            @else
                <table>
                    <thead>
                        <tr>
                            <th>Date de pièce</th>
                            <th>N° Saisie / Pièce</th>
                            <th>Code journal</th>
                            <th>Référence pièce</th>
                            <th>N° Compte Général</th>
                            <th>Titre / Libellé</th>
                            <th style="text-align: right; color:#1e3a8a;">Montant Débit</th>
                            <th style="text-align: right; color:#7f1d1d;">Montant Crédit</th>
                            <th>N° Compte Tiers</th>
                            <th>Point de Vente</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ecritures as $ecr)
                        @php
                            // Compte général mouvementé (débit ou crédit) — TOUJOURS le compte
                            // collectif réel (ex: 411000), plus jamais le code tiers individuel
                            // depuis le correctif du 22/07/2026.
                            $compte = $ecr->compte_debit ?? $ecr->compte_credit;

                            // N° Saisie : lu directement depuis l'Operation liée (colonne réelle
                            // stockée en base, séquentielle par journal) — ne dépend plus d'un
                            // recalcul MIN(id) à la volée.
                            $saisieNum = $ecr->operation ? ('#' . $ecr->operation->numero_saisie) : ('#' . $ecr->id);
                        @endphp
                        <tr>
                            <td style="font-weight: 500; white-space:nowrap;">{{ \Carbon\Carbon::parse($ecr->date_ecriture)->format('d/m/Y') }}</td>
                            <td style="font-weight: 700; color: var(--primary);">{{ $saisieNum }}</td>
                            <td><span class="badge" style="background: var(--bg3); color: var(--primary); font-weight:700;">{{ $ecr->code_journal }}</span></td>
                            <td style="font-weight: 700; color: var(--primary);">{{ $ecr->reference_document ?? '—' }}</td>
                            <td style="font-weight: 700; color: var(--text-1);">
                                {{-- En SYSCOHADA, le Compte Général est TOUJOURS affiché (y compris 401000 / 411000) --}}
                                {{ $compte }}
                            </td>
                            <td style="white-space: normal; min-width: 240px; font-weight:500;">
                                @if($ecr->description)
                                    <span
                                        onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none';"
                                        style="cursor:pointer; text-decoration:underline dotted; text-decoration-color:var(--text-3);"
                                        title="Cliquer pour voir le détail complet"
                                    >{{ $ecr->libelle }}</span>
                                    <div style="display:none; margin-top:4px; font-size:12px; color:var(--text-2); background:#f8fafc; border:1px solid var(--border); border-radius:6px; padding:6px 8px;">
                                        <i class="fas fa-list"></i> {{ $ecr->description }}
                                    </div>
                                @else
                                    {{ $ecr->libelle }}
                                @endif
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #1e3a8a;">
                                {{ $ecr->debit > 0 ? number_format($ecr->debit, 0, ',', ' ') . ' F' : '—' }}
                            </td>
                            <td style="text-align: right; font-weight: 700; color: #7f1d1d;">
                                {{ $ecr->credit > 0 ? number_format($ecr->credit, 0, ',', ' ') . ' F' : '—' }}
                            </td>
                            <td style="font-weight: 600; color: var(--text-2);">
                                {{-- Compte Tiers : code individuel du client/fournisseur (colonne
                                     dédiée compte_tiers), distinct du compte général ci-dessus --}}
                                {{ $ecr->compte_tiers ?? '—' }}
                            </td>
                            <td><span style="background:var(--bg3); color:var(--primary); padding:3px 8px; border-radius:6px; font-weight:600; font-size:12px;">{{ $ecr->pointDeVente->nom }}</span></td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                <div style="padding: 16px;">
                    {{ $ecritures->appends(request()->query())->links() }}
                </div>
            @endif
        @endif
    </div>
</div>

{{-- MODAL DE CRÉATION D'ÉCRITURE MANUELLE --}}
<div class="modal-overlay" id="modalEcritureManuelle" style="display:none; position:fixed; top:0; left:0; right:0; bottom:0; background:rgba(0,0,0,0.5); z-index:9999; align-items:center; justify-content:center;">
    <div class="modal" style="background:#fff; border-radius:12px; max-width:600px; width:100%; box-shadow:0 10px 30px rgba(0,0,0,0.15); overflow:hidden;">
        <div class="modal-header" style="padding:16px 20px; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center;">
            <h3 style="font-size:16px; font-weight:700; color:var(--text-1); margin:0;"><i class="fas fa-plus" style="color:var(--primary)"></i> Nouvelle écriture manuelle</h3>
            <button type="button" class="modal-close" onclick="fermerModalEcriture()" style="background:none; border:none; font-size:24px; cursor:pointer; color:var(--text-3);">&times;</button>
        </div>
        <form method="POST" action="{{ route('admin.comptabilite.ecriture_manuelle') }}" id="formEcritureManuelle" style="margin:0; padding:20px;">
            @csrf

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-bottom:14px;">
                <div class="form-group">
                    <label class="form-label">Date d'écriture <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="date_ecriture" class="form-control" required value="{{ date('Y-m-d') }}">
                </div>
                <div class="form-group">
                    <label class="form-label">Code Journal <span style="color:var(--danger)">*</span></label>
                    <select name="code_journal" class="form-control" required>
                        @forelse($codesJournaux as $j)
                            <option value="{{ $j->code }}">{{ $j->code }} - {{ $j->intitule }} ({{ $j->type }})</option>
                        @empty
                            <option value="OD">OD - Opérations Diverses</option>
                            <option value="CA">CA - Caisse principale</option>
                            <option value="BQ">BQ - Banque</option>
                            <option value="VT">VT - Journal de Ventes</option>
                            <option value="AC">AC - Journal d'Achats</option>
                        @endforelse
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Titre / Libellé court <span style="color:var(--danger)">*</span></label>
                <input type="text" name="libelle" class="form-control" required placeholder="Ex: Constatation provision, Régularisation paie..." maxlength="255">
            </div>

            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Description libre / Détail</label>
                <textarea name="description" class="form-control" rows="2" placeholder="Saisissez des précisions sur cette écriture..." maxlength="2000"></textarea>
            </div>

            @if($isAdmin)
            <div class="form-group" style="margin-bottom:14px;">
                <label class="form-label">Affectation Point de Vente</label>
                <select name="point_de_vente_id" class="form-control">
                    <option value="">Sélectionner un site...</option>
                    @foreach($pointsDeVente as $pdv)
                        <option value="{{ $pdv->id }}">{{ $pdv->nom }}</option>
                    @endforeach
                </select>
            </div>
            @endif

            {{-- Lignes de l'écriture : nombre illimité, débit ou crédit chacune --}}
            <div style="border-top:1px solid var(--border); padding-top:14px; margin-top:4px;">
                <label class="form-label" style="margin-bottom:8px; display:block;">Lignes de l'écriture <span style="color:var(--danger)">*</span></label>
                <div id="lignesEcriture"></div>
                <button type="button" class="btn btn-outline" onclick="ajouterLigneEcriture()" style="margin-top:8px; font-size:13px;">
                    <i class="fas fa-plus"></i> Ajouter une ligne
                </button>
            </div>

            <div id="equilibreInfo" style="margin-top:14px; padding:10px 12px; border-radius:8px; font-size:13px; font-weight:600;"></div>

            <div style="border-top:1px solid var(--border); padding-top:14px; margin-top:14px; display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" class="btn btn-outline" onclick="fermerModalEcriture()">Annuler</button>
                <button type="submit" class="btn btn-primary" id="btnEnregistrerEcriture"><i class="fas fa-save"></i> Enregistrer l'écriture</button>
            </div>
        </form>
    </div>
</div>

<script>
let compteurLignesEcriture = 0;

function ligneEcritureTemplate(index) {
    return `
        <div class="ligne-ecriture" data-index="${index}" style="display:grid; grid-template-columns: 2fr 1.4fr 1fr 1.2fr auto; gap:8px; align-items:center; margin-bottom:8px;">
            <input type="text" name="lignes[${index}][compte]" class="form-control" placeholder="N° Compte (ex: 601000)" pattern="[0-9]+" required title="Numéro de compte comptable">
            <input type="text" name="lignes[${index}][compte_tiers]" class="form-control" placeholder="Compte tiers (optionnel)">
            <select name="lignes[${index}][sens]" class="form-control ligne-sens" required onchange="recalculerEquilibre()">
                <option value="debit">Débit</option>
                <option value="credit">Crédit</option>
            </select>
            <input type="number" name="lignes[${index}][montant]" class="form-control ligne-montant" placeholder="Montant" min="0.01" step="0.01" required oninput="recalculerEquilibre()">
            <button type="button" class="btn btn-outline" style="padding:6px 10px; color:var(--danger); border-color:var(--danger);" onclick="retirerLigneEcriture(${index})" title="Retirer cette ligne">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
}

function ajouterLigneEcriture(sensParDefaut) {
    const conteneur = document.getElementById('lignesEcriture');
    const index = compteurLignesEcriture++;
    conteneur.insertAdjacentHTML('beforeend', ligneEcritureTemplate(index));
    if (sensParDefaut) {
        conteneur.querySelector(`.ligne-ecriture[data-index="${index}"] .ligne-sens`).value = sensParDefaut;
    }
    recalculerEquilibre();
}

function retirerLigneEcriture(index) {
    const ligne = document.querySelector(`.ligne-ecriture[data-index="${index}"]`);
    if (ligne) ligne.remove();
    recalculerEquilibre();
}

function recalculerEquilibre() {
    let totalDebit = 0, totalCredit = 0;
    document.querySelectorAll('.ligne-ecriture').forEach(ligne => {
        const sens = ligne.querySelector('.ligne-sens').value;
        const montant = parseFloat(ligne.querySelector('.ligne-montant').value) || 0;
        if (sens === 'debit') totalDebit += montant; else totalCredit += montant;
    });

    const info = document.getElementById('equilibreInfo');
    const btn = document.getElementById('btnEnregistrerEcriture');
    const formatteur = new Intl.NumberFormat('fr-FR');
    const equilibree = Math.abs(totalDebit - totalCredit) < 0.01 && totalDebit > 0;

    info.textContent = `Total Débit : ${formatteur.format(totalDebit)} F  —  Total Crédit : ${formatteur.format(totalCredit)} F` +
        (equilibree ? '  ✅ Équilibrée' : '  ⚠️ Non équilibrée');
    info.style.background = equilibree ? '#ecfdf5' : '#fef2f2';
    info.style.color = equilibree ? '#065f46' : '#7f1d1d';
    btn.disabled = !equilibree;
}

function ouvrirModalEcriture() {
    const modal = document.getElementById('modalEcritureManuelle');
    // Réinitialiser avec 2 lignes de départ (1 débit, 1 crédit) à chaque ouverture
    document.getElementById('lignesEcriture').innerHTML = '';
    compteurLignesEcriture = 0;
    ajouterLigneEcriture('debit');
    ajouterLigneEcriture('credit');
    modal.style.display = 'flex';
}
function fermerModalEcriture() {
    const modal = document.getElementById('modalEcritureManuelle');
    modal.style.display = 'none';
}

// Restaurer le focus sur le champ de recherche après auto-submit
document.addEventListener('DOMContentLoaded', () => {
    const inp = document.getElementById('inputSearch');
    if (inp && inp.value) {
        inp.focus();
        inp.setSelectionRange(inp.value.length, inp.value.length);
    }
});
</script>
@endsection
