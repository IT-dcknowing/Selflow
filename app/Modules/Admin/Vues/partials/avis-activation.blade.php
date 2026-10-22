{{--
    Le renouvellement en cours de vérification.

    Depuis le 22/10/2026, un paiement en attente bloque l'espace tant qu'aucune
    période payée ne court (fenêtre « En attente de confirmation de
    l'abonnement »). Ce bandeau ne reste que pour l'entreprise qui renouvelle
    avant son échéance : elle continue de travailler, et il lui dit que le
    paiement est en vérification. Il disparaît une fois le paiement confirmé.
--}}
@php
    $__entreprise = auth()->user()?->entreprise;
    $__paiementEnAttente = $__entreprise
        ? \App\Modules\Admin\Modeles\PaiementAbonnement::where('entreprise_id', $__entreprise->id)
            ->where('bloquant', false)
            ->whereIn('statut', \App\Modules\Admin\Modeles\PaiementAbonnement::A_TRAITER)
            ->latest('id')->first()
        : null;
@endphp
@if($__paiementEnAttente)
<div style="background:#EFF6FF; border:1px solid #BFDBFE; color:#1E40AF; border-radius:10px; padding:11px 16px; margin:0 0 16px; font-size:13px; line-height:1.6; display:flex; gap:10px; align-items:flex-start;">
    <i class="fas fa-hourglass-half" style="margin-top:2px;"></i>
    <div>
        <strong>Renouvellement en cours de vérification.</strong>
        Votre paiement (réf. <strong>{{ $__paiementEnAttente->reference_paiement }}</strong>) est en cours de
        vérification par DC-Knowing.
        @if($__entreprise->abonnement_expire_le) Votre abonnement actuel court jusqu'au {{ $__entreprise->abonnement_expire_le->format('d/m/Y') }} : @endif
        vous continuez de travailler.
    </div>
</div>
@endif
