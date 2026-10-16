{{--
    L'avis d'activation en attente (retour du 16/10/2026).

    Dès qu'une entreprise a saisi la référence de son paiement, elle entre dans
    la plateforme — rien n'est bloqué. Ce bandeau la rassure : son compte
    s'active dès que DC-Knowing confirme le règlement, en général sous quelques
    minutes. Il disparaît de lui-même une fois le paiement confirmé.
--}}
@php
    $__entreprise = auth()->user()?->entreprise;
    $__paiementEnAttente = $__entreprise
        ? \App\Modules\Admin\Modeles\PaiementAbonnement::where('entreprise_id', $__entreprise->id)
            ->whereIn('statut', \App\Modules\Admin\Modeles\PaiementAbonnement::A_TRAITER)
            ->latest('id')->first()
        : null;
@endphp
@if($__paiementEnAttente)
<div style="background:#EFF6FF; border:1px solid #BFDBFE; color:#1E40AF; border-radius:10px; padding:11px 16px; margin:0 0 16px; font-size:13px; line-height:1.6; display:flex; gap:10px; align-items:flex-start;">
    <i class="fas fa-hourglass-half" style="margin-top:2px;"></i>
    <div>
        <strong>Votre compte est en cours d'activation.</strong>
        Vous pouvez déjà travailler. Votre paiement
        (réf. <strong>{{ $__paiementEnAttente->reference_paiement }}</strong>) est en cours de vérification —
        l'activation complète intervient dès que DC-Knowing le confirme, en général <strong>sous 5 minutes</strong>.
        Merci de votre patience.
    </div>
</div>
@endif
