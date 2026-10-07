@extends('admin::gabarits.application')

@section('titre', 'Vente enregistrée')
@section('topbar_titre', 'Vente enregistrée')

@section('styles')
<style>
    .fin-vente { max-width: 620px; margin: 8px auto 0; }
    .fin-vente .entete { text-align: center; padding: 26px 24px 18px; }
    .fin-vente .entete .coche {
        width: 56px; height: 56px; border-radius: 50%;
        background: rgba(16,185,129,.12); color: var(--success);
        display: inline-flex; align-items: center; justify-content: center;
        font-size: 26px; margin-bottom: 12px;
    }
    .fin-vente .entete h1 { font-size: 20px; font-weight: 800; margin: 0 0 4px; }
    .fin-vente .entete p { color: var(--text-2); font-size: 13px; margin: 0; }

    .fin-vente .montants { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
    .fin-vente .montants > div { padding: 14px 10px; text-align: center; }
    .fin-vente .montants > div + div { border-left: 1px solid var(--border); }
    .fin-vente .montants .libelle { font-size: 11px; color: var(--text-3); text-transform: uppercase; letter-spacing: .4px; font-weight: 700; }
    .fin-vente .montants .valeur { font-size: 19px; font-weight: 800; margin-top: 4px; }
    .fin-vente .montants .a-rendre .valeur { color: #15803d; font-size: 24px; }
    .fin-vente .montants .reste-du .valeur { color: #b45309; }

    .fin-vente .etat-dgi { margin: 18px 24px 0; padding: 12px 14px; border-radius: 10px; font-size: 13px; line-height: 1.5; display: none; }
    .fin-vente .etat-dgi.visible { display: flex; gap: 10px; align-items: flex-start; }
    .fin-vente .etat-dgi i { margin-top: 2px; }
    .fin-vente .etat-dgi[data-etat="certifiee"]   { background: #f0fdf4; border: 1px solid #86efac; color: #166534; }
    .fin-vente .etat-dgi[data-etat="en_cours"]    { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; }
    .fin-vente .etat-dgi[data-etat="en_attente"]  { background: #f8fafc; border: 1px solid #cbd5e1; color: #334155; }
    .fin-vente .etat-dgi[data-etat="rejetee"]     { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }
    .fin-vente .etat-dgi[data-etat="injoignable"] { background: #fef2f2; border: 1px solid #fca5a5; color: #991b1b; }

    .fin-vente .actions { padding: 20px 24px 24px; display: flex; flex-direction: column; gap: 10px; }
    .fin-vente .actions .btn { width: 100%; justify-content: center; padding: 12px; font-size: 14px; }
    .fin-vente .actions .secondaires { display: flex; gap: 10px; flex-wrap: wrap; }
    .fin-vente .actions .secondaires > * { flex: 1; }
    .fin-vente .actions .secondaires .btn { font-size: 12.5px; padding: 9px; }
    .fin-vente .action-etat { display: none; }
    .fin-vente .action-etat.visible { display: block; }
    .fin-vente kbd {
        font-family: inherit; font-size: 11px; font-weight: 700;
        padding: 1px 6px; border-radius: 4px; margin-left: 6px;
        background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.35);
    }
    .fin-vente .discret { text-align: center; font-size: 12px; }
    .fin-vente .discret a { color: var(--text-2); }

    @media (max-width: 560px) {
        .fin-vente .montants { grid-template-columns: 1fr; }
        .fin-vente .montants > div + div { border-left: none; border-top: 1px solid var(--border); }
    }
</style>
@endsection

@section('contenu')
@php
    $net = $vente->netAPayer();
    $rendu = $vente->monnaieRendue();
    $encaisse = min((float) ($vente->montant_recu ?? 0), $net);
    $resteDu = max(0, $net - $encaisse);
    $fcfa = fn ($n) => number_format(round($n), 0, ',', ' ') . ' F';

    $routeTicket   = route($espace . '.ventes.ticket', $vente);
    $routeFacture  = route($espace . '.ventes.imprimer', $vente);
    $routeNouvelle = route($espace . '.ventes.nouvelle');
    $routeListe    = route($espace . '.ventes.factures');
    $routeEtat     = route($espace . '.ventes.etat_dgi', $vente);
    $routeNormaliser = route($espace . '.ventes.normaliser', $vente);
@endphp

<div class="fin-vente card">
    <div class="entete">
        <div class="coche"><i class="fas fa-check"></i></div>
        <h1>Vente enregistrée</h1>
        <p>
            {{ $vente->numero_facture }} · {{ $vente->client?->nom ?? 'Client de passage' }} · {{ $vente->mode_paiement }}
        </p>
    </div>

    {{-- Le net, ce qui a été tendu, et ce qu'on rend : les trois chiffres
         que le caissier annonce au client. Aucun n'est recalculé ici — ils
         viennent de la pièce, comme sur la facture et le reçu. --}}
    <div class="montants">
        <div>
            <div class="libelle">Net à payer</div>
            <div class="valeur">{{ $fcfa($net) }}</div>
        </div>
        <div>
            <div class="libelle">Reçu</div>
            <div class="valeur">{{ $vente->montant_recu !== null ? $fcfa($vente->montant_recu) : '—' }}</div>
        </div>
        @if($resteDu > 0.5)
            <div class="reste-du">
                <div class="libelle">{{ $vente->statut === 'Crédit' ? 'À crédit' : 'Reste dû' }}</div>
                <div class="valeur">{{ $fcfa($resteDu) }}</div>
            </div>
        @else
            <div class="a-rendre">
                <div class="libelle">À rendre</div>
                <div class="valeur" id="monnaieARendre">{{ $fcfa($rendu ?? 0) }}</div>
            </div>
        @endif
    </div>

    {{-- L'état DGI. Les cinq blocs sont rendus, un seul est visible : l'écran
         passe de l'un à l'autre sans se recharger quand la certification
         aboutit. --}}
    <div class="etat-dgi {{ $etatDgi === 'certifiee' ? 'visible' : '' }}" data-etat="certifiee">
        <i class="fas fa-circle-check"></i>
        <div><strong>Certifiée par la DGI.</strong> Le reçu normalisé porte le code QR et le sticker.</div>
    </div>
    <div class="etat-dgi {{ $etatDgi === 'en_cours' ? 'visible' : '' }}" data-etat="en_cours">
        <i class="fas fa-spinner fa-spin"></i>
        <div id="texteEnCours"><strong>Certification en cours.</strong> La pièce part à la DGI dans la minute ; le reçu normalisé sera prêt ici, sans recharger la page.</div>
    </div>
    <div class="etat-dgi {{ $etatDgi === 'en_attente' ? 'visible' : '' }}" data-etat="en_attente">
        <i class="fas fa-hourglass-half"></i>
        <div><strong>Pas encore normalisée.</strong> La normalisation automatique est décochée dans vos paramètres : rien ne part à la DGI tant que la pièce n'a pas été normalisée.</div>
    </div>
    <div class="etat-dgi {{ $etatDgi === 'rejetee' ? 'visible' : '' }}" data-etat="rejetee">
        <i class="fas fa-triangle-exclamation"></i>
        <div><strong>Refusée par la DGI.</strong> Plus rien ne part tant que la cause n'est pas levée. La vente, elle, est bien enregistrée.</div>
    </div>
    <div class="etat-dgi {{ $etatDgi === 'injoignable' ? 'visible' : '' }}" data-etat="injoignable">
        <i class="fas fa-plug-circle-xmark"></i>
        <div><strong>Plateforme FNE injoignable.</strong> La pièce n'est pas partie et la DGI n'a rien refusé. Réessayez dans un instant.</div>
    </div>

    <div class="actions">
        @if($voitLesPieces)
            {{-- Le reçu normalisé, dès qu'il existe. Le ticket s'imprime à
                 l'ouverture : un clic, et la boîte d'impression est là. --}}
            <div class="action-etat {{ $etatDgi === 'certifiee' ? 'visible' : '' }}" data-pour="certifiee">
                <a href="{{ $routeTicket }}" target="_blank" class="btn btn-success" id="btnImprimerRecu">
                    <i class="fas fa-print"></i> Imprimer le reçu normalisé
                </a>
            </div>
            {{-- Rien n'est parti, et rien ne partira seul : la normaliser
                 d'ici évite d'aller la chercher dans la liste. Elle revient
                 sur cet écran, certifiée ou avec la raison du refus. --}}
            <div class="action-etat {{ in_array($etatDgi, ['en_attente', 'injoignable'], true) ? 'visible' : '' }}" data-pour="en_attente injoignable">
                <form method="POST" action="{{ $routeNormaliser }}" style="margin:0;">
                    @csrf
                    <button type="submit" class="btn btn-success" id="btnNormaliser">
                        <i class="fas fa-stamp"></i> {{ $etatDgi === 'injoignable' ? 'Réessayer la normalisation' : 'Normaliser maintenant' }}
                    </button>
                </form>
            </div>
        @endif

        <a href="{{ $routeNouvelle }}" class="btn btn-primary" id="btnNouvelleVente">
            <i class="fas fa-cash-register"></i> Nouvelle vente <kbd>Entrée</kbd>
        </a>

        @if($voitLesPieces)
            <div class="secondaires">
                {{-- Le reçu tel que Selflow l'établit, avant certification —
                     ce que la colonne « Originale » de la liste propose déjà.
                     Sans lui, une entreprise dont la FNE n'est pas encore
                     configurée ne pourrait rien remettre au client. --}}
                <a href="{{ $routeTicket }}" target="_blank" class="btn btn-outline action-etat {{ $etatDgi !== 'certifiee' ? 'visible' : '' }}" data-pour="en_cours en_attente rejetee injoignable" id="btnImprimerSansAttendre">
                    <i class="fas fa-receipt"></i> Reçu non certifié
                </a>
                <a href="{{ $routeFacture }}" class="btn btn-outline">
                    <i class="fas fa-file-invoice"></i> Voir la facture
                </a>
                @if($espace === 'admin')
                    <a href="{{ route('admin.fne.rejets') }}" class="btn btn-outline action-etat {{ $etatDgi === 'rejetee' ? 'visible' : '' }}" data-pour="rejetee">
                        <i class="fas fa-triangle-exclamation"></i> Voir le refus
                    </a>
                @endif
            </div>
            <div class="discret"><a href="{{ $routeListe }}">Toutes les ventes</a></div>
        @endif
    </div>
</div>
@endsection

@section('scripts')
<script>
(function () {
    // Le panier de la vente qui vient d'être enregistrée. Seule la page de la
    // facture le vidait ; elle ne s'ouvre plus après la validation, et la
    // vente suivante repartait avec les articles de celle-ci.
    try { localStorage.removeItem('selflow_vente_panier'); } catch (e) { /* stockage bloqué : rien à vider */ }

    var btnRecu     = document.getElementById('btnImprimerRecu');
    var btnNouvelle = document.getElementById('btnNouvelleVente');

    function visible(el) { return el && el.offsetParent !== null; }

    // Entrée imprime le reçu normalisé quand il est prêt, puis Entrée ouvre la
    // vente suivante. Le focus suit : un caissier n'a pas à prendre la souris.
    function placerLeFocus() {
        (visible(btnRecu) ? btnRecu : btnNouvelle).focus();
    }
    if (btnRecu) {
        btnRecu.addEventListener('click', function () { setTimeout(function () { btnNouvelle.focus(); }, 0); });
    }

    function afficherEtat(etat) {
        document.querySelectorAll('.etat-dgi').forEach(function (bloc) {
            bloc.classList.toggle('visible', bloc.dataset.etat === etat);
        });
        document.querySelectorAll('.action-etat').forEach(function (el) {
            el.classList.toggle('visible', (el.dataset.pour || '').split(' ').indexOf(etat) !== -1);
        });
    }

    placerLeFocus();

    // On n'interroge que ce qui peut encore changer seul : une pièce déposée
    // dans la file. La file est servie chaque minute par le planificateur ;
    // trois minutes sans réponse, et l'on cesse de promettre.
    var etat = @json($etatDgi);
    if (etat !== 'en_cours') { return; }

    var essais = 0, maxEssais = 45, intervalle = 4000;
    function interroger() {
        essais++;
        fetch(@json($routeEtat), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (data && data.etat && data.etat !== 'en_cours') {
                    afficherEtat(data.etat);
                    placerLeFocus();
                    return;
                }
                if (essais < maxEssais) { setTimeout(interroger, intervalle); } else { abandonner(); }
            })
            .catch(function () {
                if (essais < maxEssais) { setTimeout(interroger, intervalle); } else { abandonner(); }
            });
    }
    function abandonner() {
        var texte = document.getElementById('texteEnCours');
        if (texte) {
            texte.innerHTML = '<strong>La certification tarde.</strong> La pièce reste dans la file : '
                + 'elle partira au prochain passage, et vous la retrouverez dans la liste des ventes.';
        }
        var roue = document.querySelector('.etat-dgi[data-etat="en_cours"] i');
        if (roue) { roue.className = 'fas fa-clock'; }
    }
    setTimeout(interroger, intervalle);
})();
</script>
@endsection
