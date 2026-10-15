{{-- L'offre d'abonnement : à choisir, à l'essai, expirée ou à régler (15/10/2026). --}}
@php
    use App\Modules\Admin\Regles\Abonnements;
    $__e = auth()->user()?->entreprise;
    $__bandeau = null;
    if ($__e && auth()->user()->role === 'admin') {
        if (Abonnements::aChoisir($__e)) {
            $__bandeau = ['#EFF6FF', '#BFDBFE', '#1E40AF', 'fa-gift', 'Choisissez votre offre', 'Solo, Business, Premium, sur mesure — ou le pass cadeau : ' . Abonnements::DUREE_PASS_CADEAU_JOURS . ' jours gratuits, tout activé.', 'Voir les offres'];
        } elseif (Abonnements::passExpire($__e)) {
            $__bandeau = ['#FEF2F2', '#FECACA', '#991B1B', 'fa-hourglass-end', 'Votre pass cadeau est terminé', 'Il a pris fin le ' . $__e->abonnement_expire_le->format('d/m/Y') . '. Choisissez une offre pour continuer sereinement.', 'Choisir une offre'];
        } elseif ($__e->plan_abonnement === Abonnements::PASS_CADEAU && $__e->abonnement_expire_le) {
            $__jours = max(0, (int) ceil(now()->diffInHours($__e->abonnement_expire_le, false) / 24));
            $__bandeau = ['#FFFBEB', '#FCD34D', '#92400E', 'fa-gift', 'Pass cadeau : ' . $__jours . ' jour' . ($__jours > 1 ? 's' : '') . ' restant' . ($__jours > 1 ? 's' : ''), 'Jusqu\'au ' . $__e->abonnement_expire_le->format('d/m/Y') . ' — 5 points de vente, 5 utilisateurs, tout activé.', 'Choisir mon offre'];
        } elseif ($__e->abonnement_statut === Abonnements::STATUT_A_REGLER) {
            $__bandeau = ['#F8FAFC', '#E2E8F0', '#334155', 'fa-receipt', 'Offre ' . Abonnements::libelle($__e->plan_abonnement) . ' : règlement en attente', 'DC-Knowing vous contacte pour le règlement de votre abonnement.', 'Voir les offres'];
        }
    }
@endphp
@if($__bandeau)
<div id="bandeau-abonnement" style="display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; background:{{ $__bandeau[0] }}; border:1px solid {{ $__bandeau[1] }}; color:{{ $__bandeau[2] }}; border-radius:12px; padding:14px 18px; margin-bottom:16px;">
    <div style="display:flex; align-items:center; gap:12px;">
        <i class="fas {{ $__bandeau[3] }}" style="font-size:20px;"></i>
        <div>
            <strong style="font-size:14px;">{{ $__bandeau[4] }}</strong>
            <div style="font-size:12.5px; opacity:.9;">{{ $__bandeau[5] }}</div>
        </div>
    </div>
    <a href="{{ route('admin.abonnement.choisir') }}" class="btn btn-primary btn-sm">{{ $__bandeau[6] }}</a>
</div>
@endif
