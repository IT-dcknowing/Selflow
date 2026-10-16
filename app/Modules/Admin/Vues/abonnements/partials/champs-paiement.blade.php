{{--
    Le paiement par référence d'une offre (16/10/2026), en attendant l'API :
    dépôt sur le numéro du cabinet, puis l'opérateur et la référence.
    Styles en ligne : le bloc sert sur la page des offres (sans le gabarit de
    l'application) comme dans la section Facturation.

    @param  int  $montant
--}}
@php
    $numeros = \App\Modules\Admin\Regles\PaiementParReference::numerosDuCabinet();
@endphp
<div class="champs-paiement" style="display:none; text-align:left; margin-top:10px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:12px;">
    <div style="font-size:12.5px; font-weight:800; color:#92400e; margin-bottom:6px;">
        Paiement : {{ number_format($montant, 0, ',', ' ') }} F
    </div>
    @if($numeros)
        <div style="font-size:11.5px; color:#92400e; margin-bottom:4px;">Déposez le montant sur un numéro du cabinet DC-Knowing :</div>
        <ul style="font-size:12px; font-weight:700; color:#78350f; margin:0 0 8px 16px; line-height:1.6;">
            @foreach($numeros as $numero)<li>{{ $numero }}</li>@endforeach
        </ul>
    @else
        <div style="font-size:11.5px; color:#92400e; margin-bottom:8px;">Déposez le montant sur le numéro du cabinet communiqué par l'assistance.</div>
    @endif
    <select name="operateur_paiement" style="width:100%; height:36px; border:1px solid #E2E8F0; border-radius:8px; padding:4px 8px; margin-bottom:6px; font-family:inherit;">
        <option value="">— Opérateur utilisé —</option>
        @foreach(\App\Modules\Admin\Regles\PaiementParReference::OPERATEURS as $code => $libelle)
            <option value="{{ $code }}">{{ $libelle }}</option>
        @endforeach
    </select>
    <input type="text" name="reference_paiement" maxlength="100" placeholder="Référence de la transaction (SMS)"
           style="width:100%; height:36px; border:1px solid #E2E8F0; border-radius:8px; padding:4px 8px; margin-bottom:8px; font-family:inherit;">
    <button type="submit" style="width:100%; padding:10px; border:none; border-radius:8px; background:#16a34a; color:#fff; font-weight:700; cursor:pointer; font-family:inherit;">
        Valider le paiement
    </button>
</div>
