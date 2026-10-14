@extends('admin::gabarits.application')
@use('App\Modules\Admin\Modeles\DemandeSticker', 'Demande')
@section('titre', 'Commander des stickers')
@section('topbar_titre', 'Fiscalité — Commander des stickers')

@section('contenu')
<div class="page-header">
    <div>
        <h1><i class="fas fa-ticket"></i> Commander des stickers</h1>
        <p>Les stickers de certification se vendent sur votre espace FNE, auprès de la DGI.
           <strong>Selflow ne les vend pas</strong> : indiquez ce qu'il vous faut, et <strong>DC-Knowing les achète pour vous</strong>.</p>
    </div>
</div>

<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:18px; align-items:start;">

    <div class="card" style="padding:22px;">
        <h3 style="font-size:15px; font-weight:800; margin-bottom:6px;">Nouvelle demande</h3>
        <p style="font-size:12.5px; color:var(--text-2); margin-bottom:16px; line-height:1.6;">
            Solde actuel de votre espace FNE :
            <strong>{{ number_format((int) ($entreprise->fne_sticker_balance ?? 0), 0, ',', ' ') }} stickers</strong>
            @if($alerte)
                — <span style="color:{{ $alerte['niveau'] === 'epuise' ? 'var(--danger)' : '#b45309' }}; font-weight:700;">
                    {{ $alerte['niveau'] === 'epuise' ? 'épuisé : plus aucune pièce ne peut être certifiée' : 'sous le seuil d\'alerte (' . $alerte['seuil'] . ')' }}
                </span>
            @endif
        </p>

        <form method="POST" action="{{ route('admin.stickers.enregistrer') }}" id="form-stickers">
            @csrf
            <label class="form-label">Quantité <span style="color:var(--danger)">*</span></label>
            <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:8px; margin-bottom:8px;">
                @foreach(Demande::QUANTITES_PROPOSEES as $q)
                    <button type="button" class="btn btn-outline" style="justify-content:center; font-size:12.5px; padding:8px 4px;"
                            onclick="document.getElementById('quantite').value={{ $q }}; estimer();">{{ number_format($q, 0, ',', ' ') }}</button>
                @endforeach
            </div>
            <input type="number" name="quantite" id="quantite" class="form-control" min="{{ Demande::QUANTITE_MIN }}" step="1" required
                   value="{{ old('quantite', 500) }}" oninput="estimer()" style="margin-bottom:6px;">
            <p style="font-size:12px; color:var(--text-2); margin-bottom:14px;">
                Montant estimé : <strong id="estimation">—</strong>
                <span style="color:var(--text-3);">({{ number_format($prixUnitaire, 0, ',', ' ') }} F l'unité, tarif indicatif ; le prix fait foi à l'achat)</span>
            </p>

            <label class="form-label">Point de vente concerné</label>
            <select name="point_de_vente_id" class="form-control" style="margin-bottom:14px;">
                <option value="">Tous les sites</option>
                @foreach($pointsDeVente as $pdv)
                    <option value="{{ $pdv->id }}" @selected(old('point_de_vente_id') == $pdv->id)>{{ $pdv->nom }}</option>
                @endforeach
            </select>

            <label class="form-label">Urgence <span style="color:var(--danger)">*</span></label>
            <div style="display:flex; flex-direction:column; gap:6px; margin-bottom:14px;">
                @foreach(Demande::URGENCES as $cle => $libelle)
                    <label style="display:flex; gap:8px; align-items:center; font-size:13px; cursor:pointer;">
                        <input type="radio" name="urgence" value="{{ $cle }}" @checked(old('urgence', $alerte && $alerte['niveau'] === 'epuise' ? 'urgente' : 'normale') === $cle)> {{ $libelle }}
                    </label>
                @endforeach
            </div>

            <label class="form-label">Téléphone pour vous joindre</label>
            <input type="text" name="contact_telephone" class="form-control" maxlength="30" value="{{ old('contact_telephone', $entreprise->telephone ?? '') }}" style="margin-bottom:14px;">

            <label class="form-label">Commentaire</label>
            <textarea name="commentaire" class="form-control" rows="3" maxlength="1000" placeholder="Précisions utiles à DC-Knowing" style="margin-bottom:16px;">{{ old('commentaire') }}</textarea>

            <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">
                <i class="fas fa-paper-plane"></i> Transmettre à DC-Knowing
            </button>
            <p style="font-size:11.5px; color:var(--text-3); margin-top:10px; line-height:1.5;">
                La demande part par courriel à DC-Knowing et reste visible ici. Vous recevez un accusé de réception.
            </p>
        </form>
    </div>

    <div class="card" style="padding:0; overflow:hidden;">
        <div style="padding:16px 20px; border-bottom:1px solid var(--border); font-weight:800; font-size:15px;">Mes demandes</div>
        @if($demandes->isEmpty())
            <p style="padding:30px; text-align:center; color:var(--text-3); font-size:13px;">Aucune demande pour l'instant.</p>
        @else
            <table class="table" style="width:100%;">
                <thead><tr><th>Date</th><th>Quantité</th><th>Site</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                @foreach($demandes as $d)
                    <tr>
                        <td style="font-size:12.5px;">{{ $d->created_at->format('d/m/Y H:i') }}</td>
                        <td><strong>{{ number_format($d->quantite, 0, ',', ' ') }}</strong>
                            @if($d->urgence === 'urgente')<span style="color:var(--danger); font-size:11px; font-weight:700;"> urgent</span>@endif</td>
                        <td style="font-size:12.5px;">{{ $d->pointDeVente?->nom ?? 'Tous' }}</td>
                        <td>
                            <span style="font-size:11.5px; font-weight:700; padding:3px 8px; border-radius:20px; background:{{ $d->statut === 'livree' ? '#ecfdf5' : ($d->statut === 'annulee' ? '#f1f5f9' : '#eff6ff') }}; color:{{ $d->statut === 'livree' ? '#047857' : ($d->statut === 'annulee' ? '#64748b' : '#1d4ed8') }};">{{ $d->libelleStatut() }}</span>
                            @if($d->note_dcknowing)<div style="font-size:11.5px; color:var(--text-2); margin-top:4px;">{{ $d->note_dcknowing }}</div>@endif
                        </td>
                        <td>
                            @if($d->statut === 'recue')
                                <form method="POST" action="{{ route('admin.stickers.annuler', $d) }}" onsubmit="return confirm('Annuler cette demande ?');" style="margin:0;">
                                    @csrf
                                    <button class="btn btn-outline btn-sm" style="font-size:11px;">Annuler</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <div style="padding:0 16px;">{{ $demandes->links() }}</div>
        @endif
    </div>
</div>
@endsection

@section('scripts')
<script>
function estimer() {
    var q = parseInt(document.getElementById('quantite').value, 10) || 0;
    document.getElementById('estimation').textContent = new Intl.NumberFormat('fr-FR').format(q * {{ $prixUnitaire }}) + ' F CFA';
}
document.addEventListener('DOMContentLoaded', estimer);
</script>
@endsection
