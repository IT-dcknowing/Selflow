@extends('admin::gabarits.application')
@section('titre', 'Négociations B2B (Achats)')
@section('topbar_titre', 'B2B — Mes demandes et commandes')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-comments-dollar" style="color:var(--primary); margin-right:8px;"></i> Négociations B2B</h1>
        <p>Vos demandes de prix et vos commandes auprès des fournisseurs inscrits sur Selflow : leur réponse,
           vos contre-propositions, la confirmation, la livraison et la réception.</p>
    </div>
    <button type="button" class="btn btn-primary" data-modal-open="modalNouvelleB2b">
        <i class="fas fa-plus"></i> Nouvelle demande ou commande
    </button>
</div>

@include('admin::b2b.partials.liste')

{{-- Composer une demande de prix, ou une commande directe (autorisée par le
     propriétaire le 14/10/2026), à un fournisseur inscrit. --}}
<div class="modal-overlay" id="modalNouvelleB2b">
    <div class="modal" style="max-width:720px;">
        <div class="modal-header">
            <h3><i class="fas fa-paper-plane"></i> Nouvelle demande B2B</h3>
            <button type="button" class="modal-close" data-modal-close>✕</button>
        </div>
        @if($fournisseursB2b->isEmpty())
            <p style="padding:6px 0 12px; color:var(--text-2); font-size:13px; line-height:1.6;">
                Aucun de vos fournisseurs n'est inscrit sur Selflow. Le lien se fait par le NCC :
                renseignez celui de votre fournisseur dans sa fiche ; s'il utilise Selflow, il apparaîtra ici.
            </p>
        @else
        <form method="POST" action="{{ route('admin.b2b.rfq.creer') }}" id="formNouvelleB2b">
            @csrf
            <div class="form-group">
                <label class="form-label">Fournisseur <span style="color:var(--danger)">*</span></label>
                <select name="fournisseur_id" class="form-control" required>
                    @foreach($fournisseursB2b as $f)
                        <option value="{{ $f->id }}">{{ $f->nom }} — NCC {{ $f->ncc }}</option>
                    @endforeach
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Nature</label>
                <div style="display:flex; gap:10px; flex-wrap:wrap;">
                    <label style="flex:1; min-width:200px; border:1px solid var(--border); border-radius:8px; padding:10px; cursor:pointer;">
                        <input type="radio" name="type_demande" value="rfq" checked> <strong>Demande de prix</strong>
                        <div style="font-size:11.5px; color:var(--text-3); margin-top:3px;">Le fournisseur répond avec ses prix ; vous négociez avant de commander.</div>
                    </label>
                    <label style="flex:1; min-width:200px; border:1px solid var(--border); border-radius:8px; padding:10px; cursor:pointer;">
                        <input type="radio" name="type_demande" value="commande"> <strong>Commande directe</strong>
                        <div style="font-size:11.5px; color:var(--text-3); margin-top:3px;">Aux prix indiqués ; le fournisseur la confirme selon son stock.</div>
                    </label>
                </div>
            </div>
            <table style="width:100%; font-size:13px; margin-bottom:8px;">
                <thead><tr><th style="text-align:left;">Article</th><th style="width:110px;">Quantité</th><th style="width:140px;">Prix unitaire</th><th style="width:36px;"></th></tr></thead>
                <tbody id="lignesB2b"></tbody>
            </table>
            <button type="button" class="btn btn-outline btn-sm" onclick="ajouterLigneB2b()" style="border-style:dashed;"><i class="fas fa-plus"></i> Ajouter un article</button>
            <div class="form-group" style="margin-top:14px;">
                <label class="form-label">Message au fournisseur</label>
                <textarea name="message" class="form-control" rows="2" maxlength="1000" placeholder="Délai souhaité, conditions…"></textarea>
            </div>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" class="btn btn-outline" data-modal-close>Annuler</button>
                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i> Envoyer</button>
            </div>
        </form>
        @endif
    </div>
</div>
@endsection

@section('scripts')
<script>
const CATALOGUE_B2B = @json($produits->map(fn ($p) => ['id' => $p->id, 'nom' => $p->nom . ($p->reference ? ' (' . $p->reference . ')' : ''), 'prix' => (float) $p->prix_achat]));
let rangB2b = 0;
function ajouterLigneB2b() {
    const corps = document.getElementById('lignesB2b');
    if (!corps) return;
    const i = rangB2b++;
    const tr = document.createElement('tr');
    const options = CATALOGUE_B2B.map(p => `<option value="${p.id}" data-prix="${p.prix}">${p.nom.replace(/</g, '&lt;')}</option>`).join('');
    tr.innerHTML = `<td><select name="articles[${i}][produit_id]" class="form-control" required onchange="this.closest('tr').querySelector('.prix-b2b').value = this.selectedOptions[0].dataset.prix || 0">${options}</select></td>
        <td><input type="number" name="articles[${i}][quantite]" class="form-control" min="0.001" step="any" value="1" required></td>
        <td><input type="number" name="articles[${i}][prix_unitaire]" class="form-control prix-b2b" min="0" step="any" value="${CATALOGUE_B2B[0] ? CATALOGUE_B2B[0].prix : 0}"></td>
        <td><button type="button" class="btn btn-outline btn-sm" onclick="this.closest('tr').remove()" title="Retirer"><i class="fas fa-trash"></i></button></td>`;
    corps.appendChild(tr);
}
document.addEventListener('DOMContentLoaded', () => { if (document.getElementById('lignesB2b')) ajouterLigneB2b(); });
</script>
@endsection
