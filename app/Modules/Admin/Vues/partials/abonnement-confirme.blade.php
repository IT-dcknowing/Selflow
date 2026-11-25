{{--
    « Abonnement confirmé » — 22/10/2026. Il s'affiche une fois, au premier
    écran qui suit la confirmation du paiement par DC-Knowing, jusqu'à
    « Commencer ».
--}}
@use('App\Modules\Admin\Regles\Abonnements', 'Abonnements')
@php $echeance = auth()->user()->entreprise?->abonnement_expire_le; @endphp
<div id="voile-abonnement-a-terme" class="voile-abonnement-confirme" role="alertdialog" aria-modal="true" aria-labelledby="titre-abonnement-confirme">
    <div class="boite">
        <div style="width:58px; height:58px; border-radius:14px; background:#DCFCE7; color:#16A34A; display:inline-flex; align-items:center; justify-content:center; font-size:26px; margin-bottom:16px;">
            <i class="fas fa-circle-check"></i>
        </div>
        <h2 id="titre-abonnement-confirme" style="font-size:20px; font-weight:800; color:#1E293B; margin-bottom:8px;">Abonnement confirmé</h2>
        <p style="color:#64748B; font-size:14px; line-height:1.6; margin-bottom:22px;">
            Votre paiement (réf. <strong>{{ $paiement->reference_paiement }}</strong>) est confirmé.
            L'offre <strong>{{ Abonnements::libelle($paiement->offre) }}</strong> est active
            @if($echeance) jusqu'au <strong>{{ $echeance->format('d/m/Y') }}</strong>@endif.
            Votre espace est ouvert : vous pouvez travailler.
        </p>
        {{-- 25/11/2026 : la fenêtre se ferme aussitôt, la confirmation part en
             arrière-plan. Le formulaire rechargeait toute la page (lent). --}}
        <form method="POST" action="{{ route('admin.abonnement.confirmation_vue') }}" style="margin:0;"
              onsubmit="if (!window.fetch) return true;
                        var v = document.querySelector('.voile-abonnement-confirme'); if (v) v.remove();
                        document.body.classList.remove('abonnement-a-terme');
                        fetch(this.action, { method: 'POST', body: new FormData(this), keepalive: true, headers: { 'Accept': 'application/json' } });
                        return false;">@csrf
            <button type="submit" id="btn-abonnement-confirme"
                    style="width:100%; display:flex; justify-content:center; align-items:center; gap:8px; padding:13px; background:#16A34A; color:#fff; border:none; border-radius:10px; font-weight:700; cursor:pointer;">
                Commencer <i class="fas fa-arrow-right"></i>
            </button>
        </form>
    </div>
</div>
