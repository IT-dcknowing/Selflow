{{--
    L'abonnement en attente — décision du propriétaire du 22/10/2026.

    Une offre choisie et payée par référence n'ouvre pas l'espace : il reste
    visible et inerte tant que DC-Knowing n'a pas confirmé le règlement. La
    fenêtre ne se ferme pas ; elle demande où en est le paiement toutes les
    dix secondes et recharge la page dès que le statut change — la
    confirmation s'annonce alors d'elle-même. Un paiement en échec garde le
    blocage, avec le motif et la voie pour saisir une nouvelle référence.
--}}
@use('App\Modules\Admin\Regles\Abonnements', 'Abonnements')
@use('App\Modules\Admin\Regles\PaiementParReference', 'Reference')
@php $echec = $paiement->statut === 'echec'; @endphp
<div id="voile-abonnement-a-terme" class="voile-abonnement-en-attente" role="alertdialog" aria-modal="true" aria-labelledby="titre-abonnement-en-attente"
     data-statut="{{ $echec ? 'echec' : 'en_attente' }}">
    <div class="boite">
        <div style="width:58px; height:58px; border-radius:14px; background:{{ $echec ? '#FEE2E2' : '#DBEAFE' }}; color:{{ $echec ? '#DC2626' : '#1D4ED8' }}; display:inline-flex; align-items:center; justify-content:center; font-size:24px; margin-bottom:16px;">
            <i class="fas {{ $echec ? 'fa-circle-exclamation' : 'fa-hourglass-half fa-spin' }}" @unless($echec) style="animation-duration:3s;" @endunless></i>
        </div>
        <h2 id="titre-abonnement-en-attente" style="font-size:20px; font-weight:800; color:#1E293B; margin-bottom:8px;">
            {{ $echec ? 'Paiement non confirmé' : 'En attente de confirmation de l\'abonnement' }}
        </h2>
        <p style="color:#64748B; font-size:14px; line-height:1.6; margin-bottom:14px;">
            @if($echec)
                DC-Knowing n'a pas pu confirmer votre paiement. Votre espace reste bloqué
                jusqu'à la confirmation d'un nouveau paiement.
            @else
                Votre paiement est en cours de vérification par DC-Knowing, en général
                <strong>sous quelques minutes</strong>. Votre espace s'ouvre dès la confirmation :
                cette fenêtre s'actualise d'elle-même.
            @endif
        </p>
        <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:10px 14px; font-size:13px; text-align:left; line-height:1.7; margin-bottom:18px;">
            <div>Offre : <strong>{{ Abonnements::libelle($paiement->offre) }}</strong> — {{ number_format((float) $paiement->montant, 0, ',', ' ') }} F</div>
            <div>Paiement : {{ Reference::OPERATEURS[$paiement->operateur_paiement] ?? $paiement->operateur_paiement }},
                réf. <strong style="font-family:ui-monospace,Menlo,monospace;">{{ $paiement->reference_paiement }}</strong></div>
            <div>Déclaré le {{ $paiement->created_at?->format('d/m/Y à H:i') }}</div>
            @if($echec && $paiement->motif_echec)
                <div style="color:#B91C1C; margin-top:4px;"><strong>Motif :</strong> {{ $paiement->motif_echec }}</div>
            @endif
        </div>
        @if(auth()->user()->role === 'admin')
            <a href="{{ route('admin.entreprise.parametres') }}#facturation" id="btn-facturation-en-attente"
               style="display:flex; justify-content:center; align-items:center; gap:8px; padding:13px; background:#002B5C; color:#fff; border-radius:10px; font-weight:700; text-decoration:none;">
                {{ $echec ? 'Saisir une nouvelle référence' : 'Voir la facturation' }} <i class="fas fa-arrow-right"></i>
            </a>
        @else
            <p style="font-size:13px; color:#991B1B; font-weight:600;">Prévenez l'administrateur de votre entreprise.</p>
        @endif
        <form method="POST" action="{{ route('deconnexion') }}" style="margin-top:10px;">@csrf
            <button type="submit" style="width:100%; padding:12px; border:1px solid #E2E8F0; background:#fff; border-radius:10px; font-weight:600; cursor:pointer;">Se déconnecter</button>
        </form>
    </div>
</div>
@unless($echec)
<script>
    // Dès que DC-Knowing a tranché, la page se recharge : « Abonnement
    // confirmé » s'affiche, ou le motif de l'échec.
    (function () {
        const url = @js(route('admin.abonnement.etat'));
        setInterval(function () {
            fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) { if (d && d.statut !== 'en_attente') { window.location.reload(); } })
                .catch(function () {});
        }, 10000);
    })();
</script>
@endunless
