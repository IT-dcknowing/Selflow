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
            {{-- Toute autre quantité se saisit librement. --}}
            <input type="number" name="quantite" id="quantite" class="form-control" min="{{ Demande::QUANTITE_MIN }}" step="1" required
                   value="{{ old('quantite', 500) }}" oninput="estimer()" placeholder="Saisissez la quantité" style="margin-bottom:10px;">

            <label class="form-label">Montant estimé</label>
            <input type="text" id="estimation" class="form-control" readonly tabindex="-1"
                   style="background:#f0f9ff; border-color:#bae6fd; color:#075985; font-weight:800; font-size:16px; cursor:default; margin-bottom:4px;">
            <p style="font-size:11.5px; color:var(--text-3); margin-bottom:14px;">
                {{ number_format($prixUnitaire, 0, ',', ' ') }} F l'unité, tarif indicatif ; le prix fait foi à l'achat.
            </p>

            {{-- Le paiement, en attendant l'API : un dépôt sur le numéro du
                 cabinet, puis la référence de la transaction (16/10/2026). --}}
            <div style="border:1px solid #fde68a; background:#fffbeb; border-radius:10px; padding:12px 14px; margin-bottom:14px;">
                <div style="font-size:12.5px; font-weight:800; color:#92400e; margin-bottom:6px;"><i class="fas fa-mobile-screen"></i> Paiement</div>
                @php $numeros = \App\Modules\Admin\Regles\PaiementParReference::numerosDuCabinet(); @endphp
                @if($numeros)
                    <p style="font-size:12px; color:#92400e; line-height:1.6; margin-bottom:8px;">Déposez le montant sur un numéro du cabinet DC-Knowing :</p>
                    <ul style="font-size:12.5px; font-weight:700; color:#78350f; margin:0 0 10px 18px; line-height:1.7;">
                        @foreach($numeros as $numero)<li>{{ $numero }}</li>@endforeach
                    </ul>
                @else
                    <p style="font-size:12px; color:#92400e; margin-bottom:8px;">Déposez le montant sur le numéro du cabinet DC-Knowing communiqué par l'assistance.</p>
                @endif
                <label class="form-label">Opérateur utilisé <span style="color:var(--danger)">*</span></label>
                <select name="operateur_paiement" class="form-control" required style="margin-bottom:10px;">
                    <option value="">— Choisir l'opérateur —</option>
                    @foreach(\App\Modules\Admin\Regles\PaiementParReference::OPERATEURS as $code => $libelle)
                        <option value="{{ $code }}" @selected(old('operateur_paiement') === $code)>{{ $libelle }}</option>
                    @endforeach
                </select>
                <label class="form-label">Référence de la transaction <span style="color:var(--danger)">*</span></label>
                <input type="text" name="reference_paiement" class="form-control" required maxlength="100"
                       value="{{ old('reference_paiement') }}" placeholder="Reçue par SMS après le dépôt">
                @error('reference_paiement')<small style="color:var(--danger);">{{ $message }}</small>@enderror
                @error('operateur_paiement')<small style="color:var(--danger);">{{ $message }}</small>@enderror
            </div>

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
                <thead><tr><th>Date</th><th>Quantité</th><th>Priorité</th><th>Site</th><th>Statut</th><th></th></tr></thead>
                <tbody>
                @foreach($demandes as $d)
                    @php [$fond, $texte] = $d->couleurs(); @endphp
                    <tr>
                        <td style="font-size:12.5px; white-space:nowrap;">{{ $d->created_at->format('d/m/Y H:i') }}</td>
                        <td><strong>{{ number_format($d->quantite, 0, ',', ' ') }}</strong>
                            <div style="font-size:11px; color:var(--text-3);">{{ number_format((float) $d->montant_estime, 0, ',', ' ') }} F</div></td>
                        <td>
                            <span style="font-size:11px; font-weight:700; padding:2px 8px; border-radius:20px; {{ $d->urgence === 'urgente' ? 'background:#fee2e2; color:#b91c1c;' : 'background:#f1f5f9; color:#475569;' }}">
                                {{ $d->urgence === 'urgente' ? 'Urgente' : 'Normale' }}
                            </span>
                        </td>
                        <td style="font-size:12.5px;">{{ $d->pointDeVente?->nom ?? 'Tous' }}</td>
                        <td>
                            <span style="font-size:11.5px; font-weight:700; padding:3px 8px; border-radius:20px; background:{{ $fond }}; color:{{ $texte }};">{{ $d->libelleStatut() }}</span>
                            {{-- La traçabilité : chaque étape et son heure. --}}
                            <div class="etapes-demande">
                                @foreach($d->etapes() as $etape)
                                    <div><span>{{ $etape['libelle'] }}</span> {{ $etape['le']?->format('d/m/Y H:i') }}</div>
                                @endforeach
                            </div>
                            @if($d->motif_echec)<div style="font-size:11.5px; color:#b91c1c; margin-top:4px;">Motif : {{ $d->motif_echec }}</div>@endif
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

@section('styles')
<style>
    .etapes-demande { margin-top:6px; font-size:11px; color:var(--text-3); line-height:1.6; border-left:2px solid var(--border); padding-left:8px; }
    .etapes-demande span { font-weight:700; color:var(--text-2); }
</style>
@endsection

@section('scripts')
<script>
function estimer() {
    var q = parseInt(document.getElementById('quantite').value, 10) || 0;
    document.getElementById('estimation').value = new Intl.NumberFormat('fr-FR').format(q * {{ $prixUnitaire }}) + ' F CFA';
}
document.addEventListener('DOMContentLoaded', estimer);
</script>
@endsection
